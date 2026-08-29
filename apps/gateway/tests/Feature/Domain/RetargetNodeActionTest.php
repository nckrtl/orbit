<?php

declare(strict_types=1);

use App\Actions\Nodes\RetargetNodeAction;
use App\Data\Nodes\RetargetNodeData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;

describe(RetargetNodeAction::class, function (): void {
    beforeEach(function (): void {
        app()->instance(HostKeyScanner::class, new class implements HostKeyScanner {
            public bool $throws = false;

            /** @var list<array{host:string,port:int}> */
            public array $scans = [];

            public function scan(string $host, int $port): HostKey
            {
                $this->scans[] = ['host' => $host, 'port' => $port];

                if ($this->throws) {
                    throw new RuntimeException('scan failed');
                }

                return new HostKey('ssh-ed25519', 'host-key', 'SHA256:pinned');
            }
        });
        app()->instance(KnownHostsStore::class, new class implements KnownHostsStore {
            /** @var list<array{host:string,port:int,fingerprint:string}> */
            public array $writes = [];

            public function put(string $host, int $port, HostKey $key): void
            {
                $this->writes[] = ['host' => $host, 'port' => $port, 'fingerprint' => $key->fingerprint];
            }

            public function path(): string
            {
                return '/tmp/known-hosts';
            }
        });
        app()->instance(SshKeyProvider::class, new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAAC3Nza';
            }
        });
        app()->instance(SshExecutor::class, new class implements SshExecutor {
            /** @var list<array{connection:SshConnection,command:RemoteCommand}> */
            public array $calls = [];
            public CommandResult $result;

            public function __construct()
            {
                $this->result = new CommandResult(0, '', '', 1, false);
            }

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->calls[] = compact('connection', 'command');

                return $this->result;
            }
        });
        app()->instance(WireGuardPeerConverger::class, new class implements WireGuardPeerConverger {
            public ?Node $node = null;
            public ?SshConnection $connection = null;
            public ?Throwable $throws = null;

            public function converge(Node $node, SshConnection $connection): void
            {
                $this->node = $node;
                $this->connection = $connection;

                if ($this->throws instanceof Throwable) {
                    throw $this->throws;
                }
            }
        });
    });

    it('retargets an active node without roles over public ssh and preserves its identity fields', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'aarch64',
            'tld' => 'app-dev.orbit',
            'public_ssh_host' => '192.0.2.10',
            'public_ssh_port' => 2222,
            'user' => 'nckrtl',
            'wireguard_address' => '10.44.0.3',
            'wireguard_endpoint_override' => '198.51.100.10:51820',
            'dns_server_override' => '10.44.0.1',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        $retargeted = app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
            publicSshPort: 2202,
        ));

        /** @var KnownHostsStore&object{writes:list<array{host:string,port:int,fingerprint:string}>} $knownHosts */
        $knownHosts = app(KnownHostsStore::class);
        /** @var WireGuardPeerConverger&object{connection:?SshConnection} $wireGuard */
        $wireGuard = app(WireGuardPeerConverger::class);
        /** @var SshExecutor&object{calls:list<array{connection:SshConnection,command:RemoteCommand}>} $ssh */
        $ssh = app(SshExecutor::class);

        expect($retargeted->only([
            'status',
            'platform',
            'architecture',
            'tld',
            'public_ssh_host',
            'public_ssh_port',
            'user',
            'wireguard_address',
            'wireguard_endpoint_override',
            'dns_server_override',
        ]))
            ->toBe([
                'status' => LifecycleStatus::Active,
                'platform' => 'linux',
                'architecture' => 'aarch64',
                'tld' => 'app-dev.orbit',
                'public_ssh_host' => '198.51.100.25',
                'public_ssh_port' => 2202,
                'user' => 'nckrtl',
                'wireguard_address' => '10.44.0.3',
                'wireguard_endpoint_override' => '198.51.100.10:51820',
                'dns_server_override' => '10.44.0.1',
            ])
            ->and($knownHosts->writes)
            ->toBe([
                ['host' => '198.51.100.25', 'port' => 2202, 'fingerprint' => 'SHA256:pinned'],
                ['host' => '10.44.0.3', 'port' => 22, 'fingerprint' => 'SHA256:pinned'],
            ])
            ->and($wireGuard->connection?->host)
            ->toBe('198.51.100.25')
            ->and($wireGuard->connection?->port)
            ->toBe(2202)
            ->and($wireGuard->connection?->user)
            ->toBe('nckrtl')
            ->and($ssh->calls)
            ->toHaveCount(1)
            ->and($ssh->calls[0]['connection']->user)
            ->toBe('nckrtl');
    });

    it('rejects an invalid public ssh host', function (): void {
        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: 'bad host',
        )))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('validation')
                    ->and($exception->errorCode)
                    ->toBe('node.public_ssh_host_invalid');
            });
    });

    it('rejects an invalid public ssh port', function (int $port): void {
        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
            publicSshPort: $port,
        )))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('validation')
                    ->and($exception->errorCode)
                    ->toBe('node.public_ssh_port_invalid');
            });
    })->with([0, 65536]);

    it('rejects a missing or inactive node', function (LifecycleStatus $status): void {
        Node::query()->create([
            'name' => 'app-dev',
            'status' => $status,
            'public_ssh_host' => '192.0.2.10',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
        )))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('lookup')->and($exception->errorCode)->toBe('node.not_active');
            });
    })->with([LifecycleStatus::Provisioning, LifecycleStatus::Failed]);

    it('rejects a missing node', function (): void {
        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'missing',
            publicSshHost: '198.51.100.25',
        )))
            ->toThrow(NodeProvisioningException::class, 'Node [missing] does not exist as an active node.');
    });

    it('marks the node failed when host key scanning fails', function (): void {
        /** @var HostKeyScanner&object{throws:bool} $scanner */
        $scanner = app(HostKeyScanner::class);
        $scanner->throws = true;
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
        )))
            ->toThrow(function (NodeProvisioningException $exception) use ($node): void {
                expect($exception->step)
                    ->toBe('ssh-host-key')
                    ->and($exception->errorCode)
                    ->toBe('node.ssh_host_key_scan_failed')
                    ->and($node->refresh()->status)
                    ->toBe(LifecycleStatus::Failed)
                    ->and($node->failed_step)
                    ->toBe('ssh-host-key')
                    ->and($node->error_code)
                    ->toBe('node.ssh_host_key_scan_failed');
            });
    });

    it('marks the node failed when the scanned host key does not match', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'ssh_host_fingerprint' => 'SHA256:expected',
        ]);

        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
        )))
            ->toThrow(function (NodeProvisioningException $exception) use ($node): void {
                expect($exception->step)
                    ->toBe('ssh-host-key')
                    ->and($exception->errorCode)
                    ->toBe('node.ssh_host_key_mismatch')
                    ->and($node->refresh()->status)
                    ->toBe(LifecycleStatus::Failed)
                    ->and($node->public_ssh_host)
                    ->toBe('192.0.2.10');
            });
    });

    it('restores the public ssh target and lifecycle evidence when wireguard convergence fails', function (): void {
        /** @var WireGuardPeerConverger&object{throws:?Throwable} $wireGuard */
        $wireGuard = app(WireGuardPeerConverger::class);
        $wireGuard->throws = new NodeProvisioningException('wireguard', 'vpn.peer_config_failed', 'wireguard failed');
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'public_ssh_port' => 22,
            'wireguard_address' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
            publicSshPort: 2202,
        )))
            ->toThrow(function (NodeProvisioningException $exception) use ($node): void {
                expect($exception->step)
                    ->toBe('wireguard')
                    ->and($exception->errorCode)
                    ->toBe('vpn.peer_config_failed')
                    ->and($node->refresh()->status)
                    ->toBe(LifecycleStatus::Failed)
                    ->and($node->public_ssh_host)
                    ->toBe('192.0.2.10')
                    ->and($node->public_ssh_port)
                    ->toBe(22)
                    ->and($node->failed_step)
                    ->toBe('wireguard')
                    ->and($node->error_code)
                    ->toBe('vpn.peer_config_failed');
            });
    });

    it('fails when the private wireguard ssh probe fails', function (): void {
        /** @var SshExecutor&object{result:CommandResult,calls:list<array{connection:SshConnection,command:RemoteCommand}>} $ssh */
        $ssh = app(SshExecutor::class);
        $ssh->result = new CommandResult(1, '', 'wireguard ssh failed', 2, false);
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'wireguard_address' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
        )))
            ->toThrow(function (NodeProvisioningException $exception) use ($node, $ssh): void {
                expect($exception->step)
                    ->toBe('wireguard-ssh')
                    ->and($exception->errorCode)
                    ->toBe('vpn.peer_ssh_failed')
                    ->and($exception->result)
                    ->toBe($ssh->result)
                    ->and($node->refresh()->status)
                    ->toBe(LifecycleStatus::Failed)
                    ->and($node->public_ssh_host)
                    ->toBe('192.0.2.10');
            });
    });

    it('fails closed when the node has no wireguard address for the private probe', function (): void {
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
            name: 'app-dev',
            publicSshHost: '198.51.100.25',
        )))
            ->toThrow(function (NodeProvisioningException $exception) use ($node): void {
                expect($exception->step)
                    ->toBe('wireguard-address')
                    ->and($exception->errorCode)
                    ->toBe('vpn.peer_address_missing')
                    ->and($node->refresh()->status)
                    ->toBe(LifecycleStatus::Failed)
                    ->and($node->public_ssh_host)
                    ->toBe('192.0.2.10');
            });
    });

    describe('converged node', function (): void {
        beforeEach(function (): void {
            $this->node = Node::query()->create([
                'name' => 'app-dev',
                'status' => LifecycleStatus::Active,
                'platform' => 'linux',
                'architecture' => 'aarch64',
                'tld' => 'app-dev.orbit',
                'public_ssh_host' => '192.0.2.10',
                'public_ssh_port' => 22,
                'user' => 'nckrtl',
                'wireguard_address' => '10.44.0.3',
                'ssh_host_fingerprint' => 'SHA256:pinned',
            ]);
            $this->node
                ->roles()
                ->create([
                    'role' => \App\Domain\Nodes\RoleName::AppDev,
                    'status' => LifecycleStatus::Active,
                ]);
        });

        it('retargets over wireguard and never opens a public ssh connection', function (): void {
            $retargeted = app(RetargetNodeAction::class)->execute(new RetargetNodeData(
                name: 'app-dev',
                publicSshHost: '198.51.100.25',
                publicSshPort: 2202,
            ));

            /** @var HostKeyScanner&object{scans:list<array{host:string,port:int}>} $scanner */
            $scanner = app(HostKeyScanner::class);
            /** @var KnownHostsStore&object{writes:list<array{host:string,port:int,fingerprint:string}>} $knownHosts */
            $knownHosts = app(KnownHostsStore::class);
            /** @var WireGuardPeerConverger&object{node:?Node} $wireGuard */
            $wireGuard = app(WireGuardPeerConverger::class);
            /** @var SshExecutor&object{calls:list<array{connection:SshConnection,command:RemoteCommand}>} $ssh */
            $ssh = app(SshExecutor::class);

            expect($retargeted->only(['status', 'public_ssh_host', 'public_ssh_port', 'wireguard_address']))
                ->toBe([
                    'status' => LifecycleStatus::Active,
                    'public_ssh_host' => '198.51.100.25',
                    'public_ssh_port' => 2202,
                    'wireguard_address' => '10.44.0.3',
                ])
                ->and($scanner->scans)
                ->toBe([['host' => '10.44.0.3', 'port' => 22]])
                ->and($wireGuard->node)
                ->toBeNull()
                ->and($ssh->calls)
                ->toHaveCount(1)
                ->and($ssh->calls[0]['connection']->host)
                ->toBe('10.44.0.3')
                ->and($ssh->calls[0]['connection']->port)
                ->toBe(22)
                ->and($ssh->calls[0]['connection']->user)
                ->toBe('nckrtl')
                ->and($ssh->calls[0]['command']->arguments)
                ->toBe(['true'])
                ->and($knownHosts->writes)
                ->toBe([
                    ['host' => '10.44.0.3', 'port' => 22, 'fingerprint' => 'SHA256:pinned'],
                    ['host' => '198.51.100.25', 'port' => 2202, 'fingerprint' => 'SHA256:pinned'],
                ]);
        });

        it('treats any role row as a closed public ssh boundary', function (LifecycleStatus $status): void {
            $this->node->roles()->sole()->update(['status' => $status]);

            app(RetargetNodeAction::class)->execute(new RetargetNodeData(
                name: 'app-dev',
                publicSshHost: '198.51.100.25',
            ));

            /** @var HostKeyScanner&object{scans:list<array{host:string,port:int}>} $scanner */
            $scanner = app(HostKeyScanner::class);

            expect($scanner->scans)->toBe([['host' => '10.44.0.3', 'port' => 22]]);
        })->with([LifecycleStatus::Provisioning, LifecycleStatus::Failed]);

        it('fails closed without touching the record when the node is unreachable over wireguard', function (): void {
            /** @var HostKeyScanner&object{throws:bool} $scanner */
            $scanner = app(HostKeyScanner::class);
            $scanner->throws = true;

            expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
                name: 'app-dev',
                publicSshHost: '198.51.100.25',
            )))
                ->toThrow(function (NodeProvisioningException $exception): void {
                    expect($exception->step)
                        ->toBe('wireguard-ssh')
                        ->and($exception->errorCode)
                        ->toBe('node.retarget_requires_vpn')
                        ->and($exception->getMessage())
                        ->toContain('/etc/wireguard/orbit.conf')
                        ->and($this->node->refresh()->status)
                        ->toBe(LifecycleStatus::Active)
                        ->and($this->node->public_ssh_host)
                        ->toBe('192.0.2.10')
                        ->and($this->node->failed_step)
                        ->toBeNull()
                        ->and($this->node->error_code)
                        ->toBeNull();
                });
        });

        it('marks the node failed when the wireguard host key does not match', function (): void {
            $this->node->update(['ssh_host_fingerprint' => 'SHA256:expected']);

            expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
                name: 'app-dev',
                publicSshHost: '198.51.100.25',
            )))
                ->toThrow(function (NodeProvisioningException $exception): void {
                    expect($exception->step)
                        ->toBe('ssh-host-key')
                        ->and($exception->errorCode)
                        ->toBe('node.ssh_host_key_mismatch')
                        ->and($this->node->refresh()->status)
                        ->toBe(LifecycleStatus::Failed)
                        ->and($this->node->public_ssh_host)
                        ->toBe('192.0.2.10');
                });
        });

        it('restores the public ssh target when the wireguard probe fails', function (): void {
            /** @var SshExecutor&object{result:CommandResult} $ssh */
            $ssh = app(SshExecutor::class);
            $ssh->result = new CommandResult(1, '', 'wireguard ssh failed', 2, false);

            expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
                name: 'app-dev',
                publicSshHost: '198.51.100.25',
                publicSshPort: 2202,
            )))
                ->toThrow(function (NodeProvisioningException $exception): void {
                    expect($exception->step)
                        ->toBe('wireguard-ssh')
                        ->and($exception->errorCode)
                        ->toBe('vpn.peer_ssh_failed')
                        ->and($this->node->refresh()->status)
                        ->toBe(LifecycleStatus::Failed)
                        ->and($this->node->public_ssh_host)
                        ->toBe('192.0.2.10')
                        ->and($this->node->public_ssh_port)
                        ->toBe(22)
                        ->and($this->node->failed_step)
                        ->toBe('wireguard-ssh')
                        ->and($this->node->error_code)
                        ->toBe('vpn.peer_ssh_failed');
                });
        });

        it('fails closed when the node has no wireguard address', function (): void {
            $this->node->update(['wireguard_address' => null]);

            expect(fn () => app(RetargetNodeAction::class)->execute(new RetargetNodeData(
                name: 'app-dev',
                publicSshHost: '198.51.100.25',
            )))
                ->toThrow(function (NodeProvisioningException $exception): void {
                    expect($exception->step)
                        ->toBe('wireguard-address')
                        ->and($exception->errorCode)
                        ->toBe('vpn.peer_address_missing')
                        ->and($this->node->refresh()->status)
                        ->toBe(LifecycleStatus::Failed)
                        ->and($this->node->public_ssh_host)
                        ->toBe('192.0.2.10');
                });
        });
    });
});
