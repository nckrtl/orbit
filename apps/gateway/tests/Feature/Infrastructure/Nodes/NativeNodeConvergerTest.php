<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\RecoverableWireGuardPeerConverger;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('fails closed before SSH when no host adapter supports the node platform', function (): void {
    expect(interface_exists(NodeRoleFirewallManager::class))->toBeTrue();

    $node = base_provisionable_node();
    $node->update(['platform' => 'windows']);
    $scans = 0;
    $scanner = new class($scans) implements HostKeyScanner {
        public function __construct(
            private int &$scans,
        ) {}

        public function scan(string $host, int $port): HostKey
        {
            $this->scans++;

            return new HostKey('ssh-ed25519', 'PUBLICKEY', 'SHA256:pinned');
        }
    };
    $converger = base_node_converger(
        scanner: $scanner,
        ssh: new class implements SshExecutor {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                throw new LogicException('SSH must not run for an unsupported platform.');
            }
        },
    );

    expect(fn () => $converger->converge($node, base_identity(), 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('platform')
                ->and($exception->errorCode)
                ->toBe('node.platform_unsupported');
        });
    expect($scans)->toBe(0);
});

it('stops unsupported operating systems before the first bootstrap mutation', function (
    ?string $release,
    string $identifier,
): void {
    $result = run_base_bootstrap_preflight($release);

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->getErrorOutput())
        ->toBe(UbuntuRelease::unsupportedText(...explode('/', $identifier))."\n")
        ->and($result->getOutput())
        ->not->toContain('mutation-reached');
})->with([
    'missing release file' => [null, 'unknown/unknown'],
    'unsupported Ubuntu release' => ["ID=ubuntu\nVERSION_CODENAME=unsupported\n", 'ubuntu/unsupported'],
    'Debian' => ["ID=debian\nVERSION_CODENAME=resolute\n", 'debian/resolute'],
    'malformed codename' => ["ID=ubuntu\nVERSION_CODENAME='resolute extra'\n", 'unknown/unknown'],
]);

it('does not execute commands from the remote os-release file', function (): void {
    $marker = sys_get_temp_dir().'/orbit-os-release-sourced-'.Str::random(16);
    $result = run_base_bootstrap_preflight("ID=ubuntu\nVERSION_CODENAME=$(touch __OS_RELEASE_MARKER__)\n", $marker);

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->getErrorOutput())
        ->toContain(UbuntuRelease::unsupportedText())
        ->and($result->getErrorOutput())
        ->not
        ->toContain('__OS_RELEASE_MARKER__')
        ->and(is_file($marker))
        ->toBeFalse();
});

it('accepts matching quoted os-release values', function (string $release): void {
    $result = run_base_bootstrap_preflight($release);

    expect($result->isSuccessful())->toBeTrue()->and($result->getOutput())->toContain('mutation-reached');
})->with([
    'double quoted' => "ID=\"ubuntu\"\nVERSION_CODENAME=\"resolute\"\n",
    'single quoted' => "ID='ubuntu'\nVERSION_CODENAME='resolute'\n",
    'bare values without final newline' => "ID=ubuntu\nVERSION_CODENAME=resolute",
]);

it('rejects unsafe or incomplete os-release metadata before bootstrap mutation', function (string $release): void {
    $payload = 'payload-'.Str::random(16);
    $mutation = 'mutation-'.Str::random(16);
    $result = run_base_bootstrap_preflight(
        str_replace('__PAYLOAD_MARKER__', $payload, $release),
        mutationMarker: $mutation,
    );

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->getErrorOutput())
        ->toBe(UbuntuRelease::unsupportedText()."\n")
        ->and($result->getOutput())
        ->not->toContain($payload, $mutation);
})->with([
    'duplicate id with supported value first' => "ID=ubuntu\nID=__PAYLOAD_MARKER__\nVERSION_CODENAME=resolute\n",
    'duplicate id with supported value last' => "ID=__PAYLOAD_MARKER__\nID=ubuntu\nVERSION_CODENAME=resolute\n",
    'duplicate codename with supported value first' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=__PAYLOAD_MARKER__\n",
    'duplicate codename with supported value last' => "ID=ubuntu\nVERSION_CODENAME=__PAYLOAD_MARKER__\nVERSION_CODENAME=resolute\n",
    'missing id' => "VERSION_CODENAME=resolute\n",
    'empty codename' => "ID=ubuntu\nVERSION_CODENAME=\n",
    'mismatched quotes' => "ID=ubuntu\nVERSION_CODENAME=\"__PAYLOAD_MARKER__'\n",
    'unclosed quotes' => "ID=ubuntu\nVERSION_CODENAME='__PAYLOAD_MARKER__\n",
    'command substitution' => "ID=ubuntu\nVERSION_CODENAME=\$(touch __PAYLOAD_MARKER__)\n",
    'backticks' => "ID=ubuntu\nVERSION_CODENAME=`touch __PAYLOAD_MARKER__`\n",
    'semicolon' => "ID=ubuntu\nVERSION_CODENAME=resolute; touch __PAYLOAD_MARKER__\n",
]);

it('keeps the base bootstrap role-neutral with one fixed shared package list', function (): void {
    $command = base_bootstrap_command();
    $script = $command->input ?? '';

    expect($command->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'ubuntu',
            'resolute',
            UbuntuRelease::unsupportedText(),
            'orbit',
            'ssh-ed25519 GATEWAY',
            'ca-certificates',
            'curl',
            'gnupg',
            'libnss-resolve',
            'openssh-client',
            'sudo',
            'ufw',
            'wireguard',
        ])
        ->and($script)
        ->toContain(
            'useradd --create-home --shell /bin/bash -- "$managed_user"',
            'install -d -m 0700 -o "$managed_user" -g "$managed_group"',
            'printf \'%s ALL=(ALL) NOPASSWD:ALL\\n\' "$managed_user"',
        )
        ->not->toContain(
            '/home/orbit/apps',
            '/home/orbit/.orbit/worktrees',
            'setfacl',
            '/opt/orbit/vite-plus',
            '/opt/orbit/bun',
            'https://vite.plus',
            'https://bun.com/install',
            '/usr/local/bin/node',
            'caddy',
            'dnsmasq',
            'docker.io',
            'openssl',
        );
});

it('accepts each supported Ubuntu release before the first bootstrap mutation', function (string $release): void {
    $result = run_base_bootstrap_preflight($release);

    expect($result->isSuccessful())->toBeTrue()->and($result->getOutput())->toContain('mutation-reached');
})->with([
    'Ubuntu Resolute' => "ID=ubuntu\nVERSION_CODENAME=resolute\n",
]);

it('pins the host and converges only base node identity and connectivity', function (): void {
    expect(interface_exists(NodeRoleFirewallManager::class))->toBeTrue();

    $node = base_provisionable_node();
    $node->roles()->delete();
    $knownHosts = new class implements KnownHostsStore {
        /** @var list<string> */
        public array $hosts = [];

        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void
        {
            $this->hosts[] = "{$host}:{$port}:{$key->fingerprint}";
        }
    };
    $ssh = new BaseNodeSshExecutor;
    $baseNodes = [];
    $firewallRoles = [];
    $firewall = base_firewall_spy($baseNodes, $firewallRoles);
    $wireGuard = new class implements WireGuardPeerConverger {
        public bool $converged = false;

        public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void
        {
            $this->converged = true;
        }
    };
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: $knownHosts,
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: $wireGuard,
        firewall: $firewall,
    );

    $converger->converge($node, base_identity(), 'SHA256:pinned');

    expect($node->refresh()->user)
        ->toBe('root')
        ->and($node->ssh_host_fingerprint)
        ->toBe('SHA256:pinned')
        ->and($knownHosts->hosts)
        ->toBe([
            '192.0.2.10:22:SHA256:pinned',
            '10.44.0.2:22:SHA256:pinned',
        ])
        ->and($baseNodes)
        ->toBe([$node->id])
        ->and($firewallRoles)
        ->toBe([RoleName::Vpn])
        ->and($wireGuard->converged)
        ->toBeTrue()
        ->and($ssh->calls)
        ->toHaveCount(3)
        ->and($ssh->calls[0]['connection']->user)
        ->toBe('root')
        ->and($ssh->calls[1]['command']->arguments)
        ->toBe(['true'])
        ->and($ssh->calls[2]['connection']->host)
        ->toBe('10.44.0.2')
        ->and($ssh->calls[2]['command']->arguments)
        ->toBe(['true']);
});

it('reprovisions active role-bearing nodes only through WireGuard', function (): void {
    $node = base_provisionable_node();
    $node->update(['status' => \App\Domain\Shared\LifecycleStatus::Active, 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $scans = [];
    $scanner = new class($scans) implements HostKeyScanner {
        /** @param list<string> $scans */
        public function __construct(
            private array &$scans,
        ) {}

        public function scan(string $host, int $port): HostKey
        {
            $this->scans[] = "{$host}:{$port}";

            return new HostKey('ssh-ed25519', 'PUBLICKEY', 'SHA256:pinned');
        }
    };
    $ssh = new BaseNodeSshExecutor;
    $wireGuardConnections = [];
    $wireGuard = new class($wireGuardConnections) implements WireGuardPeerConverger, RecoverableWireGuardPeerConverger {
        /** @param list<string> $connections */
        public function __construct(
            private array &$connections,
        ) {}

        public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}

        public function convergeRecoverably(
            Node $node,
            SshConnection $connection,
            Closure $completion,
            bool $rolelessOperator = false,
        ): void {
            $this->connections[] = "{$connection->host}:{$connection->port}";
            $completion();
        }
    };
    $baseFirewallNodes = [];
    $firewallRoles = [];
    $converger = new NativeNodeConverger(
        hostKeys: $scanner,
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: $wireGuard,
        firewall: base_firewall_spy($baseFirewallNodes, $firewallRoles),
    );

    $completed = false;

    $converger->convergeRecoverably($node, base_identity(), null, static function () use (&$completed): void {
        $completed = true;
    });

    expect($scans)->toBe(['10.44.0.2:22']);
    expect($wireGuardConnections)->toBe([]);
    expect($baseFirewallNodes)->toBe([]);
    expect($firewallRoles)->toBe([RoleName::Vpn]);
    expect($completed)->toBeTrue();
    expect(array_map(
        static fn (array $call): string => "{$call['connection']->host}:{$call['connection']->port}",
        $ssh->calls,
    ))->toBe([
        '10.44.0.2:22',
        '10.44.0.2:22',
        '10.44.0.2:22',
    ]);
});

it('commits recoverable peer publication before activating orbit SSH for active roleless reprovisioning', function (): void {
    $node = base_provisionable_node();
    $node->roles()->delete();
    $node->update(['status' => \App\Domain\Shared\LifecycleStatus::Active, 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $events = [];
    $wireGuard = new class($events) implements WireGuardPeerConverger, RecoverableWireGuardPeerConverger {
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void
        {
            $this->events[] = 'ordinary-wireguard';
        }

        public function convergeRecoverably(
            Node $node,
            SshConnection $connection,
            Closure $completion,
            bool $rolelessOperator = false,
        ): void {
            $this->events[] = 'wireguard-publish';
            $completion();
            $this->events[] = 'wireguard-commit';
        }
    };
    $ssh = new BaseNodeSshExecutor;
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: $wireGuard,
        firewall: base_firewall_spy(),
    );

    expect($converger)->toBeInstanceOf(RecoverableNodeConverger::class);
    $converger->convergeRecoverably($node, base_identity(), 'SHA256:pinned', function () use (&$events): void {
        $events[] = 'apt';
    });

    expect($events)->toBe([
        'wireguard-publish',
        'apt',
        'wireguard-commit',
    ]);
    expect($ssh->calls)->toHaveCount(3);
});

it('rolls back recoverable peer publication when roleless private ssh verification fails', function (): void {
    $node = base_provisionable_node();
    $node->roles()->delete();
    $node->update(['status' => \App\Domain\Shared\LifecycleStatus::Active, 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $events = [];
    $wireGuard = new class($events) implements WireGuardPeerConverger, RecoverableWireGuardPeerConverger {
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}

        public function convergeRecoverably(
            Node $node,
            SshConnection $connection,
            Closure $completion,
            bool $rolelessOperator = false,
        ): void {
            $this->events[] = 'wireguard-publish';

            try {
                $completion();
            } catch (Throwable $throwable) {
                $this->events[] = 'wireguard-rollback';

                throw $throwable;
            }
        }
    };
    $ssh = new class implements SshExecutor {
        public int $calls = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return match ($this->calls) {
                1, 2 => new CommandResult(0, '', '', 1, false),
                default => new CommandResult(1, '', 'no route', 1, false),
            };
        }
    };
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: $wireGuard,
        firewall: base_firewall_spy(),
    );

    expect(fn () => $converger->convergeRecoverably(
        $node,
        base_identity(),
        'SHA256:pinned',
        static function (): void {},
    ))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)->toBe('wireguard-ssh')->and($exception->errorCode)->toBe('vpn.peer_ssh_failed');
        });

    expect($events)->toBe(['wireguard-publish', 'wireguard-rollback']);
});

it('retries a transient private WireGuard SSH connection with bounded backoff', function (): void {
    $node = base_provisionable_node();
    $ssh = new class implements SshExecutor {
        public int $calls = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return match ($this->calls) {
                3 => new CommandResult(
                    255,
                    '',
                    'ssh: connect to host 10.44.0.7 port 22: Connection timed out',
                    1,
                    false,
                ),
                4 => new CommandResult(0, '', '', 1, false),
                default => new CommandResult(0, '', '', 1, false),
            };
        }
    };
    $sleeps = [];
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: new class implements WireGuardPeerConverger {
            public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}
        },
        firewall: base_firewall_spy(),
        sleep: static function (int $delay) use (&$sleeps): int {
            $sleeps[] = $delay;

            return 0;
        },
    );

    $converger->converge($node, base_identity(), 'SHA256:pinned');

    expect($ssh->calls)->toBe(4)->and($sleeps)->toBe([1_000_000]);
});

it('preserves the final transient private SSH failure after retries', function (): void {
    $node = base_provisionable_node();
    $ssh = new class implements SshExecutor {
        public int $calls = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return match ($this->calls) {
                1, 2 => new CommandResult(0, '', '', $this->calls, false),
                3 => new CommandResult(255, '', 'ssh: connect to host 10.44.0.7 port 22: timeout-1', 3, false),
                4 => new CommandResult(255, '', 'ssh: connect to host 10.44.0.7 port 22: timeout-2', 4, false),
                default => new CommandResult(
                    255,
                    '',
                    'ssh: connect to host 10.44.0.7 port 22: timeout-final',
                    5,
                    false,
                ),
            };
        }
    };
    $sleeps = [];
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: new class implements WireGuardPeerConverger {
            public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}
        },
        firewall: base_firewall_spy(),
        sleep: static function (int $delay) use (&$sleeps): int {
            $sleeps[] = $delay;

            return 0;
        },
    );
    expect(
        fn () => $converger->converge($node, base_identity(), 'SHA256:pinned'),
    )->toThrow(function (NodeProvisioningException $exception) use ($ssh, &$sleeps): void {
        expect($exception->step)
            ->toBe('wireguard-ssh')
            ->and($exception->errorCode)
            ->toBe('vpn.peer_ssh_failed')
            ->and($exception->result?->durationMs)
            ->toBe(5);
        expect($exception->result?->exitCode)
            ->toBe(255)
            ->and($exception->result?->stderr)
            ->toBe('ssh: connect to host 10.44.0.7 port 22: timeout-final')
            ->and($exception->getMessage())
            ->toBe('Could not reach node [base-node] through WireGuard.');
        expect($ssh->calls)->toBe(5)->and($sleeps)->toBe([1_000_000, 2_000_000]);
    });
});

it('does not retry semantic private SSH exit 255 failures', function (): void {
    $node = base_provisionable_node();
    $ssh = new class implements SshExecutor {
        public int $calls = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return $this->calls < 3
                ? new CommandResult(0, '', '', 1, false)
                : new CommandResult(255, '', 'remote command failed', 3, false);
        }
    };
    $sleeps = [];
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: new class implements WireGuardPeerConverger {
            public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}
        },
        firewall: base_firewall_spy(),
        sleep: static function (int $delay) use (&$sleeps): int {
            $sleeps[] = $delay;

            return 0;
        },
    );
    expect(fn () => $converger->converge($node, base_identity(), 'SHA256:pinned'))
        ->toThrow('Could not reach node')
        ->and($ssh->calls)
        ->toBe(3)
        ->and($sleeps)
        ->toBeEmpty();
});

it('uses passwordless sudo for the same fixed base command when reconnecting as orbit', function (): void {
    expect(interface_exists(NodeRoleFirewallManager::class))->toBeTrue();

    $node = base_provisionable_node();
    $node->update(['user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:pinned']);
    $ssh = new BaseNodeSshExecutor;
    $factory = new NodeBootstrapCommandFactory(base_test_keys());
    $expected = $factory->makeWithPasswordlessSudo($node, 'orbit');
    $converger = new NativeNodeConverger(
        hostKeys: base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: $factory,
        wireGuard: new class implements WireGuardPeerConverger {
            public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}
        },
        firewall: base_firewall_spy(),
    );

    $converger->converge($node, new NodeProvisioningIdentity('nckrtl', 'orbit'));

    expect($ssh->calls[0]['command']->arguments)
        ->toBe($expected->arguments)
        ->and($ssh->calls[0]['command']->input)
        ->toBe($expected->input);
});

it('reports a bounded base bootstrap failure before later convergence', function (): void {
    expect(interface_exists(NodeRoleFirewallManager::class))->toBeTrue();

    $node = base_provisionable_node();
    $failure = new CommandResult(1, '', 'sudo failed', 10, false);
    $ssh = new class($failure) implements SshExecutor {
        public function __construct(
            private CommandResult $failure,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return $this->failure;
        }
    };
    $converger = base_node_converger($ssh);

    expect(fn () => $converger->converge($node, base_identity(), 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception) use ($failure): void {
            expect($exception->step)
                ->toBe('base-host')
                ->and($exception->errorCode)
                ->toBe('node.bootstrap_failed')
                ->and($exception->result)
                ->toBe($failure);
        });
});

it('translates base firewall failures to node provisioning failures', function (): void {
    expect(interface_exists(NodeRoleFirewallManager::class))->toBeTrue();

    $node = base_provisionable_node();
    $node->roles()->delete();
    $result = new CommandResult(1, '', 'ufw failed', 10, false);
    $firewall = new class($result) implements NodeRoleFirewallManager {
        public function __construct(
            private CommandResult $result,
        ) {}

        public function convergeBase(Node $node, string $managedUser): void
        {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'node.firewall_convergence_failed',
                message: 'Could not converge UFW.',
                result: $this->result,
            );
        }

        public function converge(Node $node, RoleName $role, string $managedUser): void {}

        public function remove(Node $node, RoleName $role, string $managedUser): void {}
    };
    $converger = base_node_converger(new BaseNodeSshExecutor, firewall: $firewall);

    expect(fn () => $converger->converge($node, base_identity(), 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception) use ($result): void {
            expect($exception->step)
                ->toBe('host-firewall')
                ->and($exception->errorCode)
                ->toBe('node.firewall_convergence_failed')
                ->and($exception->result)
                ->toBe($result);
        });
});

it('guards first-contact and stored SSH fingerprints before remote effects', function (
    ?string $stored,
    ?string $expected,
    string $observed,
    string $code,
): void {
    expect(interface_exists(NodeRoleFirewallManager::class))->toBeTrue();

    $node = base_provisionable_node();
    $node->update(['ssh_host_fingerprint' => $stored]);
    $calls = 0;
    $ssh = new class($calls) implements SshExecutor {
        public function __construct(
            private int &$calls,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $scanner = new class($observed) implements HostKeyScanner {
        public function __construct(
            private string $observed,
        ) {}

        public function scan(string $host, int $port): HostKey
        {
            return new HostKey('ssh-ed25519', 'PUBLICKEY', $this->observed);
        }
    };
    $converger = base_node_converger($ssh, scanner: $scanner);

    expect(fn () => $converger->converge($node, base_identity(), $expected))
        ->toThrow(function (NodeProvisioningException $exception) use ($code): void {
            expect($exception->step)
                ->toBe('ssh-host-key')
                ->and($exception->errorCode)
                ->toBe($code);
        });
    expect($calls)->toBe(0);
})->with([
    'expected fingerprint is required' => [null, null, 'SHA256:pinned', 'node.ssh_host_fingerprint_required'],
    'first-contact mismatch' => [null, 'SHA256:expected', 'SHA256:other', 'node.ssh_host_key_mismatch'],
    'stored key changed' => ['SHA256:stored', null, 'SHA256:other', 'node.ssh_host_key_changed'],
]);

function base_provisionable_node(): Node
{
    $node = Node::query()->create([
        'name' => 'base-node',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'root',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev]);

    return $node;
}

function base_test_scanner(): HostKeyScanner
{
    return new class implements HostKeyScanner {
        public function scan(string $host, int $port): HostKey
        {
            return new HostKey('ssh-ed25519', 'PUBLICKEY', 'SHA256:pinned');
        }
    };
}

function base_test_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

function base_test_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 GATEWAY';
        }
    };
}

function base_bootstrap_command(): RemoteCommand
{
    return new NodeBootstrapCommandFactory(base_test_keys())->make(new Node, 'orbit');
}

function base_identity(): NodeProvisioningIdentity
{
    return new NodeProvisioningIdentity('root', 'orbit');
}

it('bootstraps a supplied nckrtl identity without orbit literals or package confusion', function (): void {
    $factory = new NodeBootstrapCommandFactory(base_test_keys());
    $command = $factory->makeWithPasswordlessSudo(new Node, 'nckrtl');
    $script = $command->input ?? '';

    expect($command->arguments)
        ->toBe([
            'sudo',
            '-n',
            '--',
            'bash',
            '-seu',
            '--',
            'ubuntu',
            'resolute',
            UbuntuRelease::unsupportedText(),
            'nckrtl',
            'ssh-ed25519 GATEWAY',
            'ca-certificates',
            'curl',
            'gnupg',
            'libnss-resolve',
            'openssh-client',
            'sudo',
            'ufw',
            'wireguard',
        ])
        ->and($script)
        ->toContain(
            'managed_user=$4',
            'orbit_key=$5',
            'useradd --create-home --shell /bin/bash -- "$managed_user"',
            'sudo -n -u "$managed_user" -- sudo -n true',
            '[ ! -d "$managed_home/.ssh" ]',
            '[ ! -d "$managed_home/.orbit" ]',
            '[ -L "$managed_home/.ssh" ]',
            '[ -L "$managed_home/.orbit" ]',
            '[ -L "$managed_home" ]',
            '[ -L "$authorized_keys" ]',
            'chown "$managed_user:$managed_group" -- "$authorized_keys"',
            'chmod 0600 -- "$authorized_keys"',
            'trap \'rm -f -- "$sudoers"\' EXIT',
        )
        ->not->toContain('useradd orbit', '/home/orbit', '/etc/sudoers.d/orbit')->and(array_slice(
            $command->arguments,
            12,
        ))
        ->not->toContain('nckrtl');
});

it('reports the selected managed user when its SSH verification fails', function (): void {
    $node = base_provisionable_node();
    $ssh = new class implements SshExecutor {
        public int $calls = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return $this->calls === 1
                ? new CommandResult(0, '', '', 1, false)
                : new CommandResult(255, '', 'permission denied', 1, false);
        }
    };

    expect(fn () => base_node_converger($ssh)->converge(
        $node,
        new NodeProvisioningIdentity('nckrtl', 'nckrtl'),
        'SHA256:pinned',
    ))->toThrow(function (NodeProvisioningException $exception): void {
        expect($exception->errorCode)
            ->toBe('node.orbit_ssh_failed')
            ->and($exception->getMessage())
            ->toBe('Could not connect to node [base-node] as nckrtl.');
    });
});

function run_base_bootstrap_preflight(
    ?string $release,
    ?string $marker = null,
    string $mutationMarker = 'mutation-reached',
): Process {
    $filesystem = new Filesystem;
    $directory = sys_get_temp_dir().'/orbit-node-bootstrap-'.Str::random(16);
    $releasePath = "{$directory}/os-release";
    $filesystem->makeDirectory($directory, 0o700);

    if ($release !== null) {
        if ($marker !== null) {
            $release = str_replace('__OS_RELEASE_MARKER__', $marker, $release);
        }
        $filesystem->put($releasePath, $release);
    }

    $script = base_bootstrap_command()->input ?? '';
    $preMutation = strstr(haystack: $script, needle: 'export DEBIAN_FRONTEND=noninteractive', before_needle: true);
    $harness =
        str_replace('/etc/os-release', $releasePath, is_string($preMutation) ? $preMutation : $script)
        ."printf '{$mutationMarker}\\n'\n";
    $process = new Process([
        'bash',
        '-seu',
        '--',
        'ubuntu',
        'resolute',
        UbuntuRelease::unsupportedText(),
        'orbit',
        'ssh-ed25519 TEST',
    ]);
    $process->setInput($harness);
    $process->run();
    $filesystem->deleteDirectory($directory);

    return $process;
}

function base_node_converger(
    SshExecutor $ssh,
    ?HostKeyScanner $scanner = null,
    ?NodeRoleFirewallManager $firewall = null,
): NativeNodeConverger {
    return new NativeNodeConverger(
        hostKeys: $scanner ?? base_test_scanner(),
        knownHosts: base_test_known_hosts(),
        sshKeys: base_test_keys(),
        ssh: $ssh,
        bootstrapCommand: new NodeBootstrapCommandFactory(base_test_keys()),
        wireGuard: new class implements WireGuardPeerConverger {
            public function converge(Node $node, SshConnection $connection, bool $rolelessOperator = false): void {}
        },
        firewall: $firewall ?? base_firewall_spy(),
    );
}

/** @mago-expect lint:file-name The fake stays with its base convergence tests. */
final class BaseNodeSshExecutor implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];

        return new CommandResult(0, '', '', 1, false);
    }
}

/**
 * @param list<int>|null $baseNodes
 * @param list<RoleName>|null $roles
 */
function base_firewall_spy(?array &$baseNodes = null, ?array &$roles = null): NodeRoleFirewallManager
{
    $firewall = Mockery::mock(NodeRoleFirewallManager::class);
    $firewall
        ->shouldReceive('convergeBase')
        ->andReturnUsing(static function (Node $node) use (&$baseNodes): void {
            if (is_array($baseNodes)) {
                $baseNodes[] = $node->id;
            }
        });
    $firewall
        ->shouldReceive('converge')
        ->andReturnUsing(static function (Node $node, RoleName $role) use (&$roles): void {
            if (is_array($roles)) {
                $roles[] = $role;
            }
        });

    return $firewall;
}
