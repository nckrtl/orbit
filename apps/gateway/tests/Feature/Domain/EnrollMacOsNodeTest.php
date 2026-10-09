<?php

declare(strict_types=1);

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeToolManagerMaterializer;

beforeEach(function (): void {
    $this->ssh = new EnrollmentSsh;
    $this->scanner = new EnrollmentScanner;
    $this->knownHosts = new EnrollmentKnownHosts;
    $this->agent = new FakeNodeAgentRuntime;
    $this->tools = new FakeToolManagerMaterializer;
    $this->linux = new class implements NodeConverger
    {
        public int $calls = 0;

        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): NodeObservation {
            $this->calls++;

            throw new RuntimeException('Linux convergence must not start.');
        }
    };
    $this->firewall = new class implements NodeRoleFirewallManager
    {
        public int $calls = 0;

        public function convergeBase(Node $node, string $managedUser): void
        {
            $this->calls++;
        }

        public function converge(Node $node, RoleName $role, string $managedUser): void
        {
            $this->calls++;
        }

        public function remove(Node $node, RoleName $role, string $managedUser): void
        {
            $this->calls++;
        }

        public function trustWireGuardMembers(Node $node, string $managedUser): void
        {
            $this->calls++;
        }

        public function restorePublicSsh(Node $node, string $managedUser): void
        {
            $this->calls++;
        }
    };
    $this->metrics = new class implements MetricsFleetReconciler
    {
        public int $calls = 0;

        public function reconcile(): void
        {
            $this->calls++;
        }

        public function retire(Node $node): void
        {
            $this->calls++;
        }
    };
    app()->instance(SshExecutor::class, $this->ssh);
    app()->instance(HostKeyScanner::class, $this->scanner);
    app()->instance(SshKeyProvider::class, new EnrollmentKeys);
    app()->instance(KnownHostsStore::class, $this->knownHosts);
    app()->instance(NodeConverger::class, $this->linux);
    app()->instance(NodeAgentRuntime::class, $this->agent);
    app()->instance(ToolManagerMaterializer::class, $this->tools);
    app()->instance(NodeRoleFirewallManager::class, $this->firewall);
    app()->instance(MetricsFleetReconciler::class, $this->metrics);
});

it('enrolls a new mac without bootstrap, allocation, or linux services', function (): void {
    $node = enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.40',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    ));

    expect($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->platform)->toBe('macos')
        ->and($node->architecture)->toBe('arm64')
        ->and($node->user)->toBe('mini')
        ->and($node->wireguard_ip)->toBe('10.44.0.40')
        ->and($node->wireguard_public_key)->toBeNull()
        ->and($node->ssh_host_fingerprint)->toBe('SHA256:pinned')
        ->and($node->roles)->toHaveCount(0)
        ->and($this->linux->calls)->toBe(0)
        ->and($this->agent->nodeIds)->toBe([])
        ->and($this->tools->requests)->toBe([])
        ->and($this->firewall->calls)->toBe(0)
        ->and($this->metrics->calls)->toBe(0)
        ->and($this->knownHosts->puts)->toBe([
            ['192.0.2.40', 22, 'SHA256:pinned'],
            ['10.44.0.40', 22, 'SHA256:pinned'],
        ])
        ->and(Node::query()->whereNotNull('wireguard_ip')->pluck('wireguard_ip')->all())->toBe(['10.44.0.40']);
});

it('corrects an unpinned roleless peer and preserves its id, access, and tunnel', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => 'GATEWAY_KEY',
        'ssh_host_fingerprint' => 'SHA256:gateway',
    ]);
    $peer = Node::query()->create([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.40',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.40',
        'wireguard_public_key' => 'MINI_KEY',
        'wireguard_endpoint_override' => '192.0.2.40:51820',
        'dns_server_override' => '10.44.0.1',
    ]);
    $gateway->accessibleNodes()->attach($peer);
    $peer->accessibleNodes()->attach($gateway);
    $id = $peer->id;

    $node = enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    ));

    expect($node->id)->toBe($id)
        ->and($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->platform)->toBe('macos')
        ->and($node->architecture)->toBe('arm64')
        ->and($node->user)->toBe('mini')
        ->and($node->wireguard_ip)->toBe('10.44.0.40')
        ->and($node->wireguard_public_key)->toBe('MINI_KEY')
        ->and($node->wireguard_endpoint_override)->toBe('192.0.2.40:51820')
        ->and($node->dns_server_override)->toBe('10.44.0.1')
        ->and($node->ssh_host_fingerprint)->toBe('SHA256:pinned')
        ->and($gateway->fresh()->accessibleNodes()->pluck('nodes.id')->all())->toBe([$id])
        ->and($node->accessibleNodes()->pluck('nodes.id')->all())->toBe([$gateway->id])
        ->and($this->linux->calls)->toBe(0);
});

it('enrolls the same managed mac again without rewriting its identity', function (): void {
    $first = enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.40',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    ));
    $first->update(['wireguard_public_key' => 'MINI_KEY']);

    $second = enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        platform: 'macos',
        platformProvided: true,
    ));

    expect($second->id)->toBe($first->id)
        ->and($second->platform)->toBe('macos')
        ->and($second->architecture)->toBe('arm64')
        ->and($second->user)->toBe('mini')
        ->and($second->wireguard_ip)->toBe('10.44.0.40')
        ->and($second->wireguard_public_key)->toBe('MINI_KEY')
        ->and($second->ssh_host_fingerprint)->toBe('SHA256:pinned')
        ->and($second->status)->toBe(LifecycleStatus::Active);
});

it('refuses silent conversion of an already managed node', function (): void {
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.20',
        'wireguard_public_key' => 'BEAST_KEY',
        'ssh_host_fingerprint' => 'SHA256:beast',
    ]);

    expect(fn () => enroll_mac(new ProvisionNodeData(
        name: 'beast',
        publicSshHost: '192.0.2.20',
        user: 'mini',
        orbitUser: 'mini',
        platform: 'macos',
        platformProvided: true,
    )))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('node.platform_unsupported')->and($exception->status)->toBe(422);
    });

    expect($node->fresh()->platform)->toBe('linux')
        ->and($node->fresh()->user)->toBe('orbit')
        ->and($node->fresh()->ssh_host_fingerprint)->toBe('SHA256:beast')
        ->and($this->scanner->scans)->toBe(0)
        ->and($this->linux->calls)->toBe(0);
});

it('refuses another account, a role, and a tunnel change before ssh', function (ProvisionNodeData $data, string $code, int $status): void {
    Node::query()->create([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'public_ssh_host' => '192.0.2.40',
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.40',
        'wireguard_public_key' => 'MINI_KEY',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);

    expect(fn () => enroll_mac($data))
        ->toThrow(function (ResourceOperationException $exception) use ($code, $status): void {
            expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe($status);
        });

    expect(Node::query()->where('name', 'mini')->sole()->user)->toBe('mini')
        ->and($this->scanner->scans)->toBe(0);
})->with([
    'account change' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', user: 'other', orbitUser: 'other', platform: 'macos', platformProvided: true),
        'node.user_change_unsupported',
        409,
    ],
    'role' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', roles: [RoleName::AppDev], user: 'mini', orbitUser: 'mini', platform: 'macos', platformProvided: true),
        'node.platform_unsupported',
        422,
    ],
    'endpoint' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', user: 'mini', orbitUser: 'mini', wireguardEndpointOverride: '192.0.2.40:51820', platform: 'macos', platformProvided: true),
        'node.platform_unsupported',
        422,
    ],
    'address replacement' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', user: 'mini', orbitUser: 'mini', wireguardIp: '10.44.0.41', platform: 'macos', platformProvided: true),
        'node.wireguard_required',
        409,
    ],
]);

it('refuses a new mac enrollment that omits the account, mixes accounts, or omits the address', function (ProvisionNodeData $data, string $code, int $status): void {
    expect(fn () => enroll_mac($data))
        ->toThrow(function (ResourceOperationException $exception) use ($code, $status): void {
            expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe($status);
        });

    expect(Node::query()->where('name', 'mini')->exists())->toBeFalse()
        ->and($this->scanner->scans)->toBe(0);
})->with([
    'missing account' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', wireguardIp: '10.44.0.40', expectedSshHostFingerprint: 'SHA256:pinned', platform: 'macos', platformProvided: true),
        'node.macos_account_required',
        422,
    ],
    'different accounts' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', user: 'mini', orbitUser: 'other', wireguardIp: '10.44.0.40', expectedSshHostFingerprint: 'SHA256:pinned', platform: 'macos', platformProvided: true),
        'node.macos_account_mismatch',
        422,
    ],
    'missing address' => [
        new ProvisionNodeData(name: 'mini', publicSshHost: '192.0.2.40', user: 'mini', orbitUser: 'mini', expectedSshHostFingerprint: 'SHA256:pinned', platform: 'macos', platformProvided: true),
        'node.wireguard_required',
        409,
    ],
]);

it('leaves a new mac failed and unpinned when the account or platform check fails', function (string $kind, string $step, string $code): void {
    if ($kind === 'account') {
        $this->ssh->accountOk = false;
    }
    if ($kind === 'platform') {
        $this->ssh->platform = 'Linux';
    }

    try {
        enroll_mac(new ProvisionNodeData(
            name: 'mini',
            publicSshHost: '192.0.2.40',
            user: 'mini',
            orbitUser: 'mini',
            wireguardIp: '10.44.0.40',
            expectedSshHostFingerprint: 'SHA256:pinned',
            platform: 'macos',
            platformProvided: true,
        ));
        throw new RuntimeException('macOS enrollment should have failed.');
    } catch (NodeProvisioningException|ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
    }

    $node = Node::query()->where('name', 'mini')->sole();

    expect($node->status)->toBe(LifecycleStatus::Failed)
        ->and($node->failed_step)->toBe($step)
        ->and($node->error_code)->toBe($code)
        ->and($node->platform)->toBe('macos')
        ->and($node->architecture)->toBeNull()
        ->and($node->ssh_host_fingerprint)->toBeNull()
        ->and($node->user)->toBe('mini')
        ->and($this->agent->nodeIds)->toBe([])
        ->and($this->linux->calls)->toBe(0);
})->with([
    'account' => ['account', 'account', 'node.account_unavailable'],
    'platform' => ['platform', 'machine-architecture', 'node.platform_mismatch'],
]);

it('keeps an active mac unchanged when a later check disagrees', function (): void {
    $node = enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.40',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    ));
    $this->ssh->architecture = 'x86_64';

    expect(fn () => enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        platform: 'macos',
        platformProvided: true,
    )))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)
            ->toBe('node.architecture_mismatch')
            ->and($exception->status)->toBe(409)
            ->and($exception->details['step'] ?? null)->toBe('machine-architecture');
    });

    expect($node->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($node->fresh()->architecture)->toBe('arm64')
        ->and($node->fresh()->platform)->toBe('macos')
        ->and($node->fresh()->user)->toBe('mini')
        ->and($node->fresh()->ssh_host_fingerprint)->toBe('SHA256:pinned');
});

it('leaves a managed mac unchanged when known-host storage fails', function (): void {
    enroll_mac(new ProvisionNodeData(
        name: 'fresh',
        publicSshHost: '192.0.2.41',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.41',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    ));

    $this->knownHosts->fail = true;

    expect(fn () => enroll_mac(new ProvisionNodeData(
        name: 'fresh',
        publicSshHost: '192.0.2.41',
        user: 'mini',
        orbitUser: 'mini',
        platform: 'macos',
        platformProvided: true,
    )))->toThrow(function (NodeProvisioningException $exception): void {
        expect($exception->step)->toBe('ssh-pin');
    });

    $restored = Node::query()->where('name', 'fresh')->sole();

    expect($restored->status)->toBe(LifecycleStatus::Active)
        ->and($restored->ssh_host_fingerprint)->toBe('SHA256:pinned')
        ->and($restored->architecture)->toBe('arm64')
        ->and($restored->user)->toBe('mini');
});

it('marks a new mac failed without a registry pin when known-host storage fails', function (): void {
    $this->knownHosts->fail = true;

    expect(fn () => enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.40',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    )))->toThrow(NodeProvisioningException::class);

    $node = Node::query()->where('name', 'mini')->sole();

    expect($node->status)->toBe(LifecycleStatus::Failed)
        ->and($node->failed_step)->toBe('ssh-pin')
        ->and($node->ssh_host_fingerprint)->toBeNull()
        ->and($node->platform)->toBe('macos')
        ->and($node->architecture)->toBe('arm64')
        ->and($node->wireguard_ip)->toBe('10.44.0.40')
        ->and($this->linux->calls)->toBe(0);
});

it('does not store an active pin when the registry write fails after known hosts, and a retry recovers', function (): void {
    $failPinWrite = true;
    Node::saving(function (Node $node) use (&$failPinWrite): void {
        if (! $failPinWrite || $node->status !== LifecycleStatus::Active || $node->ssh_host_fingerprint === null) {
            return;
        }

        $failPinWrite = false;

        throw new RuntimeException('registry pin failed');
    });
    $data = new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.40',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        platformProvided: true,
    );

    expect(fn () => enroll_mac($data))->toThrow(RuntimeException::class);

    expect(Node::query()->where('status', LifecycleStatus::Active)->whereNotNull('ssh_host_fingerprint')->exists())->toBeFalse()
        ->and($this->knownHosts->puts)->toBe([
            ['192.0.2.40', 22, 'SHA256:pinned'],
            ['10.44.0.40', 22, 'SHA256:pinned'],
        ]);

    $node = enroll_mac($data);

    expect($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->ssh_host_fingerprint)->toBe('SHA256:pinned')
        ->and($this->knownHosts->puts)->toContain(['192.0.2.40', 22, 'SHA256:pinned'])
        ->and($this->knownHosts->puts)->toContain(['10.44.0.40', 22, 'SHA256:pinned']);
});

it('does not rewrite arm64 when the machine reports it', function (): void {
    $node = enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        wireguardIp: '10.44.0.40',
        expectedSshHostFingerprint: 'SHA256:pinned',
        platform: 'macos',
        architecture: 'arm64',
        platformProvided: true,
    ));

    expect($node->architecture)->toBe('arm64');

    $this->ssh->architecture = 'arm64';

    expect(fn () => enroll_mac(new ProvisionNodeData(
        name: 'mini',
        publicSshHost: '192.0.2.40',
        user: 'mini',
        orbitUser: 'mini',
        architecture: 'aarch64',
        platform: 'macos',
        platformProvided: true,
    )))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('node.architecture_mismatch');
    });

    expect(Node::query()->where('name', 'mini')->sole()->architecture)->toBe('arm64');
});

function enroll_mac(ProvisionNodeData $data): Node
{
    return app(ProvisionNodeAction::class)->execute($data);
}

final class EnrollmentKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/orbit-gateway-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 GATEWAY';
    }
}

final class EnrollmentScanner implements HostKeyScanner
{
    public int $scans = 0;

    public string $fingerprint = 'SHA256:pinned';

    public function scan(string $host, int $port, ?SshConnection $via = null): HostKey
    {
        $this->scans++;

        return new HostKey('ssh-ed25519', 'PUBLICKEY', $this->fingerprint);
    }
}

final class EnrollmentKnownHosts implements KnownHostsStore
{
    /** @var list<array{0: string, 1: int, 2: string}> */
    public array $puts = [];

    public bool $fail = false;

    public function path(): string
    {
        return '/tmp/orbit-test-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void
    {
        if ($this->fail) {
            throw new RuntimeException('known hosts failed');
        }

        $this->puts[] = [$host, $port, $key->fingerprint];
    }
}

final class EnrollmentSsh implements SshExecutor
{
    public string $platform = 'Darwin';

    public string $architecture = 'arm64';

    public bool $accountOk = true;

    public bool $addressPresent = true;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($command->arguments[0] === 'true') {
            return new CommandResult($this->accountOk ? 0 : 255, '', '', 1, false);
        }

        return new CommandResult(
            0,
            $this->platform."\n".$this->architecture."\nok\n".($this->addressPresent ? '1' : '0')."\n",
            '',
            1,
            false,
        );
    }
}
