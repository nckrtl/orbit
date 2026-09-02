<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\Console\Commands\E2ECommand;
use App\E2E\LegacyStandbyRecovery;
use App\E2E\StandbyRebuilder;
use App\E2E\StandbyRefresher;
use App\E2E\State\SecretRedactor;
use Throwable;

final class RecoverLegacyCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'standby:recover-legacy
        {--main-sha=}
        {--json}';
    #[\Override]
    protected $description = 'Recover exact legacy resources for this checkout\'s named standby';

    public function handle(
        StandbyRefresher $refresher,
        LegacyStandbyRecovery $recovery,
        StandbyRebuilder $rebuilder,
    ): int {
        $sha = null;
        try {
            $sha = $this->option('main-sha');
            if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/D', $sha) !== 1) {
                throw new \InvalidArgumentException('The exact main SHA is required.');
            }

            $result = $refresher->recoverLegacy($sha, $recovery, $rebuilder);
            $record = $recovery->retained();
            $error = $result->error === null ? null : app(SecretRedactor::class)->redact($result->error);
            $nextAction = $result->successful()
                ? 'bin/e2e-standby status'
                : "bin/e2e-standby recover-legacy --main-sha={$sha}";
            $payload = [
                ...$result->toArray(),
                'error' => $error,
                'recovery_evidence' => $record === null ? null : '.e2e/standby/recovery.json',
                'recovery_phase' => $record['phase'] ?? null,
                'next_action' => $nextAction,
            ];
            $text = $result->successful()
                ? $result->state.' '.($result->generationId ?? '')." Next action: {$nextAction}"
                : "failed: {$error} Next action: {$nextAction}";
            $this->outputJson($payload, $text);

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $message = app(SecretRedactor::class)->redact($exception->getMessage());
            $nextAction = is_string($sha) && preg_match('/\A[a-f0-9]{40}\z/D', $sha) === 1
                ? "bin/e2e-standby recover-legacy --main-sha={$sha}"
                : 'bin/e2e-standby recover-legacy --main-sha=<sha>';
            $record = $recovery->retained();
            $this->outputJson([
                'state' => 'failed',
                'error' => $message,
                'recovery_evidence' => $record === null ? null : '.e2e/standby/recovery.json',
                'recovery_phase' => $record['phase'] ?? null,
                'next_action' => $nextAction,
            ], "failed: {$message} Next action: {$nextAction}");

            return self::FAILURE;
        }
    }
}
