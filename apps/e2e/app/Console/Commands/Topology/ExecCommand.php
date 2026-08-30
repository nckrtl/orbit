<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use InvalidArgumentException;
use JsonException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity JSON argv validation remains at the command boundary. */
final class ExecCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:exec {issue} {attempt} {role} {--argv=} {--argv-file=} {--json}';
    #[\Override]
    protected $description = 'Execute an exact argv vector, as the orbit runtime user, on one role of an exact topology attempt';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            [$argv, $stdin] = $this->commandInput();
            $result = $acquirer->execute(
                (string) $this->argument('issue'),
                new AttemptId((string) $this->argument('attempt')),
                (string) $this->argument('role'),
                $argv,
                $stdin,
            );
            $identity = $operation->value;
            $payload = [
                'state' => 'executed',
                'operation_id' => $identity,
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $result->stdout);

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
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
     * @param list<mixed> $value
     * @return list<string>
     */
    private function argvList(array $value): array
    {
        $argv = [];
        /** @mago-expect analysis:mixed-assignment Each argument is validated before it joins the vector. */
        foreach ($value as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Every argv item must be a string.');
            }
            $argv[] = $argument;
        }

        return $argv;
    }
}
