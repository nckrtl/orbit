<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\ProofReviewService;
use App\E2E\State\SecretRedactor;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptPurpose;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ExecCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:exec {issue} {role} '
        .self::WORKTREE_OPTION
        .' {--argv=} {--argv-file=} {--proof : Run against a retained proof topology}'
        .' {--review-action= : Record this action against captured successful proof}'
        .' {--required : Mark the recorded review action as required}'
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
            $result = $action === null
                ? $acquirer->execute($request, $role, $argv, $stdin, $purpose)
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The argv vector comes from exactly one of `--argv` (an inline JSON array of
     * strings, no stdin) or `--argv-file` (a file holding `{"argv":[...],"stdin":null}`).
     *
     * @return array{list<string>, ?string}
     */
    private function commandInput(): array
    {
        $inline = $this->option('argv');
        $path = $this->option('argv-file');
        $hasInline = is_string($inline) && $inline !== '';
        $hasFile = is_string($path) && $path !== '';
        if ($hasInline && $hasFile) {
            throw new InvalidArgumentException('Use either --argv or --argv-file, not both.');
        }
        if (! $hasInline && ! $hasFile) {
            throw new InvalidArgumentException(
                'An exact argv JSON array (--argv) or argv JSON file (--argv-file) is required.',
            );
        }
        if ($hasInline) {
            try {
                $value = json_decode($inline, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    'The --argv value must be a JSON array of strings, for example \'["orbit","doctor","--json"]\'.',
                    previous: $exception,
                );
            }
            if (! is_array($value) || ! array_is_list($value) || $value === []) {
                throw new InvalidArgumentException(
                    'The --argv value must be a non-empty JSON array of strings, for example \'["orbit","doctor","--json"]\'.',
                );
            }

            return [$this->argvList($value), null];
        }
        if (! is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('An exact argv JSON file is required.');
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The argv JSON file is malformed.', previous: $exception);
        }
        if (
            ! is_array($value)
            || array_keys($value) !== ['argv', 'stdin']
            || ! is_array($value['argv'])
            || ! array_is_list($value['argv'])
            || $value['stdin'] !== null
            && ! is_string($value['stdin'])
        ) {
            throw new InvalidArgumentException('The argv JSON schema is invalid.');
        }

        return [$this->argvList($value['argv']), $value['stdin']];
    }

    /**
     * @param  list<mixed>  $value
     * @return list<string>
     */
    private function argvList(array $value): array
    {
        $argv = [];
        foreach ($value as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Every argv item must be a string.');
            }
            $argv[] = $argument;
        }

        return $argv;
    }
}
