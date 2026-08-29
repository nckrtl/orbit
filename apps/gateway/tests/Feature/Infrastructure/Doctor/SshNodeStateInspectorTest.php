<?php

declare(strict_types=1);

use App\Infrastructure\Doctor\SshNodeStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('maps a bounded successful SSH observation', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            expect($connection->host)
                ->toBe('10.44.0.7')
                ->and($connection->user)
                ->toBe('orbit')
                ->and($connection->port)
                ->toBe(22)
                ->and($connection->identityFile)
                ->toBe('/key')
                ->and($connection->knownHostsFile)
                ->toBe('/known')
                ->and($connection->commandTimeout)
                ->toBe(30.0);
            expect($command->arguments)
                ->toBe(['bash', '-seu', '--', '10.44.0.7'])
                ->and($command->input)
                ->toContain('uname -s')
                ->and($command->input)
                ->not->toContain('10.44.0.7');

            return new CommandResult(0, "Linux\nx86_64\n1\n", 'secret stderr', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };
    $node = new Node(['wireguard_address' => '10.44.0.7']);

    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect($node);

    expect($result->reachable)
        ->toBeTrue()
        ->and($result->platform)
        ->toBe('linux')
        ->and($result->architecture)
        ->toBe('x86_64')
        ->and($result->wireGuardAddressMatches)
        ->toBeTrue();
});

it('rejects malformed successful output and bounds architecture aliases', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(0, "Linux\nunknown\n1\nsecret", 'secret', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };
    expect(fn (): mixed => new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'wireguard_address' => '10.44.0.7',
    ])))
        ->toThrow(\App\Domain\Doctor\DoctorInspectionException::class);
});

it('rejects truncated successful output', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(0, "Linux\nx86_64\n1\n", '', 1, true);
        }
    };
    expect(fn (): mixed => new SshNodeStateInspector(
        $ssh,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/key';
            }

            public function publicKey(): string
            {
                return 'key';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/known';
            }

            public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
        },
        new CommandDeadline,
    )->inspect(new Node(['wireguard_address' => '10.44.0.7'])))
        ->toThrow(\App\Domain\Doctor\DoctorInspectionException::class);
});

it('returns reachable with a missing interface and maps arm aliases', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(0, "Linux\narm64\n0\n", '', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };
    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'wireguard_address' => '10.44.0.7',
    ]));
    expect($result->reachable)
        ->toBeTrue()
        ->and($result->architecture)
        ->toBe('aarch64')
        ->and($result->wireGuardAddressMatches)
        ->toBeFalse();
});

it('maps transport exceptions and missing addresses to unreachable', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            throw new RuntimeException('secret');
        }
    };
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };
    $inspector = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline);
    expect($inspector->inspect(new Node(['wireguard_address' => '10.44.0.7']))->reachable)
        ->toBeFalse()
        ->and($inspector->inspect(new Node)->reachable)
        ->toBeFalse();
});

it('maps timeouts to an unreachable bounded observation', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            throw new RuntimeException('Command timed out.');
        }
    };
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };
    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'wireguard_address' => '10.44.0.7',
    ]));
    expect($result->reachable)
        ->toBeFalse()
        ->and($result->platform)
        ->toBeNull()
        ->and($result->architecture)
        ->toBeNull()
        ->and($result->wireGuardAddressMatches)
        ->toBeNull();
});

it('maps command failures to an unreachable bounded observation', function (): void {
    $ssh = new class implements SshExecutor {
        public function execute(
            SshConnection $connection,
            \App\Infrastructure\Ssh\RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(255, 'secret', 'secret', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };
    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'wireguard_address' => '10.44.0.7',
    ]));
    expect($result->reachable)
        ->toBeFalse()
        ->and($result->platform)
        ->toBeNull()
        ->and($result->architecture)
        ->toBeNull()
        ->and($result->wireGuardAddressMatches)
        ->toBeNull();
});
