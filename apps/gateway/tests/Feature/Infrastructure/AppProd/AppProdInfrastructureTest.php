<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppProd\AppProdPhpFpmConfigRenderer;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Infrastructure\AppProd\RemoteAppProdPhpFpmManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Str;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FpmPublishHarness;

it('renders no leftover production Instance sites for PHP-FPM', function (): void {
    $node = app_prod_runtime_models();
    $sites = new AppProdSiteRepository()->forNode($node);

    $fpm = new AppProdPhpFpmConfigRenderer()->render($sites);

    expect($sites)
        ->toBeEmpty()
        ->and($fpm)
        ->not->toContain(
            '[orbit-prod-instance-1]',
            'https://orbit.nckrtl.com',
            'php_fastcgi unix//run/php/orbit-prod-instance-1.sock',
        );
});

it('retires leftover app-prod pools without activating leftover Instance PHP versions', function (): void {
    $node = app_prod_runtime_models();
    $leftoverConfiguration = "[orbit-prod-instance-1]\n";
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($leftoverConfiguration)."\n", '', 1, false),
    ]);
    $manager = new RemoteAppProdPhpFpmManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdPhpFpmConfigRenderer,
        ssh: app_prod_ssh($ssh),
    );

    $manager->converge($node);

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($ssh->commands[0]->input)
        ->toContain('base64 --wrap=0 -- "$path"')
        ->and($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5'])
        ->and($publishCalls->first()?->input)
        ->toContain("printf '%s' '' | base64 --decode")
        ->not
        ->toContain(base64_encode($leftoverConfiguration));
});

it('fails closed when leftover app-prod retirement publication fails', function (): void {
    $node = app_prod_runtime_models();
    $previousConfiguration = "[orbit-prod-instance-1]\n";
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode($previousConfiguration)."\n", '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppProdPhpFpmManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdPhpFpmConfigRenderer,
        ssh: app_prod_ssh($ssh),
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->first()?->arguments)
        ->toContain('/run/lock/orbit')
        ->and($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.5'])
        ->and($publishCalls->first()?->input)
        ->toContain("printf '%s' '' | base64 --decode")
        ->not
        ->toContain(base64_encode($previousConfiguration));
});

it('restores earlier leftover app-prod retirements when a later leftover version fails', function (): void {
    $node = app_prod_runtime_models();
    $previousFour = "[orbit-prod-instance-1]\n";
    $previousFive = "[orbit-prod-instance-2]\n";
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.4\t".base64_encode($previousFour)."\n8.5\t".base64_encode($previousFive)."\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'activation failed', 1, false),
    ]);
    $manager = new RemoteAppProdPhpFpmManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdPhpFpmConfigRenderer,
        ssh: app_prod_ssh($ssh),
    );

    expect(fn () => $manager->converge($node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_fpm_config_failed');
        });

    $publishCalls = collect($ssh->commands)
        ->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'))
        ->values();

    expect($publishCalls->map(static fn (RemoteCommand $command): string => $command->arguments[4])->all())
        ->toBe(['8.4', '8.5', '8.4'])
        ->and($publishCalls->get(0)?->input)
        ->toContain("printf '%s' '' | base64 --decode")
        ->and($publishCalls->get(1)?->input)
        ->toContain("printf '%s' '' | base64 --decode")
        ->and($publishCalls->last()?->input)
        ->toContain(base64_encode($previousFour));
});

it('validates aggregate FPM candidates and restores the managed pool after leftover retirement failure', function (): void {
    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "8.5\t".base64_encode("[orbit-prod-instance-1]\n")."\n", '', 1, false),
    ]);
    $manager = new RemoteAppProdPhpFpmManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdPhpFpmConfigRenderer,
        ssh: app_prod_ssh($ssh),
    );

    $manager->converge($node);

    expect($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[1]->input)
        ->toContain(
            'if [ "$lock_directory" = /run/lock/orbit ]; then',
            'test "$(stat -c %u:%g:%a -- "$lock_directory")" = 0:0:700',
            'lock="$lock_directory/orbit-php-fpm-$version.lock"',
            'flock -w 30 9',
            'cp -- "$pool" "$temporary_directory/pool.d/"',
            'php-fpm$version" -y "$temporary_directory/php-fpm.conf" -t',
            'cmp -s -- "$candidate" "$managed_configuration"',
            'cp -a -- "$managed_configuration" "$backup"',
            'if ! sudo systemctl enable "php$version-fpm" || ! sudo systemctl reload-or-restart "php$version-fpm"; then',
            'cp -a -- "$backup" "$rollback"',
            'sudo mv -fT -- "$rollback" "$managed_configuration"',
            'sudo systemctl reload-or-restart "php$version-fpm" || true',
        );

    $script = $ssh->commands[1]->input ?? '';
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

it('does not install PHP packages for leftover production Instances', function (): void {
    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppProdPhpFpmManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdPhpFpmConfigRenderer,
        ssh: app_prod_ssh($ssh),
    );

    $manager->converge($node);

    expect($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->input)
        ->toContain('orbit-prod-scopes.conf')
        ->and(collect($ssh->commands)->map(static fn (RemoteCommand $command): string => $command->input ?? '')->implode("\n"))
        ->not
        ->toContain('apt-get -o DPkg::Lock::Timeout=300 install', 'php8.5-fpm');
});

it('restores the exact AppProd FPM file before the recovery reload when activation fails', function (): void {
    $node = app_prod_runtime_models();
    $harness = new FpmPublishHarness;
    $managed = $harness->prepare('8.5', 'orbit-prod-scopes.conf', "previous app-prod pool\n");
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, "8.5\n", '', 1, false)]);

    try {
        $manager = new RemoteAppProdPhpFpmManager(
            sites: new AppProdSiteRepository,
            renderer: new AppProdPhpFpmConfigRenderer,
            ssh: app_prod_ssh($ssh),
            phpRoot: $harness->phpRoot(),
            lockDirectory: $harness->lockDirectory(),
            logDirectory: $harness->logDirectory(),
        );
        $manager->converge($node);
        $publish = collect($ssh->commands)
            ->first(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'php-fpm.conf'));

        expect($publish)->toBeInstanceOf(RemoteCommand::class);

        $result = $harness->run($publish);

        expect($result->succeeded())
            ->toBeFalse($result->stderr)
            ->and(file_get_contents($managed))
            ->toBe("previous app-prod pool\n")
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

it('requests a Node Caddy build and writes no Caddy fragment', function (): void {
    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $builds = new FakeNodeCaddyBuilds;
    $manager = new RemoteAppProdCaddyManager(builds: $builds, ssh: app_prod_ssh($ssh));

    $manager->converge($node);
    $manager->remove($node);

    expect($builds->built)->toBe([$node->name, $node->name])
        ->and($ssh->commands)->toHaveCount(1)
        ->and(implode("\n", [...$ssh->commands[0]->arguments, (string) $ssh->commands[0]->input]))
        ->not->toContain('orbit-versions', 'app-prod.caddy', '/etc/caddy/Caddyfile');
});

it('keeps its error code and records the build stage and Caddy message when the build fails', function (): void {
    $node = app_prod_runtime_models();
    $builds = new FakeNodeCaddyBuilds;
    $builds->failNext($node->name, 'reload', 'Caddy did not reload the new version; the previous configuration is live again.');
    $manager = new RemoteAppProdCaddyManager(builds: $builds, ssh: app_prod_ssh(new AppDevFakeSshExecutor));

    expect(fn () => $manager->remove($node))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($node): void {
            expect($exception->errorCode)->toBe('app-prod.caddy_config_failed')
                ->and($exception->step)->toBe('app-prod-caddy-config')
                ->and($exception->getMessage())->toBe("The Caddy build for Node [{$node->name}] failed at stage [reload]: Caddy did not reload the new version; the previous configuration is live again.");
        });
});

it('orders the app-prod Caddy unit after the managed WireGuard interface', function (): void {
    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $builds = new FakeNodeCaddyBuilds;
    $builds->onBuild = fn () => expect($ssh->commands)->toHaveCount(1);
    $manager = new RemoteAppProdCaddyManager(builds: $builds, ssh: app_prod_ssh($ssh));

    $manager->converge($node);

    expect($builds->built)->toBe([$node->name])
        ->and($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', 'caddy', '/etc/systemd/system'])
        ->and($ssh->commands[0]->input)
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
            Str::match('/\x27([A-Za-z0-9+\/=]+)\x27 \| base64 --decode/', $ssh->commands[0]->input ?? ''),
            strict: true,
        ))
        ->toBe("# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n");
});

function app_prod_runtime_models(): Node
{
    return Node::query()->create([
        'name' => 'app-prod',
        'platform' => 'linux',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.5',
        'user' => 'nckrtl',
    ]);
}

function app_prod_ssh(AppDevFakeSshExecutor $ssh): AppProdSshExecutor
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

    return new AppProdSshExecutor($ssh, $keys, $knownHosts);
}
