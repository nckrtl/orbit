<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\SshNodeStateInspector;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('maps a bounded successful SSH observation', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            expect($connection->host)
                ->toBe('10.44.0.7')
                ->and($connection->user)
                ->toBe('nckrtl')
                ->and($connection->port)
                ->toBe(22)
                ->and($connection->identityFile)
                ->toBe('/key')
                ->and($connection->knownHostsFile)
                ->toBe('/known')
                ->and($connection->commandTimeout)
                ->toBe(30.0)
                // A shared connection outlives a stopped sshd, so reachability needs a new one.
                ->and($connection->shareConnection)
                ->toBeFalse();
            expect($command->arguments)
                ->toBe(['bash', '-seu', '--', '10.44.0.7'])
                ->and($command->input)
                ->toContain('uname -s')
                ->toContain('LC_ALL=C df --output=source,avail,size,iavail,itotal -k -- / "$HOME"')
                ->and($command->input)
                ->not->toContain('10.44.0.7');

            // The script reads only the secret file's hash, never its contents (ADR 0155).
            expect($command->input)
                ->toContain('sudo -n sha256sum -- /etc/orbit/agent/secret')
                ->not->toContain('cat');

            return new CommandResult(0, "Linux\nx86_64\n1\n1\n1\n1\n".NodeAgentFootprint::checksum('x86_64')."\n".str_repeat('b', 64)."\nFilesystem Avail 1K-blocks IFree Inodes\n/dev/root 16384 26214400 90000 100000\n/dev/root 16384 26214400 90000 100000\n", 'secret stderr', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $node = new Node(['user' => 'nckrtl', 'wireguard_ip' => '10.44.0.7', 'architecture' => 'x86_64']);

    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect($node);

    expect($result->reachable)
        ->toBeTrue()
        ->and($result->platform)
        ->toBe('linux')
        ->and($result->architecture)
        ->toBe('x86_64')
        ->and($result->wireGuardAddressMatches)
        ->toBeTrue()
        ->and($result->agentBinaryExists)
        ->toBeTrue()
        ->and($result->agentUnitExists)
        ->toBeTrue()
        ->and($result->agentActive)
        ->toBeTrue()
        ->and($result->agentChecksumMatches)
        ->toBeTrue()
        ->and($result->agentSecretChecksum)
        ->toBe(str_repeat('b', 64))
        ->and($result->diskFilesystems)
        ->toHaveCount(1)
        ->and($result->diskFilesystems[0]->location)
        ->toBe('root')
        ->and($result->diskFilesystems[0]->availableKiB)
        ->toBe(16384);
});

it('reports disk low through SSH even with unavailable inode accounting or negative availability', function (string $diskRow, bool $low, int $expectedAvailable, ?int $freeInodes, ?int $totalInodes): void {
    $ssh = new class($diskRow) implements SshExecutor
    {
        public function __construct(private readonly string $diskRow) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, "Linux\nx86_64\n1\n0\n0\n0\n\n\nFilesystem Avail 1K-blocks IFree Inodes\n{$this->diskRow}\n{$this->diskRow}\n", '', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $node = new Node([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    $inspection = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect($node);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, $inspection));
    $diskIssues = array_values(array_filter($report->issues, static fn (DoctorIssueData $issue): bool => $issue->code === 'node.disk_low'));

    expect($inspection->reachable)->toBeTrue()
        ->and($inspection->diskFilesystems)->toHaveCount(1)
        ->and($inspection->diskFilesystems[0]->availableKiB)->toBe($expectedAvailable)
        ->and($inspection->diskFilesystems[0]->freeInodes)->toBe($freeInodes)
        ->and($inspection->diskFilesystems[0]->totalInodes)->toBe($totalInodes)
        ->and($diskIssues)->toHaveCount($low ? 1 : 0);
    if ($low) {
        expect($diskIssues[0]->kind->value)->toBe('drift')
            ->and($diskIssues[0]->observed)->toContain($totalInodes === null ? 'inodes unavailable' : '90000 of 100000 inodes free');
    }
})->with([
    'Btrfs low space' => ['/dev/root 16384 26214400 0 0', true, 16384, null, null],
    'Btrfs healthy space' => ['/dev/root 16777216 26214400 0 0', false, 16777216, null, null],
    'inode totals explicitly unavailable' => ['/dev/root 16777216 26214400 - -', false, 16777216, null, null],
    'negative available space' => ['/dev/root -16384 26214400 90000 100000', true, 0, 90000, 100000],
]);

it('rejects malformed successful output and bounds architecture aliases', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(0, "Linux\nunknown\n1\nsecret", 'secret', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    expect(fn (): mixed => new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.7',
    ])))
        ->toThrow(DoctorInspectionException::class);
});

it('rejects truncated successful output', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(0, "Linux\nx86_64\n1\n1\n1\n1\n".NodeAgentFootprint::checksum('x86_64')."\n\n", '', 1, true);
        }
    };
    expect(fn (): mixed => new SshNodeStateInspector(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/key';
            }

            public function publicKey(): string
            {
                return 'key';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/known';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        new CommandDeadline,
    )->inspect(new Node(['user' => 'nckrtl', 'wireguard_ip' => '10.44.0.7'])))
        ->toThrow(DoctorInspectionException::class);
});

it('returns reachable with a missing interface and maps arm aliases', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(0, "Linux\narm64\n0\n0\n0\n0\n\n\nFilesystem Avail 1K-blocks IFree Inodes\n/dev/root 16777216 26214400 90000 100000\n/dev/home 16384 26214400 90000 100000\n", '', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.7',
    ]));
    expect($result->reachable)
        ->toBeTrue()
        ->and($result->architecture)
        ->toBe('aarch64')
        ->and($result->wireGuardAddressMatches)
        ->toBeFalse()
        ->and($result->agentSecretChecksum)
        ->toBeNull()
        ->and($result->diskFilesystems)
        ->toHaveCount(2)
        ->and($result->diskFilesystems[1]->location)
        ->toBe('home');
});

it('maps transport exceptions and missing addresses to unreachable', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            throw new RuntimeException('secret');
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $inspector = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline);
    expect($inspector->inspect(new Node(['user' => 'nckrtl', 'wireguard_ip' => '10.44.0.7']))->reachable)
        ->toBeFalse()
        ->and($inspector->inspect(new Node(['user' => 'nckrtl']))->reachable)
        ->toBeFalse();
});

it('maps timeouts to an unreachable bounded observation', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            throw new RuntimeException('Command timed out.');
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.7',
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
    $ssh = new class implements SshExecutor
    {
        public function execute(
            SshConnection $connection,
            RemoteCommand $command,
        ): CommandResult {
            return new CommandResult(255, 'secret', 'secret', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $result = new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node([
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.7',
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

it('rejects a secret line that is not a SHA-256 hash', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, "Linux\nx86_64\n1\n1\n1\n1\n".NodeAgentFootprint::checksum('x86_64')."\nnot-a-hash\n", '', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/key';
        }

        public function publicKey(): string
        {
            return 'key';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/known';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    expect(fn (): mixed => new SshNodeStateInspector($ssh, $keys, $hosts, new CommandDeadline)->inspect(new Node(['user' => 'orbit', 'wireguard_ip' => '10.44.0.7'])))
        ->toThrow(DoctorInspectionException::class);
});

it('reads a bounded macOS observation without Linux agent commands', function (): void {
    $ssh = new class implements SshExecutor
    {
        public ?RemoteCommand $command = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->command = $command;

            expect($connection->host)->toBe('10.44.0.8')
                ->and($connection->user)->toBe('mini')
                ->and($connection->shareConnection)->toBeFalse()
                ->and($command->arguments)->toBe(['bash', '-seu', '--', '10.44.0.8'])
                ->and($command->input)
                ->toContain('uname -s')
                ->toContain('/sbin/ifconfig')
                ->toContain('df -kP')
                ->not->toContain('10.44.0.8')
                ->not->toContain('systemctl')
                ->not->toContain('systemd')
                ->not->toContain('getent')
                ->not->toContain('sha256sum')
                ->not->toContain('df --output')
                ->not->toContain('ip -o')
                ->not->toContain('orbit-agent');

            return new CommandResult(0, "Darwin\narm64\nok\n1\nok\n16777216 26214400\n", '', 1, false);
        }
    };
    $node = new Node([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);

    $inspection = mac_observation_inspector($ssh)->inspect($node);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, $inspection));

    expect($inspection->reachable)->toBeTrue()
        ->and($inspection->platform)->toBe('darwin')
        ->and($inspection->architecture)->toBe('aarch64')
        ->and($inspection->wireGuardAddressMatches)->toBeTrue()
        ->and($inspection->agentBinaryExists)->toBeNull()
        ->and($inspection->agentUnitExists)->toBeNull()
        ->and($inspection->agentActive)->toBeNull()
        ->and($inspection->agentChecksumMatches)->toBeNull()
        ->and($inspection->agentSecretChecksum)->toBeNull()
        ->and($inspection->diskFilesystems)->toHaveCount(1)
        ->and($inspection->diskFilesystems[0]->location)->toBe('home')
        ->and($inspection->diskFilesystems[0]->availableKiB)->toBe(16777216)
        ->and($inspection->diskFilesystems[0]->freeInodes)->toBeNull()
        ->and($inspection->diskFilesystems[0]->totalInodes)->toBeNull()
        ->and($report->issues)->toBeEmpty();
});

it('reports macOS tunnel and platform mismatch from native observation', function (string $stdout, string $code): void {
    $ssh = new class($stdout) implements SshExecutor
    {
        public function __construct(private readonly string $stdout) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, $this->stdout, '', 1, false);
        }
    };
    $node = new Node([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $inspection = mac_observation_inspector($ssh)->inspect($node);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, $inspection));

    expect($inspection->reachable)->toBeTrue()
        ->and(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))
        ->toContain($code)
        ->not->toContain('node.agent_missing');
})->with([
    'missing tunnel address' => ["Darwin\narm64\nok\n0\nok\n16777216 26214400\n", 'node.wireguard_ip_mismatch'],
    'linux host recorded as macOS' => ["Linux\nx86_64\nunreadable\n0\nok\n1152138872 1920641288\n", 'node.platform_mismatch'],
]);

it('reports platform drift when the macOS script runs on a Linux host', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, "Linux\nx86_64\nunreadable\n0\nok\n1152138872 1920641288\n", '', 1, false);
        }
    };
    $node = new Node([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $inspection = mac_observation_inspector($ssh)->inspect($node);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, $inspection));

    expect($inspection->reachable)->toBeTrue()
        ->and($inspection->platform)->toBe('linux')
        ->and($inspection->architecture)->toBe('x86_64')
        ->and($inspection->diskFilesystems)->toBe([])
        ->and(array_map(static fn (DoctorIssueData $issue): string => $issue->code, $report->issues))->toBe([
            'node.platform_mismatch',
            'node.architecture_mismatch',
        ])
        ->and($report->issues[0]->kind->value)->toBe('drift')
        ->and($report->issues[0]->expected)->toBe('darwin')
        ->and($report->issues[0]->observed)->toBe('linux');
});

it('rejects malformed macOS observation and maps transport failure to unreachable', function (SshExecutor $ssh, string $expectation): void {
    $node = new Node([
        'platform' => 'macos',
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $inspector = mac_observation_inspector($ssh);

    if ($expectation === 'malformed') {
        expect(fn (): mixed => $inspector->inspect($node))->toThrow(DoctorInspectionException::class);

        return;
    }

    expect($inspector->inspect($node)->reachable)->toBeFalse();
})->with([
    'truncated output' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(0, "Darwin\narm64\nok\n1\nok\n16777216 26214400\n", '', 1, true);
            }
        },
        'malformed',
    ],
    'unreadable home volume' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(0, "Darwin\narm64\nok\n1\nunreadable\n0 0\n", '', 1, false);
            }
        },
        'malformed',
    ],
    'unreadable tunnel' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(0, "Darwin\narm64\nunreadable\n0\nok\n16777216 26214400\n", '', 1, false);
            }
        },
        'malformed',
    ],
    'bad disk numbers' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(0, "Darwin\narm64\nok\n1\nok\nnope\n", '', 1, false);
            }
        },
        'malformed',
    ],
    'zero inodes are not invented from a missing counter' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(0, "Darwin\narm64\nok\n1\nok\n16777216 26214400 0 0\n", '', 1, false);
            }
        },
        'malformed',
    ],
    'failed command' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(1, '', 'ssh failed', 1, false);
            }
        },
        'unreachable',
    ],
    'transport exception' => [
        new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                throw new RuntimeException('timed out');
            }
        },
        'unreachable',
    ],
]);

function mac_observation_inspector(SshExecutor $ssh): SshNodeStateInspector
{
    return new SshNodeStateInspector(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/key';
            }

            public function publicKey(): string
            {
                return 'key';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/known';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        new CommandDeadline,
    );
}
