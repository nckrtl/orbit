<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevCaddyPublisher;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeDevelopmentProjectionOperationLock;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevCaddyPublishHarness;
use Tests\Support\AppDevCaddyPublishScenario;
use Tests\Support\AppDevFakeProcessRunner;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FpmPublishHarness;

function app_dev_account_resolver(
    ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
): ManagedUserAccountResolver {
    return new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
}

it('converges the persistent and active app development TLD route over WireGuard', function (): void {
    $node = Node::query()->create([
        'name' => 'app-dev-route',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.7',
        'user' => 'orbit',
    ]);
    $ssh = new AppDevFakeSshExecutor;

    new RemoteAppDevTldRouteManager(app_dev_ssh($ssh))->converge($node);

    expect($ssh->connections)->toHaveCount(1);
    expect($ssh->connections[0]->host)->toBe('10.44.0.7');
    expect($ssh->commands)->toHaveCount(1);
    expect($ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', 'test']);
    expect($ssh->commands[0]->input)
        ->toContain(
            'exec 9>/run/lock/orbit-wireguard-peer.lock',
            'candidate=/etc/wireguard/orbit-candidate.conf',
            'dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate',
            'wg-quick strip "$candidate"',
            'mv -fT -- "$candidate" "$live"',
            'if [[ " ${dns_domains[*]} " = *\' . \'* ]]; then',
            'dns_domains=(".")',
            'resolvectl domain "$dns_link" "${resolvectl_domains[@]}"',
            'mv -fT -- "$dns_state_candidate" "$dns_state"',
        )
        ->not->toContain('systemctl restart wg-quick@orbit');
});

it('keeps the default resolver policy during an app development TLD convergence', function (): void {
    $node = Node::query()->create([
        'name' => 'app-dev-default-dns',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'new.test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.7',
        'user' => 'orbit',
    ]);
    $root = sys_get_temp_dir().'/orbit-app-dev-tld-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists("{$root}/wireguard", 0o700, true);
    $files->ensureDirectoryExists("{$root}/run", 0o700, true);
    $files->ensureDirectoryExists("{$root}/bin", 0o700, true);
    $files->put(
        "{$root}/wireguard/orbit.conf",
        "[Interface]\nPostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit \\~old.test\nPreDown = resolvectl dns %i ''; resolvectl domain %i ''\n",
    );
    $files->put("{$root}/wireguard/orbit.dns-link", "orbit\n10.44.0.1\n.\n");
    $files->put("{$root}/bin/chown", "#!/bin/bash\nexit 0\n");
    $files->put("{$root}/bin/wg-quick", "#!/bin/bash\n[ \"\$1\" != strip ] || /bin/cat -- \"\$2\"\n");
    $files->put(
        "{$root}/bin/resolvectl",
        "#!/bin/bash\nprintf '%s\\n' \"resolvectl \$*\" >> \"{$root}/commands.log\"\n",
    );
    foreach (['chown', 'wg-quick', 'resolvectl'] as $shim) {
        chmod("{$root}/bin/{$shim}", 0o755);
    }
    $transport = new class($root) implements SshExecutor
    {
        public function __construct(
            private readonly string $root,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $input = str_replace(
                ['/etc/wireguard', '/run/lock'],
                ["{$this->root}/wireguard", "{$this->root}/run"],
                $command->input ?? '',
            );
            $process = new Process(
                ['/bin/bash', '-seu', '--', ...array_slice($command->arguments, 4)],
                cwd: $this->root,
                env: ['PATH' => "{$this->root}/bin:/usr/bin:/bin"],
            );
            $process->setInput($input);
            $process->run();

            return new CommandResult(
                $process->getExitCode() ?? 1,
                $process->getOutput(),
                $process->getErrorOutput(),
                1,
                false,
            );
        }
    };

    try {
        new RemoteAppDevTldRouteManager(app_dev_ssh($transport))->converge($node);

        expect(file_get_contents("{$root}/wireguard/orbit.conf"))
            ->toContain('PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~.')
            ->not->toContain('\\~orbit', '\\~old.test', '\\~new.test')
            ->and(file_get_contents("{$root}/wireguard/orbit.dns-link"))
            ->toBe("orbit\n10.44.0.1\n.\n")
            ->and(file_get_contents("{$root}/commands.log"))
            ->toContain(
                'resolvectl dns orbit 10.44.0.1',
                'resolvectl domain orbit ~.',
            );
    } finally {
        $files->deleteDirectory($root);
    }
});

it('renders isolated pools and private Caddy listeners for every active AppInstance Route', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    $route = app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $sites = new AppDevSiteRepository()->forNode($node);
    $fpm = new AppDevPhpFpmConfigRenderer()->render($sites, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $caddy = new AppDevCaddyConfigRenderer()->render($sites);
    $adapted = caddy_adapt($caddy);

    expect($sites)
        ->toHaveCount(1)
        ->and($sites->sole()->scope)
        ->toBe("app-instance-{$appInstance->id}")
        ->and($fpm)
        ->toContain(
            "[orbit-app-instance-{$appInstance->id}]",
            "listen = /run/php/orbit-app-instance-{$appInstance->id}.sock",
            'listen.group = caddy',
            'env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin',
            'php_admin_value[opcache.validate_timestamps] = 1',
            'php_admin_value[opcache.revalidate_freq] = 0',
        )
        ->not->toContain(
            '[orbit-instance-1]',
            '[orbit-workspace-1]',
            'opcache.file_update_protection',
            'opcache.memory_consumption',
            'opcache.jit',
        )->and(
            $caddy,
        )->toContain(
            "https://{$route->domain}",
            'bind 0.0.0.0',
            "php_fastcgi unix//run/php/orbit-app-instance-{$appInstance->id}.sock",
            "tls /etc/caddy/orbit-certificates/app-instance-{$appInstance->id}/current/cert.pem",
        )
        ->not->toContain(
            ':80',
            'https://legacy.acme.app-dev.orbit',
            'https://feature.acme.app-dev.orbit',
            'php_fastcgi unix//run/php/orbit-instance-1.sock',
        );

    if (new ExecutableFinder()->find('caddy') !== null) {
        expect($adapted->succeeded())
            ->toBeTrue()
            ->and($adapted->stdout)
            ->toContain('0.0.0.0:443')
            ->not->toContain('127.0.0.1:443');
    }
});

it('stops rendering a Route once another Route has replaced it', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    $retired = app_dev_supported_route($appInstance, 'before.app-dev.orbit');
    $replacement = Route::query()->create([
        'app_id' => $retired->app_id,
        'node_id' => $retired->node_id,
        'domain' => 'after.app-dev.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $retired->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $retired->update(['replaced_by_route_id' => $replacement->id]);
    $replacement->targets()->create(['app_instance_id' => $appInstance->id, 'position' => 0]);
    $replacement->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    $retired->update(['status' => RouteStatus::Retiring]);

    $caddy = new AppDevCaddyConfigRenderer()->render(new AppDevSiteRepository()->forNode($node));

    // The retired domain keeps answering off the replacement's certificate otherwise, which sends
    // Caddy to automatic HTTPS for a private Orbit domain.
    expect($caddy)
        ->toContain('https://after.app-dev.orbit')
        ->not->toContain('https://before.app-dev.orbit');
});

it('hydrates only AppInstance Route sites and never reads leftover Instance or Workspace rows', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    $route = app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $unrelatedNode = Node::query()->create([
        'name' => 'unrelated-app-dev',
        'status' => LifecycleStatus::Active,
        'tld' => 'unrelated.orbit',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
    ]);
    $unrelatedApp = app_dev_supported_app_instance($unrelatedNode, $app->id, 'unrelated');
    $unrelatedRoute = app_dev_supported_route($unrelatedApp, 'unrelated.app-dev.orbit');
    $sites = new AppDevSiteRepository;
    $globalSites = $sites->all();
    $globalDns = new AppDevDnsConfigRenderer($sites)->render();

    $nodeSites = $sites->forNode($node);

    $siteIdentity = static fn (AppDevSite $site): array => [
        $site->scope,
        $site->domain,
        $site->phpVersion,
        $site->nodeAddress,
    ];
    expect($nodeSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $node->id)->values()->map($siteIdentity)->all())
        ->and($nodeSites->pluck('scope')->all())
        ->toBe(["app-instance-{$appInstance->id}"])
        ->and($nodeSites->sole()->phpVersion)
        ->toBe($appInstance->selected_php_version)
        ->and(Schema::hasTable('instances'))
        ->toBeFalse()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and($globalDns)
        ->toContain(
            "host-record={$route->domain},{$node->wireguard_ip}",
            "host-record={$unrelatedRoute->domain},{$unrelatedNode->wireguard_ip}",
        )
        ->not->toContain('acme.app-dev.orbit.legacy', 'feature.acme.app-dev.orbit');
});

it('retires previous app-dev pools before activating their lower PHP version', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $moving = app_dev_supported_app_instance($node, $app->id, 'moving');
    app_dev_supported_route($moving, 'moving.app-dev.orbit');
    $stable = app_dev_supported_app_instance($node, $app->id, 'stable');
    app_dev_supported_route($stable, 'stable.app-dev.orbit');
    $sites = new AppDevSiteRepository;
    $renderer = new AppDevPhpFpmConfigRenderer;
    $previousConfiguration = $renderer->render(
        $sites->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $moving->update(['selected_php_version' => '8.4']);
    $transitionConfiguration = $renderer->render(
        $sites->forNode($node)->where('phpVersion', '8.5')->values(),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: $sites,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    $manager->converge($node);

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($ssh->commands[0]->input)
        ->toContain('base64 --wrap=0 -- "$path"')
        ->and($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4'])
        ->and($publishCalls->first()?->input)
        ->toContain(base64_encode($transitionConfiguration))
        ->not->toContain(base64_encode($previousConfiguration));
});

it('restores the previous app-dev pools when lower PHP activation fails', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $moving = app_dev_supported_app_instance($node, $app->id, 'moving');
    app_dev_supported_route($moving, 'moving.app-dev.orbit');
    $stable = app_dev_supported_app_instance($node, $app->id, 'stable');
    app_dev_supported_route($stable, 'stable.app-dev.orbit');
    $sites = new AppDevSiteRepository;
    $renderer = new AppDevPhpFpmConfigRenderer;
    $previousConfiguration = $renderer->render(
        $sites->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $moving->update(['selected_php_version' => '8.4']);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: $sites,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4', '8.5'])
        ->and($publishCalls->last()?->input)
        ->toContain(base64_encode($previousConfiguration));
});

it('removes a newly activated app-dev pool when later PHP activation fails', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $lower = app_dev_supported_app_instance($node, $app->id, 'lower');
    app_dev_supported_route($lower, 'lower.app-dev.orbit');
    $higher = app_dev_supported_app_instance($node, $app->id, 'higher');
    app_dev_supported_route($higher, 'higher.app-dev.orbit');
    $sites = new AppDevSiteRepository;
    $renderer = new AppDevPhpFpmConfigRenderer;
    $previousConfiguration = $renderer->render(
        $sites->forNode($node),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $lower->update(['selected_php_version' => '8.4']);
    $higher->update(['selected_php_version' => '8.6']);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: $sites,
        renderer: $renderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5', '8.4', '8.6', '8.4', '8.5'])
        ->and($publishCalls->get(3)?->input)
        ->toContain("printf '%s' '' | base64 --decode")
        ->and($publishCalls->last()?->input)
        ->toContain(base64_encode($previousConfiguration));
});

it('installs selected PHP versions and validates a complete staged FPM configuration before publication', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $php84 = app_dev_supported_app_instance($node, $app->id, 'php84', '8.4');
    app_dev_supported_route($php84, 'php84.app-dev.orbit');
    $php85 = app_dev_supported_app_instance($node, $app->id, 'php85', '8.5');
    app_dev_supported_route($php85, 'php85.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\n", '', 1, false),
        new CommandResult(0, "8.4\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevPhpFpmConfigRenderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    $manager->converge($node);

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($ssh->commands)
        ->toHaveCount(6)
        ->and($ssh->commands[2]->input)
        ->toContain('apt-get -o DPkg::Lock::Timeout=300 install')
        ->and($ssh->commands[2]->arguments)
        ->toContain('php8.4-fpm', 'php8.4-pcov', 'php8.4-opcache')
        ->and($publishCalls)
        ->toHaveCount(2)
        ->and($publishCalls->first()?->arguments)
        ->toContain('/run/lock/orbit')
        ->and($publishCalls->first()?->input)
        ->toContain(
            'if [ "$lock_directory" = /run/lock/orbit ]; then',
            'test "$(stat -c %u:%g:%a -- "$lock_directory")" = 0:0:700',
            'lock="$lock_directory/orbit-php-fpm-$version.lock"',
            'flock -w 30 9',
            'cp -- "$pool" "$temporary_directory/pool.d/"',
            'sudo "php-fpm$version" -y "$temporary_directory/php-fpm.conf" -t',
            'cmp -s -- "$candidate" "$managed_configuration"',
            'cp -a -- "$managed_configuration" "$backup"',
            'sudo mv -fT -- "$staged" "$managed_configuration"',
            'if ! sudo systemctl enable "php$version-fpm" || ! sudo systemctl reload-or-restart "php$version-fpm"; then',
            'cp -a -- "$backup" "$rollback"',
            'sudo mv -fT -- "$rollback" "$managed_configuration"',
            'sudo systemctl reload-or-restart "php$version-fpm" || true',
        );

    $script = $publishCalls->first()->input ?? '';
    expect($script)
        ->toContain('exec 9>>"$lock"')
        ->not->toContain('exec 9>"$lock"', '/run/lock/orbit-php-fpm-');
    $setup = mb_strpos(haystack: $script, needle: 'umask 0077');
    $open = mb_strpos(haystack: $script, needle: 'exec 9>>"$lock"');
    $lock = mb_strpos(haystack: $script, needle: 'flock -w 30 9');
    $snapshot = mb_strpos(haystack: $script, needle: 'for pool in "$pool_directory"/*.conf');
    $validation = mb_strpos(
        haystack: $script,
        needle: 'php-fpm$version" -y "$temporary_directory/php-fpm.conf" -t',
    );
    $switch = mb_strpos(
        haystack: $script,
        needle: 'sudo mv -fT -- "$staged" "$managed_configuration"',
    );
    $activation = mb_strpos(haystack: $script, needle: 'if ! sudo systemctl enable');
    $rollback = mb_strpos(
        haystack: $script,
        needle: 'sudo mv -fT -- "$rollback" "$managed_configuration"',
    );

    expect($setup)
        ->toBeInt()
        ->toBeLessThan($open)
        ->and($open)
        ->toBeInt()
        ->toBeLessThan($lock)
        ->and($lock)
        ->toBeInt()
        ->toBeLessThan($snapshot)
        ->and($validation)
        ->toBeInt()
        ->toBeLessThan($switch)
        ->and($switch)
        ->toBeInt()
        ->toBeLessThan($activation)
        ->and($activation)
        ->toBeInt()
        ->toBeLessThan($rollback);
});

it('renders and publishes AppDev FPM pools with the nondefault managed account', function (): void {
    $account = new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
    [$node, $app] = app_dev_runtime_models(account: $account);
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $sites = new AppDevSiteRepository()->forNode($node);
    $rendered = new AppDevPhpFpmConfigRenderer()->render($sites, $account);
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevPhpFpmConfigRenderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver($account),
        packages: new RemotePhpPackageManager,
    );

    $manager->converge($node);

    $publishCall = collect($ssh->commands)
        ->first(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'));

    expect($rendered)
        ->toContain(
            'user = nckrtl',
            'group = nckrtl',
            'listen.owner = nckrtl',
            'listen.group = caddy',
        )
        ->and($publishCall)
        ->not
        ->toBeNull()
        ->and($publishCall->input ?? '')
        ->toContain(base64_encode($rendered))
        ->toContain('sudo install -o root -g root -m 0644 -- "$candidate" "$staged"')
        ->and($publishCall->arguments ?? [])
        ->toContain('8.5');
});

it('restores the exact AppDev FPM file before the recovery reload when activation fails', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $harness = new FpmPublishHarness;
    $managed = $harness->prepare('8.5', 'orbit-scopes.conf', "previous app-dev pool\n");
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "8.5\n", '', 1, false)]);

    try {
        $manager = new RemoteAppDevPhpFpmManager(
            sites: new AppDevSiteRepository,
            renderer: new AppDevPhpFpmConfigRenderer,
            ssh: app_dev_ssh($ssh),
            accounts: app_dev_account_resolver(),
            packages: new RemotePhpPackageManager,
            phpRoot: $harness->phpRoot(),
            lockDirectory: $harness->lockDirectory(),
        );
        $manager->converge($node);
        $result = $harness->run($ssh->commands[3]);

        expect($result->succeeded())
            ->toBeFalse($result->stderr)
            ->and(file_get_contents($managed))
            ->toBe("previous app-dev pool\n")
            ->and(fileperms($managed) & 0o777)
            ->toBe(0o600)
            ->and($harness->serviceCalls())
            ->toBe(
                [
                    'enable php8.5-fpm',
                    'reload-or-restart php8.5-fpm',
                    'reload-or-restart php8.5-fpm',
                ],
                $result->stderr,
            );
    } finally {
        $harness->cleanup();
    }
});

it('rejects an unsupported PHP version before target discovery or installation', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $unsupported = app_dev_supported_app_instance($node, $app->id, 'unsupported', '8.3');
    app_dev_supported_route($unsupported, 'unsupported.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppDevPhpFpmManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevPhpFpmConfigRenderer,
        ssh: app_dev_ssh($ssh),
        accounts: app_dev_account_resolver(),
        packages: new RemotePhpPackageManager,
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_version_unsupported');
        })
        ->and($ssh->commands)
        ->toBeEmpty();
});

it('keeps leaf private keys on the target while publishing a gateway-signed certificate', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    $route = app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, 'CSR FROM TARGET', '', 1, false),
    ]);
    $signer = new class implements LeafCertificateSigner
    {
        /** @var list<array{domain: string, csr: string}> */
        public array $calls = [];

        public function sign(string $domain, string $certificateRequest): string
        {
            $this->calls[] = ['domain' => $domain, 'csr' => $certificateRequest];

            return "LEAF CERTIFICATE\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT CERTIFICATE\n";
        }
    };
    $manager = new RemoteAppDevCertificateManager(app_dev_ssh($ssh), $signer, app_dev_account_resolver());

    $manager->convergeAppInstance($appInstance, $route);

    expect($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[0]->input)
        ->toContain(
            'openssl genrsa -out "$candidate/key.pem" 2048',
            'cat "$candidate/request.pem"',
            'openssl verify -CAfile "$current/root.pem" "$current/cert.pem"',
            'openssl x509 -in "$current/cert.pem" -noout -checkend 2592000',
            "grep -Eq '^(RSA )?Public-Key: \\(2048 bit\\)'",
            'not_before_epoch=$(date -d "$not_before" +%s)',
            '[ "$validity_seconds" -ge 34214400 ]',
            '[ "$validity_seconds" -le 34387200 ]',
            'sha256sum "$current/root.pem"',
            'trust_anchor=/usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
            'sudo update-ca-certificates',
        )
        ->and($ssh->commands[0]->arguments)
        ->toContain(hash(algo: 'sha256', data: "ROOT CERTIFICATE\n"))
        ->and($signer->calls)
        ->toBe([['domain' => 'acme.app-dev.orbit', 'csr' => 'CSR FROM TARGET']])
        ->and($ssh->commands[1]->input)
        ->toBe("LEAF CERTIFICATE\nROOT CERTIFICATE\n")
        ->and($ssh->commands[1]->arguments[2])
        ->toContain(
            'head -c "$certificate_length"',
            "grep -Eq '^(RSA )?Public-Key: \\(2048 bit\\)'",
            'openssl verify -CAfile',
            'test "$(sha256sum "$candidate/root.pem" | cut -d \' \' -f 1)" = "$expected_root_hash"',
            'sudo install -o root -g root -m 0644',
            'sudo install -o root -g caddy -m 0640 -- "$published/key.pem"',
            'sudo mv -fT -- "$caddy_link" "$caddy_root/current"',
            "if sudo systemctl is-active --quiet caddy; then\n    sudo systemctl reload-or-restart caddy\nfi",
        )
        ->not
        ->toContain('PRIVATE KEY')
        ->and(mb_strpos($ssh->commands[1]->arguments[2], 'sudo systemctl reload-or-restart caddy'))
        ->toBeGreaterThan((int) mb_strpos(
            $ssh->commands[1]->arguments[2],
            'sudo mv -fT -- "$caddy_link" "$caddy_root/current"',
        ));
});

it('uses a nondefault managed home for app-dev certificate converge and removal', function (): void {
    $account = new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
    [$node, $app] = app_dev_runtime_models(account: $account);
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    $route = app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, 'CSR FROM TARGET', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $signer = new class implements LeafCertificateSigner
    {
        public function sign(string $domain, string $certificateRequest): string
        {
            return "LEAF CERTIFICATE\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT CERTIFICATE\n";
        }
    };
    $manager = new RemoteAppDevCertificateManager(app_dev_ssh($ssh), $signer, app_dev_account_resolver($account));

    $manager->convergeAppInstance($appInstance, $route);
    $manager->removeAppInstance($appInstance);

    expect($ssh->commands)
        ->toHaveCount(3)
        ->and($ssh->commands[0]->arguments)
        ->toContain("app-instance-{$appInstance->id}", 'acme.app-dev.orbit', 'nckrtl', '/srv/users/nckrtl')
        ->and($ssh->commands[0]->input)
        ->toContain('managed_home=$7', 'root="$managed_home/.orbit/certificates/$scope"')
        ->not->toContain('/home/orbit/.orbit/certificates')->and($ssh->commands[2]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            "app-instance-{$appInstance->id}",
            'nckrtl',
            'nckrtl',
            '/srv/users/nckrtl',
        ])->and($ssh->commands[2]->input)->toContain(
            'managed_user=$2',
            'managed_group=$3',
            'managed_home=$4',
            'rm -rf -- "$managed_home/.orbit/certificates/$scope"',
        )
        ->not->toContain('/home/orbit/.orbit/certificates');
});

it('reuses only current app-dev leaves with the exact RSA extension policy', function (
    string $keyUsage,
    string $keyAlgorithm,
    string $expectedDecision,
): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    $route = app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "CURRENT\n", '', 1, false),
    ]);
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-app-dev-certificate-policy-'.(string) Str::uuid();
    $rootCertificate = create_app_dev_certificate_reuse_fixture(
        root: $root,
        scope: "app-instance-{$appInstance->id}",
        domain: $route->domain,
        keyUsage: $keyUsage,
        keyAlgorithm: $keyAlgorithm,
    );
    new Filesystem()->ensureDirectoryExists($root.'/usr/local/share/ca-certificates', recursive: true);
    file_put_contents($root.'/usr/local/share/ca-certificates/orbit-managed-root-ca.crt', "STALE\n");
    $signer = new class($rootCertificate) implements LeafCertificateSigner
    {
        public function __construct(
            private readonly string $rootCertificate,
        ) {}

        public function sign(string $domain, string $certificateRequest): string
        {
            return "unused\n";
        }

        public function rootCertificate(): string
        {
            return $this->rootCertificate;
        }
    };
    $manager = new RemoteAppDevCertificateManager(app_dev_ssh($ssh), $signer, app_dev_account_resolver());

    try {
        $manager->convergeAppInstance($appInstance, $route);
        $result = run_app_dev_certificate_probe_locally($ssh->commands[0], $root);
        $decision = trim($result->stdout) === 'CURRENT' ? 'reuse' : 'reissue';

        expect($result->succeeded())->toBeTrue($result->stderr);
        expect($decision)->toBe($expectedDecision);

        if ($expectedDecision === 'reuse') {
            expect(file_get_contents($root.'/usr/local/share/ca-certificates/orbit-managed-root-ca.crt'))
                ->toBe($rootCertificate)
                ->and(is_file($root.'/usr/local/share/ca-certificates/orbit-managed-root-ca.crt.updated'))
                ->toBeTrue();
        }

        if ($expectedDecision === 'reissue') {
            expect($result->stdout)
                ->toContain('BEGIN CERTIFICATE REQUEST')
                ->not->toContain("CURRENT\n");
        }
    } finally {
        $filesystem->deleteDirectory($root);
    }
})->with([
    'RSA with the approved usages' => ['digitalSignature,keyEncipherment', 'RSA', 'reuse'],
    'RSA without key encipherment' => ['digitalSignature', 'RSA', 'reissue'],
    'Ed25519 with the approved usages' => ['digitalSignature,keyEncipherment', 'ED25519', 'reissue'],
]);

it('publishes private Caddy and DNS configurations through complete preserved validation aggregates', function (): void {
    [$node, $app] = app_dev_runtime_models();
    $appInstance = app_dev_supported_app_instance($node, $app->id);
    app_dev_supported_route($appInstance, 'acme.app-dev.orbit');
    $feature = app_dev_supported_app_instance($node, $app->id, 'feature');
    app_dev_supported_route($feature, 'feature.acme.app-dev.orbit');
    $ssh = new AppDevFakeSshExecutor;
    $caddyRenderer = new AppDevCaddyConfigRenderer;
    $caddy = new RemoteAppDevCaddyManager(
        sites: new AppDevSiteRepository,
        renderer: $caddyRenderer,
        ssh: app_dev_ssh($ssh),
    );
    $processes = new AppDevFakeProcessRunner;
    $dns = new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer(new AppDevSiteRepository));

    $caddy->converge($node);
    $dns->converge();

    $expectedCaddy = $caddyRenderer->render(new AppDevSiteRepository()->forNode($node));

    expect($ssh->commands[0]->input)
        ->toContain(
            base64_encode($expectedCaddy),
            'exec 9>>"$lock"',
            'flock -w 30 9',
            'source_main=$(readlink -f "$live_caddyfile")',
            'previous_fragments=$(dirname "$source_main")/fragments',
            'destination="$candidate/fragments/$fragment_name"',
            'destination="$candidate/fragments/00-unmanaged.caddy"',
            'cp --preserve=mode,ownership -- "$fragment" "$destination"',
            'app-dev.caddy',
            "printf 'import %s/%s/fragments/*.caddy\n' \"\$versions\" \"\$version\"",
            'caddy validate --config "$candidate/Caddyfile"',
            'ensure_hibernation_ancestor "$hibernation_markers"',
            'install -d -o root -g caddy -m 0755 -- "$hibernation_markers"',
            'ensure_hibernation_ancestor "$hibernation_logs"',
            'install -d -o root -g caddy -m 2775 -- "$hibernation_logs"',
            'install -d -m 0755 -- "$current"',
            'cmp -s -- "$candidate/fragments/app-dev.caddy" "$previous_fragments/app-dev.caddy"',
            'mv -fT -- "$candidate_link" "$live_caddyfile"',
            'if ! systemctl enable "$caddy_service" || ! systemctl reload-or-restart "$caddy_service"; then',
            'mv -fT -- "$rollback_link" "$live_caddyfile"',
            'cp -a -- "$previous_main" "$rollback_file"',
            'mv -fT -- "$rollback_file" "$live_caddyfile"',
        )
        ->and(array_slice(array: $ssh->commands[0]->arguments, offset: 0, length: 3))
        ->toBe(['sudo', 'bash', '-seu'])
        ->and($ssh->commands[0]->arguments)
        ->toContain(
            '/run/lock/orbit/caddy.lock',
            '/dev/shm/orbit/hibernation',
            '/data/caddy/orbit/hibernation',
        );

    $lockSetup = mb_strpos(haystack: $ssh->commands[0]->input, needle: 'lock_directory=$(dirname "$lock")');
    $lockOpen = mb_strpos(haystack: $ssh->commands[0]->input, needle: 'exec 9>>"$lock"');

    expect($lockSetup)
        ->toBeInt()
        ->toBeLessThan($lockOpen)
        ->and($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->input)
        ->toContain(
            base64_encode(
                "# Managed by Orbit.\naddress=/.app-dev.orbit/10.44.0.3\nhost-record=acme.app-dev.orbit,10.44.0.3\nhost-record=feature.acme.app-dev.orbit,10.44.0.3\nhost-record=gateway.orbit,10.44.0.1\nlocal=/app-dev.orbit/\n",
            ),
            'exec 9>/run/lock/orbit-dnsmasq.lock',
            'flock -w 30 9',
            'cp -a -- /etc/dnsmasq.d/. "$validation/fragments/"',
            'sed "s#/etc/dnsmasq.d#$validation/fragments#g" /etc/dnsmasq.conf',
            'dnsmasq --test --conf-file="$validation/dnsmasq.conf"',
            'cmp -s -- "$validation/fragments/orbit-records.conf" "$managed"',
            'if systemctl is-active --quiet dnsmasq; then',
            'mv -fT -- "$candidate" "$managed"',
            'systemctl restart dnsmasq',
            'catalog_managed=/var/lib/orbit/private-dns/catalog.json',
            'python3 -c \'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))\'',
        );
});

it('retains exact DNS records for active and pending Routes on different Routers', function (): void {
    $firstRoute = orb173_dns_projection_route('first', '10.44.0.31', '10.44.0.32', RouteStatus::Active);
    $secondRoute = orb173_dns_projection_route('second', '10.44.0.41', '10.44.0.42', RouteStatus::Pending);
    $processes = new AppDevFakeProcessRunner;
    $renderer = new AppDevDnsConfigRenderer(new AppDevSiteRepository);
    $manager = new DnsmasqPrivateDnsManager($processes, $renderer);

    $manager->convergeRoute($secondRoute);

    $configuration = $renderer->render(pendingRoute: $secondRoute);

    expect($configuration)
        ->toContain(
            "host-record={$firstRoute->domain},10.44.0.32",
            "host-record={$secondRoute->domain},10.44.0.42",
        )
        ->and($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->input)
        ->toContain(base64_encode($configuration));
});

it('projects only the explicit provisioning node before its active transition', function (): void {
    $pending = Node::query()->create([
        'name' => 'pending-app-dev',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'tld' => 'pending.orbit',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
    ]);
    $pending->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Provisioning]);
    Node::query()->create([
        'name' => 'other-pending-app-dev',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'tld' => 'other-pending.orbit',
        'public_ssh_host' => '192.0.2.31',
        'wireguard_ip' => '10.44.0.31',
    ]);
    $processes = new AppDevFakeProcessRunner;
    $manager = new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer(new AppDevSiteRepository));

    $manager->converge($pending);

    expect($processes->invocations[0]->input)
        ->toContain(base64_encode(
            "# Managed by Orbit.\naddress=/.pending.orbit/10.44.0.30\nlocal=/pending.orbit/\n",
        ))
        ->not->toContain(
            base64_encode('address=/.other-pending.orbit/10.44.0.31'),
            'other-pending.orbit',
        );
});

it('projects node wildcards only while the app-dev role is provisioning or active', function (): void {
    foreach ([
        LifecycleStatus::Provisioning,
        LifecycleStatus::Active,
        LifecycleStatus::Removing,
        LifecycleStatus::Failed,
    ] as $index => $status) {
        $node = Node::query()->create([
            'name' => "dns-role-{$status->value}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'tld' => "role-{$status->value}.orbit",
            'public_ssh_host' => '192.0.2.'.(40 + $index),
            'wireguard_ip' => '10.44.0.'.(40 + $index),
        ]);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => $status]);
    }
    $processes = new AppDevFakeProcessRunner;

    new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer(new AppDevSiteRepository))->converge();

    $input = $processes->invocations[0]->input ?? '';
    preg_match("/printf '%s' '([^']+)'/", $input, $matches);
    $configuration = base64_decode($matches[1] ?? '', strict: true);

    expect($configuration)
        ->toContain('role-provisioning.orbit', 'role-active.orbit')
        ->not->toContain('role-removing.orbit', 'role-failed.orbit');
});

it('holds the shared projection lock while capturing and publishing DNS intent', function (): void {
    app_dev_runtime_models();
    $orbitHome = sys_get_temp_dir().'/orbit-dns-lock-'.Str::uuid();
    config()->set('orbit.home', $orbitHome);
    $processes = new class($orbitHome) implements ProcessRunner
    {
        public bool $observedLock = false;

        public function __construct(
            private readonly string $orbitHome,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $lock = fopen($this->orbitHome.'/.dnsmasq-projections.lock', mode: 'c+');

            if ($lock === false) {
                throw new RuntimeException('Could not inspect the DNS projection lock.');
            }

            $acquired = flock($lock, LOCK_EX | LOCK_NB);
            $this->observedLock = ! $acquired;

            if ($acquired) {
                flock($lock, LOCK_UN);
            }

            fclose($lock);

            return new CommandResult(0, '', '', 1, false);
        }
    };

    try {
        new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer(new AppDevSiteRepository))->converge();

        expect($processes->observedLock)->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps one reentrant projection owner until outer completion and releases it after failure', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-development-projection-'.Str::uuid();
    $now = 0.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $contenderDeadline = new CommandDeadline($clock);
    $contenderDeadline->start(0.02);
    $owner = new NativeDevelopmentProjectionOperationLock($orbitHome, new CommandDeadline($clock), $clock);
    $contender = new NativeDevelopmentProjectionOperationLock(
        $orbitHome,
        $contenderDeadline,
        $clock,
        static function (int $microseconds) use (&$now): void {
            $now += $microseconds / 1_000_000;
        },
    );
    $events = [];

    try {
        $owner->run(function () use ($owner, $contender, &$events): void {
            $events[] = 'outer-enter';
            $owner->run(function () use (&$events): void {
                $events[] = 'inner-enter';
            });
            $events[] = 'inner-return';

            expect(fn () => $contender->run(static fn (): string => 'contended'))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('app-dev.projection_busy')
                        ->and($exception->status)
                        ->toBe(409);
                });
        });

        expect(fn () => $owner->run(static fn () => throw new RuntimeException('outer failure')))
            ->toThrow(RuntimeException::class, 'outer failure');
        $released = new NativeDevelopmentProjectionOperationLock(
            $orbitHome,
            new CommandDeadline($clock),
            $clock,
        );

        expect($events)
            ->toBe(['outer-enter', 'inner-enter', 'inner-return'])
            ->and($released->run(static fn (): string => 'released'))
            ->toBe('released')
            ->and(fileperms($orbitHome) & 0o777)
            ->toBe(0o700)
            ->and(fileperms($orbitHome.'/.dnsmasq-projections.lock') & 0o777)
            ->toBe(0o600);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('binds one native projection owner for each application request scope', function (): void {
    $first = app(DevelopmentProjectionOperationLock::class);
    $sameScope = app(DevelopmentProjectionOperationLock::class);

    app()->forgetScopedInstances();

    $nextScope = app(DevelopmentProjectionOperationLock::class);

    expect($first)
        ->toBeInstanceOf(NativeDevelopmentProjectionOperationLock::class)
        ->toBe($sameScope)
        ->not->toBe($nextScope);
});

it('waits within the command deadline and continues after the current owner releases', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-development-projection-wait-'.Str::uuid();
    mkdir($orbitHome, permissions: 0o700, recursive: true);
    $path = $orbitHome.'/.dnsmasq-projections.lock';
    $held = fopen($path, mode: 'c+');
    expect($held)->not->toBeFalse();
    flock($held, LOCK_EX);
    $now = 10.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $deadline = new CommandDeadline($clock);
    $deadline->start(0.025);
    $waits = 0;
    $owner = new NativeDevelopmentProjectionOperationLock(
        $orbitHome,
        $deadline,
        $clock,
        static function (int $microseconds) use (&$now, &$waits, &$held): void {
            $now += $microseconds / 1_000_000;
            $waits++;

            if ($waits === 2 && is_resource($held)) {
                flock($held, LOCK_UN);
                fclose($held);
                $held = null;
            }
        },
    );

    try {
        expect($owner->run(static fn (): string => 'fresh'))
            ->toBe('fresh')
            ->and($waits)
            ->toBe(2)
            ->and(round($now, 3))
            ->toBe(10.02);
    } finally {
        if (is_resource($held)) {
            flock($held, LOCK_UN);
            fclose($held);
        }

        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('enters projection ownership before app-dev Caddy and DNS host publication', function (): void {
    [$node] = app_dev_runtime_models();
    $owner = new AppDevProjectionOwnerSpy;
    $ssh = new AppDevFakeSshExecutor;
    $caddy = new RemoteAppDevCaddyManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevCaddyConfigRenderer,
        ssh: app_dev_ssh($ssh),
        projection: $owner,
    );
    $processes = new class($owner) implements ProcessRunner
    {
        public function __construct(
            private readonly AppDevProjectionOwnerSpy $owner,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            expect($this->owner->active)->toBeTrue();

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $dns = new DnsmasqPrivateDnsManager(
        $processes,
        new AppDevDnsConfigRenderer(new AppDevSiteRepository),
        $owner,
    );

    $caddy->converge($node);
    $caddy->remove($node);
    $dns->converge();

    expect($owner->runs)
        ->toBe(3)
        ->and($owner->active)
        ->toBeFalse()
        ->and($ssh->commands)
        ->toHaveCount(3);
});

it('retires only the exact package-default caddyfile while preserving modified config and orbit fragments', function (): void {
    $harness = new AppDevCaddyPublishHarness;

    try {
        $defaultResult = $harness->run(
            publisher: zero_site_publisher($harness),
            scenario: AppDevCaddyPublishScenario::packageDefault("package default\n", "package default\n"),
        );

        expect($defaultResult->exitCode)
            ->toBe(0)
            ->and($defaultResult->publishedFragments)
            ->toHaveKey('app-dev.caddy')
            ->not
            ->toHaveKey('unmanaged.caddy')
            ->and($defaultResult->liveMainAfter)
            ->toBe("{\n    auto_https disable_certs\n}\nimport ".$harness->etcCaddyPath('orbit-versions/test-version/fragments/*.caddy')."\n");
        expect(fileperms($harness->etcCaddyPath('orbit-locks')) & 0o777)->toBe(0o700);
        expect(fileperms($harness->etcCaddyPath('orbit-locks/caddy.lock')) & 0o777)->toBe(0o600);

        $orbitResult = $harness->run(
            publisher: zero_site_publisher($harness),
            scenario: AppDevCaddyPublishScenario::orbitAggregate("import fragments/*.caddy\n", [
                'unmanaged.caddy' => "{\n    local_certs\n}\n",
                'custom.caddy' => "custom handler\n",
                'app-dev.caddy' => "stale app-dev\n",
            ]),
        );

        expect($orbitResult->exitCode)
            ->toBe(0)
            ->and($orbitResult->publishedFragments)
            ->toHaveKey('app-dev.caddy')
            ->toHaveKey('00-unmanaged.caddy')
            ->toHaveKey('custom.caddy')
            ->not
            ->toHaveKey('unmanaged.caddy')
            ->and($orbitResult->publishedFragments['custom.caddy'])
            ->toBe("custom handler\n")
            ->and($orbitResult->publishedFragments['00-unmanaged.caddy'])
            ->toBe("{\n    local_certs\n}\n")
            ->and($orbitResult->publishedFragments['app-dev.caddy'])
            ->toBe("# Managed by Orbit.\n");

        $modifiedResult = $harness->run(
            publisher: zero_site_publisher($harness),
            scenario: AppDevCaddyPublishScenario::modifiedConfig("modified config\n", "package default\n"),
        );

        expect($modifiedResult->exitCode)
            ->toBe(0)
            ->and($modifiedResult->publishedFragments)
            ->toHaveKey('00-unmanaged.caddy')
            ->not
            ->toHaveKey('unmanaged.caddy')
            ->and($modifiedResult->publishedFragments['00-unmanaged.caddy'])
            ->toBe("modified config\n");
    } finally {
        $harness->cleanup();
    }
});

it('leaves the live caddy aggregate unchanged when staged validation fails during zero-site publication', function (): void {
    $harness = new AppDevCaddyPublishHarness;

    try {
        $result = $harness->run(
            publisher: zero_site_publisher($harness),
            scenario: AppDevCaddyPublishScenario::modifiedConfigWithValidationFailure(
                "modified config\n",
                "package default\n",
            ),
        );

        expect($result->exitCode)
            ->not
            ->toBe(0)
            ->and($result->liveMainAfter)
            ->toBe("modified config\n")
            ->and($result->publishedFragments)
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('fails closed when both unmanaged Caddy fragment names already exist', function (): void {
    $harness = new AppDevCaddyPublishHarness;

    try {
        $result = $harness->run(
            publisher: zero_site_publisher($harness),
            scenario: AppDevCaddyPublishScenario::orbitAggregate("import fragments/*.caddy\n", [
                'unmanaged.caddy' => "legacy\n",
                '00-unmanaged.caddy' => "current\n",
                'custom.caddy' => "custom\n",
            ]),
        );

        expect($result->exitCode)
            ->not
            ->toBe(0)
            ->and($result->liveMainAfter)
            ->toBe("import fragments/*.caddy\n")
            ->and($result->publishedFragments)
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('restores the exact regular Caddyfile before the recovery reload when activation fails', function (): void {
    $harness = new AppDevCaddyPublishHarness;

    try {
        $result = $harness->run(
            publisher: zero_site_publisher($harness),
            scenario: AppDevCaddyPublishScenario::modifiedConfigWithActivationFailure(
                "modified config\n",
                "package default\n",
            ),
        );

        expect($result->exitCode)
            ->not
            ->toBe(0)
            ->and($result->liveMainAfter)
            ->toBe("modified config\n")
            ->and($result->liveLinkTargetAfter)
            ->toBeNull()
            ->and($result->publishedFragments)
            ->toBeEmpty()
            ->and($result->serviceCalls)
            ->toBe([
                'enable caddy',
                'reload-or-restart caddy',
                'reload-or-restart caddy',
            ]);
    } finally {
        $harness->cleanup();
    }
});

it('orders the app-dev Caddy unit after the managed WireGuard interface', function (): void {
    [$node] = app_dev_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppDevCaddyManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevCaddyConfigRenderer,
        ssh: app_dev_ssh($ssh),
    );

    $manager->converge($node);

    expect($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[1]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', 'caddy', '/etc/systemd/system'])
        ->and($ssh->commands[1]->input)
        ->toContain(
            'managed=$directory/orbit-vpn.conf',
            'install -d -o root -g root -m 0755 -- "$directory"',
            'if [ -f "$managed" ] && cmp -s -- "$staged" "$managed"; then',
            'if systemctl is-active --quiet "$service"; then',
            'install -o root -g root -m 0644 -- "$staged" "$candidate"',
            'mv -fT -- "$candidate" "$managed"',
            'systemctl daemon-reload',
            'systemctl restart "$service"',
        )
        ->and(base64_decode(
            Str::match('/\x27([A-Za-z0-9+\/=]+)\x27 \| base64 --decode/', $ssh->commands[1]->input ?? ''),
            strict: true,
        ))
        ->toBe("# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n");
});

it('keeps the live Caddy aggregate untouched when candidate validation fails', function (): void {
    [$node] = app_dev_runtime_models();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(1, '', 'invalid candidate', 1, false),
    ]);
    $manager = new RemoteAppDevCaddyManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevCaddyConfigRenderer,
        ssh: app_dev_ssh($ssh),
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.caddy_config_failed');
        });

    $command = $ssh->commands[0];
    $script = $command->input ?? '';
    $validation = mb_strpos(haystack: $script, needle: 'caddy validate --config "$candidate/Caddyfile"');
    $liveSwitch = mb_strpos(
        haystack: $script,
        needle: 'mv -fT -- "$candidate_link" "$live_caddyfile"',
    );

    expect($validation)
        ->toBeInt()
        ->and($liveSwitch)
        ->toBeInt()
        ->and($validation)
        ->toBeLessThan($liveSwitch)
        ->and($script)
        ->toContain(
            '/run/lock/orbit/caddy.lock',
            'umask 0077',
            'lock_directory=$(dirname "$lock")',
            'if ! mkdir -- "$lock_directory" 2>/dev/null; then',
            'test "$(stat -c %u:%g:%a -- "$lock_directory")" = 0:0:700',
            'test ! -L "$lock_directory"',
            'test ! -L "$lock"',
            'test "$(stat -c %u:%g -- "$lock")" = 0:0',
            'chmod 0600 -- "$lock"',
            'test "$(stat -c %a -- "$lock")" = 600',
            'rm -rf -- "$candidate"',
            'rm -f -- "$candidate_link" "$rollback_link" "$rollback_file" "$previous_main"',
        );
});

it('keeps the live DNS fragment untouched when effective validation fails', function (): void {
    app_dev_runtime_models();
    $processes = new AppDevFakeProcessRunner;
    $processes->fail = true;
    $manager = new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer(new AppDevSiteRepository));

    expect($manager->converge(...))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.dns_config_failed');
        });

    $script = $processes->invocations[0]->input ?? '';
    $validation = mb_strpos(
        haystack: $script,
        needle: 'dnsmasq --test --conf-file="$validation/dnsmasq.conf"',
    );
    $liveSwitch = mb_strpos(
        haystack: $script,
        needle: 'mv -fT -- "$candidate" "$managed"',
    );
    $restart = mb_strrpos(haystack: $script, needle: 'systemctl restart dnsmasq');

    expect($validation)
        ->toBeInt()
        ->and($liveSwitch)
        ->toBeInt()
        ->and($restart)
        ->toBeInt()
        ->and($validation)
        ->toBeLessThan($liveSwitch)
        ->and($liveSwitch)
        ->toBeLessThan($restart)
        ->and($script)
        ->toContain(
            'exec 9>/run/lock/orbit-dnsmasq.lock',
            'flock -w 30 9',
            'cmp -s -- "$validation/fragments/orbit-records.conf" "$managed"',
            'if ! systemctl restart dnsmasq; then',
            'install -o root -g root -m 0644 -- "$backup" "$managed"',
            'rm -f -- "$managed"',
            'systemctl restart dnsmasq || true',
            'trap \'rm -rf -- "$validation"; rm -f -- "$candidate" "$backup" "$catalog_candidate" "$catalog_backup"\' EXIT',
        );
});

/** @return array{Node, OrbitApp} */
function app_dev_runtime_models(
    ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
): array {
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'tld' => 'app-dev.orbit',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => $account->user,
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@github.com:acme/site.git',
    ]);
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    return [$node, $app];
}

function app_dev_supported_app_instance(
    Node $node,
    int $appId,
    string $name = 'default',
    string $phpVersion = '8.5',
): AppInstance {
    return AppInstance::query()->create([
        'app_id' => $appId,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/home/orbit/apps/acme/{$name}",
        'selected_php_version' => $phpVersion,
        'root' => 'public',
        'status' => AppInstanceState::Active,
    ]);
}

function app_dev_supported_route(AppInstance $appInstance, string $domain): Route
{
    $route = Route::query()->create([
        'app_id' => $appInstance->app_id,
        'node_id' => $appInstance->node_id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $appInstance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function orb173_dns_projection_route(
    string $name,
    string $workloadAddress,
    string $routerAddress,
    RouteStatus $status,
): Route {
    $cluster = Cluster::query()->create([
        'name' => "{$name}-cluster",
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => "{$name}-workload",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => "{$name}.test",
        'public_ssh_host' => "{$name}-workload.test",
        'wireguard_ip' => $workloadAddress,
        'user' => 'orbit',
    ]);
    $workload
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => "{$name}-router",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}-router.test",
        'wireguard_ip' => $routerAddress,
        'user' => 'orbit',
    ]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    $app = OrbitApp::query()->create([
        'name' => ucfirst($name),
        'slug' => $name,
        'repository_url' => "https://example.test/{$name}.git",
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'default',
        'checkout_path' => "/home/orbit/apps/{$name}",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat($name === 'first' ? 'a' : 'b', 40),
        'selected_php_version' => '8.5',
        'status' => $status === RouteStatus::Active
            ? AppInstanceState::Active
            : AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => "{$name}.app.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route
        ->targets()
        ->create([
            'app_instance_id' => $instance->id,
            'position' => 0,
        ]);

    if ($status === RouteStatus::Active) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return $route;
}

function run_app_dev_certificate_probe_locally(RemoteCommand $command, string $root): CommandResult
{
    $arguments = array_map(
        static fn (string $argument): string => str_replace(
            ['/home/orbit', '/etc/caddy'],
            [$root, $root.'/etc/caddy'],
            $argument,
        ),
        $command->arguments,
    );
    $input = str_replace(
        [
            '/home/orbit',
            '/etc/caddy',
            '/usr/local/share/ca-certificates',
            '/etc/ssl/certs/ca-certificates.crt',
            'sudo update-ca-certificates',
            'install -o root -g root ',
            'sudo ',
            'openssl ',
            'date -d "$not_before" +%s',
            'date -d "$not_after" +%s',
        ],
        [
            $root,
            $root.'/etc/caddy',
            $root.'/usr/local/share/ca-certificates',
            $root.'/usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
            '{ printf "Updating certificates\\n"; touch "$trust_anchor.updated"; }',
            'install ',
            '',
            app_dev_test_openssl_binary().' ',
            PHP_OS_FAMILY === 'Darwin'
                ? 'date -j -f "%b %e %T %Y %Z" "$not_before" +%s'
                : 'date -d "$not_before" +%s',
            PHP_OS_FAMILY === 'Darwin'
                ? 'date -j -f "%b %e %T %Y %Z" "$not_after" +%s'
                : 'date -d "$not_after" +%s',
        ],
        $command->input ?? '',
    );

    return new NativeProcessRunner()->run(new ProcessInvocation(
        arguments: $arguments,
        input: $input,
    ));
}

function create_app_dev_certificate_reuse_fixture(
    string $root,
    string $scope,
    string $domain,
    string $keyUsage,
    string $keyAlgorithm,
): string {
    $processes = new NativeProcessRunner;
    $ca = "{$root}/ca";
    $current = "{$root}/.orbit/certificates/{$scope}/current";
    $caddyCurrent = "{$root}/etc/caddy/orbit-certificates/{$scope}/current";
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($ca, mode: 0o700, recursive: true);
    $filesystem->makeDirectory($current, mode: 0o700, recursive: true);
    $filesystem->makeDirectory($caddyCurrent, mode: 0o700, recursive: true);

    $openssl = app_dev_test_openssl_binary();
    $leafKeyArguments = [$openssl, 'genpkey', '-algorithm', $keyAlgorithm];

    if ($keyAlgorithm === 'RSA') {
        $leafKeyArguments = [...$leafKeyArguments, '-pkeyopt', 'rsa_keygen_bits:2048'];
    }

    $leafKeyArguments = [...$leafKeyArguments, '-out', "{$current}/key.pem"];
    $commands = [
        [$openssl, 'genrsa', '-out', "{$ca}/root.key", '4096'],
        [
            $openssl,
            'req',
            '-x509',
            '-new',
            '-key',
            "{$ca}/root.key",
            '-out',
            "{$ca}/root.pem",
            '-days',
            '3650',
            '-subj',
            '/CN=Orbit Test Root',
            '-addext',
            'basicConstraints=critical,CA:TRUE',
            '-addext',
            'keyUsage=critical,keyCertSign,cRLSign',
        ],
        $leafKeyArguments,
        [
            $openssl,
            'req',
            '-new',
            '-key',
            "{$current}/key.pem",
            '-out',
            "{$current}/request.pem",
            '-subj',
            "/CN={$domain}",
        ],
    ];

    foreach ($commands as $arguments) {
        $result = $processes->run(new ProcessInvocation($arguments));
        expect($result->succeeded())->toBeTrue($result->stderr);
    }

    file_put_contents("{$current}/leaf.ext", implode("\n", [
        'basicConstraints=critical,CA:FALSE',
        "keyUsage=critical,{$keyUsage}",
        'extendedKeyUsage=serverAuth',
        "subjectAltName=DNS:{$domain}",
    ]));
    $signed = $processes->run(new ProcessInvocation([
        $openssl,
        'x509',
        '-req',
        '-in',
        "{$current}/request.pem",
        '-CA',
        "{$ca}/root.pem",
        '-CAkey',
        "{$ca}/root.key",
        '-set_serial',
        '0x01',
        '-out',
        "{$current}/cert.pem",
        '-days',
        '397',
        '-extfile',
        "{$current}/leaf.ext",
    ]));
    expect($signed->succeeded())->toBeTrue($signed->stderr);
    $rootCertificate = file_get_contents("{$ca}/root.pem");
    expect($rootCertificate)->toBeString();
    file_put_contents("{$current}/root.pem", $rootCertificate);
    copy("{$current}/key.pem", "{$caddyCurrent}/key.pem");
    copy("{$current}/cert.pem", "{$caddyCurrent}/cert.pem");

    return is_string($rootCertificate) ? $rootCertificate : '';
}

function app_dev_test_openssl_binary(): string
{
    return is_executable('/opt/homebrew/bin/openssl') ? '/opt/homebrew/bin/openssl' : 'openssl';
}

it('removes only the app development Caddy fragment through an atomic preserved aggregate', function (): void {
    expect(method_exists(AppDevCaddyPublisher::class, 'removeCommand'))->toBeTrue();

    [$node] = app_dev_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppDevCaddyManager(
        sites: new AppDevSiteRepository,
        renderer: new AppDevCaddyConfigRenderer,
        ssh: app_dev_ssh($ssh),
    );

    $manager->remove($node);

    $command = $ssh->commands[0];
    $script = $command->input ?? '';

    expect($script)
        ->toContain(
            '/run/lock/orbit/caddy.lock',
            'umask 0077',
            'lock_directory=$(dirname "$lock")',
            'if ! mkdir -- "$lock_directory" 2>/dev/null; then',
            'test "$(stat -c %u:%g:%a -- "$lock_directory")" = 0:0:700',
            'test ! -L "$lock_directory"',
            'test ! -L "$lock"',
            'test "$(stat -c %u:%g -- "$lock")" = 0:0',
            'chmod 0600 -- "$lock"',
            'test "$(stat -c %a -- "$lock")" = 600',
            'exec 9>>"$lock"',
            'flock -w 30 9',
            'source_main=$(readlink -f "$live_caddyfile")',
            'test ! -f "$current_fragments/app-dev.caddy"',
            'destination="$candidate/fragments/$fragment_name"',
            'destination="$candidate/fragments/00-unmanaged.caddy"',
            'cp --preserve=mode,ownership -- "$fragment" "$destination"',
            'caddy validate --config "$candidate/Caddyfile" --adapter caddyfile',
            'mv -fT -- "$candidate_link" "$live_caddyfile"',
            'mv -fT -- "$rollback_link" "$live_caddyfile"',
        )
        ->not->toContain(
            'exec 9>"$lock"',
            'apt-get remove',
            'apt-get purge',
            'rm -rf -- /home/orbit',
            'rm -rf -- "$current_fragments"',
        );

    $setup = mb_strpos(haystack: $script, needle: 'lock_directory=$(dirname "$lock")');
    $open = mb_strpos(haystack: $script, needle: 'exec 9>>"$lock"');
    expect($setup)->toBeInt()->toBeLessThan($open);
});

it('removes an app development fragment from a direct Caddyfile and restores that file on activation failure', function (): void {
    $success = run_app_dev_direct_caddy_removal(failActivation: false);

    expect($success['exitCode'])
        ->toBe(0, $success['stderr'])
        ->and($success['liveIsLink'])
        ->toBeTrue()
        ->and($success['publishedFragments'])
        ->toBe(['custom.caddy' => "custom handler\n"]);

    $failure = run_app_dev_direct_caddy_removal(failActivation: true);

    expect($failure['exitCode'])
        ->not
        ->toBe(0)
        ->and($failure['liveIsLink'])
        ->toBeFalse()
        ->and($failure['liveMain'])
        ->toBe("import fragments/*.caddy\n")
        ->and($failure['publishedFragments'])
        ->toBeEmpty()
        ->and($failure['serviceCalls'])
        ->toBe(['reload-or-restart caddy', 'reload-or-restart caddy']);
});

/**
 * @return array{exitCode: int, stderr: string, liveIsLink: bool, liveMain: string, publishedFragments: array<string, string>, serviceCalls: list<string>}
 */
function run_app_dev_direct_caddy_removal(bool $failActivation): array
{
    $root = sys_get_temp_dir().'/orbit-caddy-remove-'.bin2hex(random_bytes(8));
    $etc = $root.'/etc/caddy';
    $bin = $root.'/bin';
    $files = new Filesystem;
    $files->ensureDirectoryExists(path: $etc.'/fragments', mode: 0o777, recursive: true);
    $files->ensureDirectoryExists(path: $bin, mode: 0o777, recursive: true);
    file_put_contents(filename: $etc.'/Caddyfile', data: "import fragments/*.caddy\n");
    file_put_contents(filename: $etc.'/fragments/app-dev.caddy', data: "owned\n");
    file_put_contents(filename: $etc.'/fragments/custom.caddy', data: "custom handler\n");
    file_put_contents(
        filename: $bin.'/install',
        data: "#!/bin/bash\nargs=(); skip=0; for arg in \"\$@\"; do if [ \"\$skip\" = 1 ]; then skip=0; continue; fi; case \"\$arg\" in -o|-g) skip=1;; *) args+=(\"\$arg\");; esac; done; exec /usr/bin/install \"\${args[@]}\"\n",
    );
    file_put_contents(filename: $bin.'/chown', data: "#!/bin/bash\nexit 0\n");
    file_put_contents(filename: $bin.'/caddy', data: "#!/bin/bash\ntest \"\${HARNESS_CADDY_RUNTIME_USER:-}\" = caddy\n");
    file_put_contents(
        filename: $bin.'/runuser',
        data: <<<'BASH'
            #!/bin/bash
            set -euo pipefail
            test "$1" = -u
            test "$2" = caddy
            test "$3" = --
            shift 3
            export HARNESS_CADDY_RUNTIME_USER=caddy
            exec "$@"
            BASH,
    );
    file_put_contents(
        filename: $bin.'/systemctl',
        data: "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> \"\$HARNESS_SERVICE_LOG\"\nif [ \"\$HARNESS_FAIL_ACTIVATION\" = 1 ] && [ ! -e \"\$HARNESS_FAILED\" ]; then touch \"\$HARNESS_FAILED\"; exit 1; fi\nexit 0\n",
    );

    foreach (['install', 'chown', 'caddy', 'runuser', 'systemctl'] as $shim) {
        chmod(filename: $bin.'/'.$shim, permissions: 0o755);
    }

    $caddyPublisher = new AppDevCaddyPublisher(
        $etc.'/orbit-versions',
        $etc.'/Caddyfile',
        'caddy',
        $etc.'/orbit-locks/caddy.lock',
    );
    $command = $caddyPublisher->removeCommand('remove-version');
    $process = new Process(
        command: array_slice(array: $command->arguments, offset: 1),
        cwd: $root,
        env: [
            'PATH' => $bin.':'.getenv('PATH'),
            'HARNESS_SERVICE_LOG' => $root.'/service.log',
            'HARNESS_FAIL_ACTIVATION' => $failActivation ? '1' : '0',
            'HARNESS_FAILED' => $root.'/failed',
        ],
    );
    $process->setInput($command->input);
    $process->run();
    $published = [];

    $publishedDirectory = $etc.'/orbit-versions/remove-version/fragments';

    if (is_dir($publishedDirectory)) {
        foreach ($files->files($publishedDirectory) as $file) {
            $published[$file->getFilename()] = (string) file_get_contents($file->getPathname());
        }
    }

    $liveMain = file_get_contents($etc.'/Caddyfile');
    $serviceLog = is_file($root.'/service.log') ? (string) file_get_contents($root.'/service.log') : '';
    $result = [
        'exitCode' => $process->getExitCode() ?? 1,
        'stderr' => $process->getErrorOutput(),
        'liveIsLink' => is_link($etc.'/Caddyfile'),
        'liveMain' => $liveMain === false ? '' : $liveMain,
        'publishedFragments' => $published,
        'serviceCalls' => array_values(array_filter(explode("\n", trim($serviceLog)))),
    ];
    $files->deleteDirectory($root);

    return $result;
}

function zero_site_publisher(AppDevCaddyPublishHarness $harness): AppDevCaddyPublisher
{
    return new AppDevCaddyPublisher(
        versionsDirectory: $harness->etcCaddyPath('orbit-versions'),
        liveCaddyfilePath: $harness->etcCaddyPath('Caddyfile'),
        caddyServiceName: 'caddy',
        lockPath: $harness->etcCaddyPath('orbit-locks/caddy.lock'),
        hibernationMarkerDirectory: $harness->etcCaddyPath('hibernation-markers'),
        hibernationAccessLogDirectory: $harness->etcCaddyPath('hibernation-logs'),
    );
}

final class AppDevProjectionOwnerSpy implements DevelopmentProjectionOperationLock
{
    public int $runs = 0;

    public bool $active = false;

    public function run(Closure $operation): mixed
    {
        $this->runs++;
        $this->active = true;

        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}

function app_dev_ssh(SshExecutor $ssh): AppDevSshExecutor
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/home/orbit/.orbit/ssh/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/home/orbit/.orbit/ssh/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new AppDevSshExecutor($ssh, $keys, $knownHosts);
}
