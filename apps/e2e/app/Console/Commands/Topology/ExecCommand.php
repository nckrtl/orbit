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
    protected $signature = 'topology:exec {issue} {attempt} {role} {--argv-file=} {--json}';
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

    /** @return array{list<string>, ?string} */
    private function commandInput(): array
    {
        $path = $this->option('argv-file');
        if (! is_string($path) || $path === '' || ! is_file($path) || is_link($path)) {
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
        $argv = [];
        foreach ($value['argv'] as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Every argv item must be a string.');
            }
            $argv[] = $argument;
        }

        return [$argv, $value['stdin']];
    }
}
