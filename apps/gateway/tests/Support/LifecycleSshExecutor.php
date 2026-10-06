<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Projects\TiaBaselineSetup;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Closure;

final class LifecycleSshExecutor implements SshExecutor
{
    /** @var list<array{checkout: string, command: string, timeout: int|float}> */
    public array $inputs = [];

    /** @var list<string> */
    public array $shells = [];

    /** @param (Closure(array{checkout: string, command: string, timeout: int|float}): int)|null $result */
    public function __construct(public ?Closure $result = null, public bool $local = false) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $payload = $this->decodePayload(stream_get_contents($command->protectedInput->stream()));
        $this->inputs[] = $payload;
        $this->shells[] = $command->shellCommand();

        if ($this->local) {
            // The runner's program starts the Node's `/usr/bin/bash`; a host without it uses the toolchain's Bash.
            $arguments = is_executable('/usr/bin/bash') ? $command->arguments : array_map(
                static fn (string $argument): string => str_replace("'/usr/bin/bash'", var_export(TestToolchain::bash(), true), $argument),
                $command->arguments,
            );

            return (new NativeProcessRunner)->run(new ProcessInvocation(
                arguments: $arguments,
                protectedInput: $command->protectedInput,
                timeout: $command->timeout ?? 10.0,
            ));
        }

        return new CommandResult($this->result === null ? 0 : ($this->result)($payload), '', '', 1, false);
    }

    /** @return array{checkout: string, command: string, timeout: int|float} */
    private function decodePayload(string $input): array
    {
        $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
        $timeout = is_array($payload) ? ($payload['timeout'] ?? null) : null;

        if (
            ! is_array($payload)
            || ! is_string($payload['checkout'] ?? null)
            || ! is_string($payload['command'] ?? null)
            || (! is_float($timeout) && ! is_int($timeout))
        ) {
            throw new \UnexpectedValueException('The lifecycle command payload is invalid.');
        }

        return [
            'checkout' => $payload['checkout'],
            'command' => $payload['command'],
            'timeout' => $timeout,
        ];
    }

    public function runner(?CommandDeadline $deadline = null): ProjectLifecycleRunner
    {
        return new ProjectLifecycleRunner(
            new ProjectLifecycleStepStore,
            new DevelopmentSshExecutor($this, new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/lifecycle-test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 test';
                }
            }, new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/lifecycle-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            }),
            $deadline ?? new CommandDeadline,
            app(TiaBaselineSetup::class),
        );
    }
}
