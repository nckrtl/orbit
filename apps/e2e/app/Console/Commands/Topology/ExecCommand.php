<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\ProofReviewService;
use App\E2E\State\SecretRedactor;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptPurpose;
use InvalidArgumentException;
use Throwable;

final class ExecCommand extends E2ECommand
{
    use ReadsGuestArgv;

    #[\Override]
    protected $signature =
        'topology:exec {issue} {role} '
        .self::WORKTREE_OPTION
        .' {--argv=} {--argv-file=} {--proof : Run against a retained proof topology}'
        .' {--review-action= : Record this action against captured successful proof}'
        .' {--required : Mark the recorded review action as required}'
        .' {--timeout=60 : Seconds the guest command may run, from 1 to 3600}'
        .' {--json}';

    #[\Override]
    protected $description = 'Execute exact argv as orbit on discovery, failed proof, or captured proof review';

    public function handle(
        TopologyAcquirer $acquirer,
        ProofReviewService $review,
        SecretRedactor $redactor,
    ): int {
        try {
            [$argv, $stdin] = $this->commandInput();
            $timeout = $this->timeoutOption();
            $request = $this->request();
            $role = (string) $this->argument('role');
            $purpose = $this->option('proof') ? AttemptPurpose::Proof : AttemptPurpose::Discovery;
            $action = $this->stringOption('review-action');
            if ($this->option('required') && $action === null) {
                throw new InvalidArgumentException('--required needs --review-action.');
            }
            if ($action !== null && ! $this->option('proof')) {
                throw new InvalidArgumentException('--review-action needs --proof.');
            }
            if ($action !== null && $timeout !== 60) {
                throw new InvalidArgumentException('--timeout does not apply to a recorded review action.');
            }
            $result = $action === null
                ? $acquirer->execute($request, $role, $argv, $stdin, $purpose, $timeout)
                : $review->execute($request, $action, $role, $argv, (bool) $this->option('required'), $stdin);
            $redactedArgv = json_encode($redactor->redactArgv($argv), JSON_THROW_ON_ERROR);
            $reviewIdentity = $action === null ? '' : " action={$action}";
            $this->log($request, "role={$role}{$reviewIdentity} exit={$result->exitCode} argv={$redactedArgv}");
            $payload = [
                'state' => 'executed',
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ];
            $this->line(
                $this->option('json')
                    ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : $result->stdout,
            );

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function timeoutOption(): int
    {
        $value = $this->option('timeout');
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,3}\z/', $value) !== 1 || (int) $value > 3600) {
            throw new InvalidArgumentException('--timeout must be a whole number of seconds from 1 to 3600.');
        }

        return (int) $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
