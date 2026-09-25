<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Infrastructure\AppDev\AppDevSshExecutor;
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
    /** @var list<array{checkout: string, command: string, timeout: float}> */
    public array $inputs = [];

    /** @var list<string> */
    public array $shells = [];

    /** @param (Closure(array{checkout: string, command: string, timeout: float}): int)|null $result */
    public function __construct(public ?Closure $result = null, public bool $local = false) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        /** @var array{checkout: string, command: string, timeout: float} $payload */
        $payload = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $this->inputs[] = $payload;
        $this->shells[] = $command->shellCommand();

        if ($this->local) {
            // The runner's program starts `/usr/bin/bash`, which the toolchain supplies on a non-Linux host.
            return (new NativeProcessRunner)->run(new ProcessInvocation(
                arguments: array_map(TestToolchain::script(...), $command->arguments),
                protectedInput: $command->protectedInput,
                timeout: $command->timeout ?? 10.0,
            ));
        }

        return new CommandResult($this->result === null ? 0 : ($this->result)($payload), '', '', 1, false);
    }

    public function runner(?CommandDeadline $deadline = null): ProjectLifecycleRunner
    {
        return new ProjectLifecycleRunner(
            new ProjectLifecycleStepStore,
            new AppDevSshExecutor($this, new class implements SshKeyProvider
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
        );
    }
}
