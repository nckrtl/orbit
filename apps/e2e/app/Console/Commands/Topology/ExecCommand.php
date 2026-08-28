<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\TopologyAcquirer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity JSON argv validation remains at the command boundary. */
final class ExecCommand extends Command
{
    #[\Override]
    protected $signature = 'topology:exec {issue} {role} {--argv-file=} {--json}';
    #[\Override]
    protected $description = 'Execute an exact argv vector on one topology role';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            [$argv, $stdin] = $this->commandInput();
            $result = $acquirer->execute(
                (string) $this->argument('issue'),
                (string) $this->argument('role'),
                $argv,
                $stdin,
            );
            $identity = bin2hex(random_bytes(16));
            $payload = [
                'state' => 'executed',
                'operation_id' => $identity,
                'evidence_id' => $identity,
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $result->stdout);

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $identity = bin2hex(random_bytes(16));
            $this->option('json')
                ? $this->line(json_encode([
                    'state' => 'failed',
                    'operation_id' => $identity,
                    'evidence_id' => $identity,
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR))
                : $this->error($exception->getMessage());

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
