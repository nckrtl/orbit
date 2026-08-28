<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use Illuminate\Console\Command;
use Throwable;

final class FingerprintCommand extends Command
{
    #[\Override]
    protected $signature = 'standby:fingerprint {--main-sha=HEAD} {--json}';
    #[\Override]
    protected $description = 'Compute the desired prepared standby fingerprint';

    public function handle(PreparedStateFingerprint $fingerprints, StandbyManifestStore $standby): int
    {
        try {
            $commit = $this->option('main-sha');
            if (! is_string($commit)) {
                throw new \InvalidArgumentException('The main SHA is invalid.');
            }
            $promoted = $standby->promoted();
            $fingerprint = $fingerprints->forCommit(
                $commit,
                $promoted?->laravel,
            );
            $payload = ['state' => 'fingerprint', 'fingerprint' => $fingerprint->value];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $fingerprint->value);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
