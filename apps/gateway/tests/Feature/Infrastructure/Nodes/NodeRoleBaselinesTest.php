<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppProd\AppProdCaddyManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Nodes\Roles\AppDevRoleBaseline;
use App\Infrastructure\Nodes\Roles\AppProdRoleBaseline;
use App\Infrastructure\Nodes\Roles\GatewayRoleBaseline;
use App\Infrastructure\Nodes\Roles\NativeRoleBaselineConverger;
use App\Infrastructure\Nodes\Roles\NodeRoleOperatingSystemGuard;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Nodes\Roles\VpnRoleBaseline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;

it('converges and removes only app development role-owned infrastructure', function (): void {
    expect(class_exists(AppDevRoleBaseline::class))->toBeTrue();

    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppDev);
    $baseline = app_dev_role_baseline($events);

    $baseline->converge($node, $assignment);
    $baseline->remove($node, $assignment, purgeData: true);

    expect($events)->toBe([
        'ssh:app-dev',
        'caddy:converge',
        'firewall:converge:app-dev',
        "dns:{$node->id}",
        'caddy:remove',
        'firewall:remove:app-dev',
        'dns:none',
    ]);
});

it('converges and removes only app production role-owned infrastructure', function (): void {
    expect(class_exists(AppProdRoleBaseline::class))->toBeTrue();

    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppProd);
    $baseline = app_prod_role_baseline($events);

    $baseline->converge($node, $assignment);
    $baseline->remove($node, $assignment, purgeData: true);

    expect($events)->toBe([
        'ssh:app-prod',
        'caddy:converge',
        'firewall:converge:app-prod',
        'caddy:remove',
        'firewall:remove:app-prod',
    ]);
});

it('keeps gateway and VPN removal protected at the baseline boundary', function (): void {
    expect(class_exists(GatewayRoleBaseline::class))
        ->toBeTrue()
        ->and(class_exists(VpnRoleBaseline::class))
        ->toBeTrue();

    $events = [];
    [$gatewayNode, $gatewayAssignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-role');
    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-role');
    $firewall = baseline_firewall($events);
    $ssh = baseline_ssh($events);
    $gateway = new GatewayRoleBaseline($firewall);
    $vpn = new VpnRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        $ssh,
        baseline_keys(),
        baseline_known_hosts(),
        $firewall,
        baseline_account_resolver(),
    );

    $gateway->converge($gatewayNode, $gatewayAssignment);
    $vpn->converge($vpnNode, $vpnAssignment);

    expect($events)->toBe(['firewall:converge:gateway', 'ssh:vpn', 'firewall:converge:vpn']);
    expect(fn () => $gateway->remove($gatewayNode, $gatewayAssignment, purgeData: false))
        ->toThrow(NodeRoleValidationException::class);
    expect(fn () => $vpn->remove($vpnNode, $vpnAssignment, purgeData: false))
        ->toThrow(NodeRoleValidationException::class);
    expect($events)->toBe(['firewall:converge:gateway', 'ssh:vpn', 'firewall:converge:vpn']);
});

it('uses the node user for VPN prerequisite SSH connections', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Vpn, 'vpn-user');
    $node->update(['user' => 'nckrtl']);

    $baseline = new VpnRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new class($events) implements SshExecutor {
            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->events[] = "ssh-user:{$connection->user}";

                return new CommandResult(0, '', '', 1, false);
            }
        },
        baseline_keys(),
        baseline_known_hosts(),
        baseline_firewall($events),
        baseline_account_resolver(),
    );

    $baseline->converge($node, $assignment);

    expect($events)->toBe(['ssh-user:nckrtl', 'firewall:converge:vpn']);
});

it('passes a nondefault managed account into every baseline prerequisite command', function (): void {
    $events = [];
    $account = new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-managed-account');
    [$appDevNode, $appDevAssignment] = role_baseline_models(RoleName::AppDev, name: 'app-dev-managed-account');
    [$appProdNode, $appProdAssignment] = role_baseline_models(RoleName::AppProd, name: 'app-prod-managed-account');
    $accounts = baseline_account_resolver($account);
    $ssh = new class($events) implements SshExecutor {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->events[] = json_encode($command->arguments, JSON_THROW_ON_ERROR);

            return new CommandResult(0, '', '', 1, false);
        }
    };

    new VpnRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        $ssh,
        baseline_keys(),
        baseline_known_hosts(),
        baseline_firewall($events),
        $accounts,
    )->converge($vpnNode, $vpnAssignment);

    new AppDevRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
        new class implements AppDevCaddyManager {
            public function converge(Node $node): void {}

            public function remove(Node $node): void {}
        },
        baseline_firewall($events),
        new class implements PrivateDnsManager {
            public function converge(?Node $pendingNode = null): void {}
        },
        $accounts,
    )->converge($appDevNode, $appDevAssignment);

    new AppProdRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppProdSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
        new class implements AppProdCaddyManager {
            public function converge(Node $node): void {}

            public function remove(Node $node): void {}
        },
        baseline_firewall($events),
        $accounts,
    )->converge($appProdNode, $appProdAssignment);

    expect($events)
        ->toContain(
            '["sudo","bash","-seu","--","vpn","nckrtl","nckrtl","\/srv\/users\/nckrtl","1","Orbit requires Ubuntu 26.04 Resolute.","resolute","dnsmasq","openssl"]',
            '["sudo","bash","-seu","--","app-dev","nckrtl","nckrtl","\/srv\/users\/nckrtl","2","Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.","noble","resolute","acl","attr","caddy","composer","docker.io","git","openssl","unzip"]',
            '["sudo","bash","-seu","--","app-prod","nckrtl","nckrtl","\/srv\/users\/nckrtl","1","Orbit requires Ubuntu 26.04 Resolute.","resolute","acl","attr","caddy","composer","docker.io","git","openssl","unzip"]',
        );
});

it('dispatches every assignment to its code-defined baseline', function (): void {
    expect(class_exists(NativeRoleBaselineConverger::class))->toBeTrue();

    $events = [];
    $firewall = baseline_firewall($events);
    $ssh = baseline_ssh($events);
    $dispatcher = new NativeRoleBaselineConverger(
        new GatewayRoleBaseline($firewall),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            $ssh,
            baseline_keys(),
            baseline_known_hosts(),
            $firewall,
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    foreach (RoleName::cases() as $role) {
        [$node, $assignment] = role_baseline_models($role, "dispatch-{$role->value}");
        $dispatcher->converge($node, $assignment);
    }

    expect($events)->toContain(
        'firewall:converge:gateway',
        'ssh:vpn',
        'ssh:app-dev',
        'ssh:app-prod',
    );
});

it('checks the remote operating system before every role convergence', function (): void {
    $events = [];
    $dispatcher = new NativeRoleBaselineConverger(
        new GatewayRoleBaseline(baseline_firewall($events)),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    foreach (RoleName::cases() as $role) {
        [$node, $assignment] = role_baseline_models($role, "guard-{$role->value}");
        $dispatcher->converge($node, $assignment);
    }

    expect($events)->toBe([
        'guard:gateway',
        'firewall:converge:gateway',
        'guard:vpn',
        'ssh:vpn',
        'firewall:converge:vpn',
        'guard:app-dev',
        'ssh:app-dev',
        'caddy:converge',
        'firewall:converge:app-dev',
        'dns:3',
        'guard:app-prod',
        'ssh:app-prod',
        'caddy:converge',
        'firewall:converge:app-prod',
    ]);
});

it('stops baseline convergence when the remote operating system guard fails', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppDev, 'guard-failure');
    $dispatcher = new NativeRoleBaselineConverger(
        new GatewayRoleBaseline(baseline_firewall($events)),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new NodeRoleOperatingSystemGuard(
            new class($events) implements SshExecutor {
                /** @param list<string> $events */
                public function __construct(
                    private array &$events,
                ) {}

                public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
                {
                    $this->events[] = 'guard:app-dev';

                    return new CommandResult(
                        1,
                        '',
                        'Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.',
                        1,
                        false,
                    );
                }
            },
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    expect(fn () => $dispatcher->converge($node, $assignment))
        ->toThrow(NodeRoleOperationException::class);
    expect($events)->toBe(['guard:app-dev']);
});

/** @param list<string> $events */
function baseline_guard_ssh(array &$events): SshExecutor
{
    return new class($events) implements SshExecutor {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $role = $command->arguments[0] === 'bash'
                ? match ($connection->host) {
                    '10.44.0.2' => 'gateway',
                    '10.44.0.3' => 'vpn',
                    '10.44.0.4' => 'app-dev',
                    '10.44.0.5' => 'app-prod',
                    default => 'unknown',
                }
                : 'unknown';
            $this->events[] = "guard:{$role}";

            return new CommandResult(0, "ID=ubuntu\nVERSION_CODENAME=resolute\n", '', 1, false);
        }
    };
}

/** @return array{Node, NodeRole} */
function role_baseline_models(RoleName $role, string $name = 'role-node'): array
{
    $address = '10.44.0.'.(Node::query()->count() + 2);
    $node = Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => $address,
    ]);
    $assignment = $node->roles()->create(['role' => $role, 'status' => 'provisioning']);

    return [$node, $assignment];
}

/** @param list<string> $events */
function app_dev_role_baseline(array &$events): AppDevRoleBaseline
{
    $caddy = new class($events) implements AppDevCaddyManager {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = 'caddy:converge';
        }

        public function remove(Node $node): void
        {
            $this->events[] = 'caddy:remove';
        }
    };
    $dns = new class($events) implements PrivateDnsManager {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(?Node $pendingNode = null): void
        {
            $this->events[] = 'dns:'.($pendingNode->id ?? 'none');
        }
    };

    return new AppDevRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
        $caddy,
        baseline_firewall($events),
        $dns,
        baseline_account_resolver(),
    );
}

/** @param list<string> $events */
function app_prod_role_baseline(array &$events): AppProdRoleBaseline
{
    $caddy = new class($events) implements AppProdCaddyManager {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = 'caddy:converge';
        }

        public function remove(Node $node): void
        {
            $this->events[] = 'caddy:remove';
        }
    };

    return new AppProdRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppProdSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
        $caddy,
        baseline_firewall($events),
        baseline_account_resolver(),
    );
}

function baseline_account_resolver(?ManagedUserAccount $account = null): ManagedUserAccountResolver
{
    return new class($account ?? new ManagedUserAccount('orbit', 'orbit', '/home/orbit')) implements
        ManagedUserAccountResolver {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
}

/** @param list<string> $events */
function baseline_firewall(array &$events): NodeRoleFirewallManager
{
    return new class($events) implements NodeRoleFirewallManager {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function convergeBase(Node $node, string $managedUser): void
        {
            $this->events[] = 'firewall:base';
        }

        public function converge(Node $node, RoleName $role, string $managedUser): void
        {
            $this->events[] = "firewall:converge:{$role->value}";
        }

        public function remove(Node $node, RoleName $role, string $managedUser): void
        {
            $this->events[] = "firewall:remove:{$role->value}";
        }
    };
}

/** @param list<string> $events */
function baseline_ssh(array &$events): SshExecutor
{
    return new class($events) implements SshExecutor {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->events[] = 'ssh:'.($command->arguments[4] ?? 'unknown');

            return new CommandResult(0, '', '', 1, false);
        }
    };
}

function baseline_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
}

function baseline_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
