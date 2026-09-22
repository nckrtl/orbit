<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppProd\AppProdCaddyConfigRenderer;
use App\Infrastructure\AppProd\AppProdCaddyPublisher;
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
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevCaddyPublishHarness;
use Tests\Support\AppDevCaddyPublishScenario;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FpmPublishHarness;

it('renders no leftover production Instance sites for Caddy or PHP-FPM', function (): void {
    $node = app_prod_runtime_models();
    $sites = new AppProdSiteRepository()->forNode($node);

    $fpm = new AppProdPhpFpmConfigRenderer()->render($sites);
    $caddy = new AppProdCaddyConfigRenderer()->render($sites);

    expect($sites)
        ->toBeEmpty()
        ->and($fpm)
        ->not->toContain(
            '[orbit-prod-instance-1]',
            'https://orbit.nckrtl.com',
            'php_fastcgi unix//run/php/orbit-prod-instance-1.sock',
        )
        ->and($caddy)
        ->not->toContain(
            'https://orbit.nckrtl.com',
            'root * /var/www/acme/main/public',
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

it('publishes one Orbit-owned Caddy fragment and restores the prior aggregate after reload failure', function (): void {
    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppProdCaddyManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdCaddyConfigRenderer,
        ssh: app_prod_ssh($ssh),
    );

    $manager->converge($node);

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
            'app-prod.caddy',
            'unmanaged.caddy',
            'exec 9>>"$lock"',
            'flock -w 30 9',
            'caddy validate --config "$candidate/Caddyfile" --adapter caddyfile',
            'cmp -s -- "$candidate/fragments/app-prod.caddy" "$current_fragments/app-prod.caddy"',
            'mv -fT -- "$candidate_link" "$live_caddyfile"',
            'if ! systemctl enable "$caddy_service" || ! systemctl reload-or-restart "$caddy_service"; then',
            'mv -fT -- "$rollback_link" "$live_caddyfile"',
            'cp -a -- "$previous_main" "$rollback_file"',
            'mv -fT -- "$rollback_file" "$live_caddyfile"',
            'systemctl reload-or-restart "$caddy_service" || true',
        )
        ->not
        ->toContain('rm -rf -- "$live_caddyfile"')
        ->and($ssh->commands[0]->arguments)
        ->toContain('/run/lock/orbit/caddy.lock');

    $lockSetup = mb_strpos(haystack: $script, needle: 'lock_directory=$(dirname "$lock")');
    $lockOpen = mb_strpos(haystack: $script, needle: 'exec 9>>"$lock"');

    expect($lockSetup)
        ->toBeInt()
        ->toBeLessThan($lockOpen);

    $lock = mb_strpos(haystack: $script, needle: 'flock -w 30 9');
    $snapshot = mb_strpos(haystack: $script, needle: 'source_main=$(readlink -f "$live_caddyfile")');
    $validation = mb_strpos(haystack: $script, needle: 'caddy validate --config "$candidate/Caddyfile"');
    $switch = mb_strpos(haystack: $script, needle: 'mv -fT -- "$candidate_link" "$live_caddyfile"');
    $activation = mb_strpos(haystack: $script, needle: 'if ! systemctl enable');
    $rollback = mb_strpos(haystack: $script, needle: 'mv -fT -- "$rollback_link" "$live_caddyfile"');

    expect($lock)
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

it('orders the app-prod Caddy unit after the managed WireGuard interface', function (): void {
    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppProdCaddyManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdCaddyConfigRenderer,
        ssh: app_prod_ssh($ssh),
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

it('normalizes the unmanaged production fragment before app production configuration', function (): void {
    $harness = new AppDevCaddyPublishHarness;

    try {
        $publisher = new AppProdCaddyPublisher(
            versionsDirectory: $harness->etcCaddyPath('orbit-versions'),
            liveCaddyfilePath: $harness->etcCaddyPath('Caddyfile'),
            caddyServiceName: 'caddy',
            lockPath: $harness->etcCaddyPath('orbit-locks/caddy.lock'),
        );
        $result = $harness->run(
            publisher: $publisher,
            scenario: AppDevCaddyPublishScenario::orbitAggregate("import fragments/*.caddy\n", [
                'unmanaged.caddy' => "{\n    local_certs\n}\n",
                'app-prod.caddy' => "stale production\n",
            ]),
        );

        expect($result->exitCode)
            ->toBe(0)
            ->and($result->publishedFragments)
            ->toHaveKey('00-unmanaged.caddy')
            ->not->toHaveKey('unmanaged.caddy');
    } finally {
        $harness->cleanup();
    }
});

it('fails closed when both unmanaged Caddy fragment names already exist', function (): void {
    $harness = new AppDevCaddyPublishHarness;

    try {
        $publisher = new AppProdCaddyPublisher(
            versionsDirectory: $harness->etcCaddyPath('orbit-versions'),
            liveCaddyfilePath: $harness->etcCaddyPath('Caddyfile'),
            caddyServiceName: 'caddy',
            lockPath: $harness->etcCaddyPath('orbit-locks/caddy.lock'),
        );
        $result = $harness->run(
            publisher: $publisher,
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

it('restores the exact Caddy symlink before the recovery reload when activation fails', function (): void {
    $harness = new AppDevCaddyPublishHarness;
    $previousTarget = $harness->etcCaddyPath('orbit-versions/current/Caddyfile');

    try {
        $publisher = new AppProdCaddyPublisher(
            versionsDirectory: $harness->etcCaddyPath('orbit-versions'),
            liveCaddyfilePath: $harness->etcCaddyPath('Caddyfile'),
            caddyServiceName: 'caddy',
            lockPath: $harness->etcCaddyPath('orbit-locks/caddy.lock'),
        );
        $result = $harness->run(
            publisher: $publisher,
            scenario: AppDevCaddyPublishScenario::orbitAggregateWithActivationFailure(
                "import fragments/*.caddy\n",
                [
                    'custom.caddy' => "custom handler\n",
                    'app-prod.caddy' => "stale production\n",
                ],
            ),
        );

        expect($result->exitCode)
            ->not
            ->toBe(0)
            ->and($result->liveMainAfter)
            ->toBe("import fragments/*.caddy\n")
            ->and(fileperms($harness->etcCaddyPath('orbit-locks')) & 0o777)
            ->toBe(0o700)
            ->and(fileperms($harness->etcCaddyPath('orbit-locks/caddy.lock')) & 0o777)
            ->toBe(0o600)
            ->and($result->liveLinkTargetAfter)
            ->toBe($previousTarget)
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

it('removes only the app production Caddy fragment through an atomic preserved aggregate', function (): void {
    expect(method_exists(AppProdCaddyPublisher::class, 'removeCommand'))->toBeTrue();

    $node = app_prod_runtime_models();
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteAppProdCaddyManager(
        sites: new AppProdSiteRepository,
        renderer: new AppProdCaddyConfigRenderer,
        ssh: app_prod_ssh($ssh),
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
            'test ! -f "$current_fragments/$owned_fragment"',
            'caddy validate --config "$candidate/Caddyfile" --adapter caddyfile',
            'mv -fT -- "$candidate_link" "$live_caddyfile"',
            'mv -fT -- "$rollback_link" "$live_caddyfile"',
        )
        ->not->toContain(
            'exec 9>"$lock"',
            'apt-get remove',
            'apt-get purge',
            'rm -rf -- /var/www',
            'rm -rf -- "$current_fragments"',
        );

    $setup = mb_strpos(haystack: $script, needle: 'lock_directory=$(dirname "$lock")');
    $open = mb_strpos(haystack: $script, needle: 'exec 9>>"$lock"');
    expect($setup)->toBeInt()->toBeLessThan($open);
});

it('removes an app production fragment from a direct Caddyfile and restores that file on activation failure', function (): void {
    $success = run_app_prod_direct_caddy_removal(failActivation: false);

    expect($success['exitCode'])
        ->toBe(0, $success['stderr'])
        ->and($success['liveIsLink'])
        ->toBeTrue()
        ->and($success['publishedFragments'])
        ->toBe([
            '00-unmanaged.caddy' => "custom unmanaged\n",
            'custom.caddy' => "custom handler\n",
        ]);

    $failure = run_app_prod_direct_caddy_removal(failActivation: true);

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
function run_app_prod_direct_caddy_removal(bool $failActivation): array
{
    $root = sys_get_temp_dir().'/orbit-caddy-remove-'.bin2hex(random_bytes(8));
    $etc = $root.'/etc/caddy';
    $bin = $root.'/bin';
    $files = new Filesystem;
    $files->ensureDirectoryExists(path: $etc.'/fragments', mode: 0o777, recursive: true);
    $files->ensureDirectoryExists(path: $bin, mode: 0o777, recursive: true);
    file_put_contents(filename: $etc.'/Caddyfile', data: "import fragments/*.caddy\n");
    file_put_contents(filename: $etc.'/fragments/app-prod.caddy', data: "owned\n");
    file_put_contents(filename: $etc.'/fragments/unmanaged.caddy', data: "custom unmanaged\n");
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

    $publisher = new AppProdCaddyPublisher(
        $etc.'/orbit-versions',
        $etc.'/Caddyfile',
        'caddy',
        $etc.'/orbit-locks/caddy.lock',
    );
    $command = $publisher->removeCommand('remove-version');
    $process = new Process(array_slice(array: $command->arguments, offset: 1), $root, [
        'PATH' => $bin.':'.getenv('PATH'),
        'HARNESS_SERVICE_LOG' => $root.'/service.log',
        'HARNESS_FAIL_ACTIVATION' => $failActivation ? '1' : '0',
        'HARNESS_FAILED' => $root.'/failed',
    ]);
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
