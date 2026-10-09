<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Instances\ProductionRuntimeGenerationProgram;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\HostBinary;

it('records a canonical dedicated PHP runtime identity without converting existing placements', function (): void {
    $project = Project::query()->create([
        'name' => 'Dedicated PHP',
        'slug' => 'dedicated-php',
        'repository_url' => 'https://example.test/dedicated-php.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $node = Node::query()->create([
        'name' => 'dedicated-php',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.214',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}",
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
        'app_overrides' => fixture_app_overrides('public'),
        'selected_php_version' => '8.5',
    ]);

    expect(Schema::hasColumns('instances', [
        'production_php_service',
        'production_php_pool',
        'production_php_socket',
    ]))
        ->toBeTrue()
        ->and($instance->production_php_service)
        ->toBeNull()
        ->and($instance->production_php_pool)
        ->toBeNull()
        ->and($instance->production_php_socket)
        ->toBeNull();

    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());

    expect($identity->service)
        ->toBe("orbit-orbit-app-{$project->id}-php8.5-fpm.service")
        ->and($identity->pool)
        ->toBe("orbit-orbit-app-{$project->id}")
        ->and($identity->socket)
        ->toBe("/run/php/orbit-app-{$project->id}.sock")
        ->and($identity->runtimeDirectory)
        ->toBe("/etc/orbit/php-fpm/orbit-app-{$project->id}")
        ->and(ProductionPhpRuntimeIdentity::from($instance->refresh()))
        ->toEqual($identity);
});

it('refuses a stored runtime association that differs from its production identity', function (): void {
    $project = Project::query()->create([
        'name' => 'Conflicting PHP',
        'slug' => 'conflicting-php',
        'repository_url' => 'https://example.test/conflicting-php.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $node = Node::query()->create([
        'name' => 'conflicting-php',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.215',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}",
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
        'app_overrides' => fixture_app_overrides('public'),
        'selected_php_version' => '8.5',
        'production_php_service' => 'php8.5-fpm.service',
        'production_php_pool' => "orbit-orbit-app-{$project->id}",
        'production_php_socket' => "/run/php/orbit-app-{$project->id}.sock",
    ]);

    expect(fn () => ProductionPhpRuntimeIdentity::from($instance))
        ->toThrow(ResourceOperationException::class);
});

it('renders generated identity separately from preserved local defaults', function (string $documentRoot, string $applicationDirectory, bool $initialRelease): void {
    $identity = new ProductionPhpRuntimeIdentity(
        user: 'orbit-app-9',
        home: '/home/orbit-app-9',
        version: '8.5',
        service: 'orbit-orbit-app-9-php8.5-fpm.service',
        pool: 'orbit-orbit-app-9',
        socket: '/run/php/orbit-app-9.sock',
        documentRoot: $documentRoot,
    );
    $renderer = new ProductionPhpRuntimeConfigRenderer;
    $rendered = $initialRelease ? $renderer->render($identity, initialRelease: true) : $renderer->render($identity);

    expect($rendered->main)
        ->toContain(
            'pid = /run/php/orbit-app-9.pid',
            'include = /etc/orbit/php-fpm/orbit-app-9/generated/pool.conf',
            'include = /etc/orbit/php-fpm/orbit-app-9/local.conf',
        )
        ->and($rendered->pool)
        ->toContain(
            '[orbit-orbit-app-9]',
            'user = orbit-app-9',
            'listen = /run/php/orbit-app-9.sock',
            'chdir = '.$applicationDirectory."\n",
            'env[HOME] = /home/orbit-app-9',
        )
        ->and($rendered->localDefaults)
        ->toContain(
            '[orbit-orbit-app-9]',
            'pm = ondemand',
        )
        ->not
        ->toContain('user =', 'listen =', 'chdir =', 'include =', 'opcache.')
        ->and($rendered->masterIni)
        ->toContain(
            'opcache.enable = On',
            'opcache.memory_consumption = 256',
            'opcache.interned_strings_buffer = 32',
            'opcache.max_accelerated_files = 65407',
            'opcache.validate_timestamps = 0',
            'opcache.jit = disable',
            'opcache.jit_buffer_size = 0',
        )
        ->and($rendered->unit)
        ->toContain(
            'Environment=PHP_INI_SCAN_DIR=/etc/php/8.5/fpm/conf.d:/etc/orbit/php-fpm/orbit-app-9/generated',
            'ExecStart=/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /etc/orbit/php-fpm/orbit-app-9/generated/php-fpm.conf',
            'PIDFile=/run/php/orbit-app-9.pid',
        );
})->with([
    'legacy public' => ['/home/orbit-app-9/public', '/home/orbit-app-9', false],
    'root public selected' => ['/home/orbit-app-9/current/public', '/home/orbit-app-9/current', false],
    'nested selected' => ['/home/orbit-app-9/current/server/web/public', '/home/orbit-app-9/current/server/web', false],
    'root public initial clone' => ['/home/orbit-app-9/current/public', '/home/orbit-app-9/releases/initial', true],
    'nested initial clone' => ['/home/orbit-app-9/current/server/web/public', '/home/orbit-app-9/releases/initial/server/web', true],
]);

it('selects an existing application directory for initial startup, deployment and rollback', function (string $root, string $suffix): void {
    $home = sys_get_temp_dir().'/orbit-fpm-application-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $identity = new ProductionPhpRuntimeIdentity(
        user: 'orbit-app-9', home: $home, version: '8.5',
        service: 'orbit-orbit-app-9-php8.5-fpm.service', pool: 'orbit-orbit-app-9',
        socket: '/run/php/orbit-app-9.sock', documentRoot: $home.'/current/'.$root,
    );
    $renderer = new ProductionPhpRuntimeConfigRenderer;
    $selectedPool = $renderer->render($identity)->pool;
    $initialPool = $renderer->render($identity, initialRelease: true)->pool;
    $program = RemoteProductionPhpRuntimeManager::applicationPoolSelectionFunction()."\n"
        .'home='.escapeshellarg($home)."\n"
        .'application='.escapeshellarg($home.'/current'.$suffix)."\n"
        .'initial_application='.escapeshellarg($home.'/releases/initial'.$suffix)."\n"
        .'pool_configuration='.escapeshellarg(base64_encode($selectedPool))."\n"
        .'initial_pool_configuration='.escapeshellarg(base64_encode($initialPool))."\n"
        ."select_application_pool\nprintf '%s' \"\$pool_configuration\" | base64 --decode\n";

    try {
        $files->ensureDirectoryExists($home);
        expect(new Process(['bash', '-seu'], input: $program)->run())->not->toBe(0);
        $files->ensureDirectoryExists($home.'/releases/initial'.$suffix);
        expect(new Process(['bash', '-seu'], input: $program)->mustRun()->getOutput())->toBe($initialPool."\n; Orbit application release: ".$home.'/releases/initial'.$suffix."\n");
        foreach (['fresh', 'initial'] as $release) {
            $files->ensureDirectoryExists($home.'/releases/'.$release.$suffix);
            symlink('releases/'.$release, $home.'/current');
            expect(new Process(['bash', '-seu'], input: $program)->mustRun()->getOutput())->toBe($selectedPool."\n; Orbit application release: ".$home.'/releases/'.$release.$suffix."\n")
                ->toContain('chdir = '.$home.'/current'.$suffix."\n");
            unlink($home.'/current');
        }
        symlink('releases/missing', $home.'/current');
        expect(new Process(['bash', '-seu'], input: $program)->run())->not->toBe(0);
    } finally {
        $files->deleteDirectory($home);
    }
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']]);

it('recovers a killed release publication and keeps confirmed convergence a no-op', function (string $root, string $suffix, string $fault): void {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/orbit-fpm-generation-'.bin2hex(random_bytes(8));
    $files->ensureDirectoryExists($directory.'/runtime/generated');
    $files->ensureDirectoryExists($directory.'/work');
    $files->ensureDirectoryExists($directory.'/units');
    $files->ensureDirectoryExists($directory.'/proc/sys/kernel/random');
    $files->ensureDirectoryExists($directory.'/proc/620');
    file_put_contents($directory.'/proc/sys/kernel/random/boot_id', file_get_contents('/proc/sys/kernel/random/boot_id'));
    file_put_contents($directory.'/proc/620/stat', file_get_contents('/proc/self/stat'));
    file_put_contents($directory.'/proc/620/exe', 'fixture executable');
    file_put_contents($directory.'/pid', '620');
    file_put_contents($directory.'/runtime/local.conf', 'preserved tuning');
    $identity = new ProductionPhpRuntimeIdentity(
        user: 'orbit-fixture', home: $directory.'/home', version: '8.5', service: 'orbit-fixture-php8.5-fpm.service',
        pool: 'orbit-fixture', socket: $directory.'/socket', documentRoot: $directory.'/home/current/'.$root,
    );
    $configuration = new ProductionPhpRuntimeConfigRenderer()->render($identity);
    $oldPool = $configuration->pool."\n; Orbit application release: ".$directory.'/home/releases/initial'.$suffix."\n";
    $newPool = $configuration->pool."\n; Orbit application release: ".$directory.'/home/releases/fresh'.$suffix."\n";
    $desiredFiles = ['php-fpm.conf' => $configuration->main, 'pool.conf' => $newPool, 'master.ini' => $configuration->masterIni, 'unit' => $configuration->unit];
    foreach ($desiredFiles as $name => $content) {
        file_put_contents($directory.'/work/'.$name, $content);
        file_put_contents($name === 'unit' ? $directory.'/units/fpm.service' : $directory.'/runtime/generated/'.$name, $name === 'pool.conf' ? $oldPool : $content);
    }
    $localHash = hash_file('sha256', $directory.'/runtime/local.conf');
    file_put_contents($directory.'/work/local.sha256', $localHash."\n");
    file_put_contents($directory.'/runtime/generated/local.sha256', $localHash."\n");
    $socket = stream_socket_server('unix://'.$directory.'/socket');
    fclose($socket);
    $program = orb304_production_shared_directory_program();
    $start = strpos($program, 'runtime_changed=0');
    expect($start)->toBeInt();
    $transition = substr($program, $start);
    $setup = 'fixture='.escapeshellarg($directory)."\n"
        .'main_configuration='.escapeshellarg(base64_encode($configuration->main))."\n"
        .'master_ini='.escapeshellarg(base64_encode($configuration->masterIni))."\n"
        .'unit_configuration='.escapeshellarg(base64_encode($configuration->unit))."\n".<<<'BASH'
            operation=converge
            user=orbit-fixture
            version=8.5
            service=orbit-fixture-php8.5-fpm.service
            runtime_directory="$fixture/runtime"
            generated_directory="$runtime_directory/generated"
            work_directory="$fixture/work"
            unit_path="$fixture/units/fpm.service"
            local_tuning="$runtime_directory/local.conf"
            expected_marker="$fixture/marker"
            socket="$fixture/socket"
            proc_root="$fixture/proc"
            was_active=1
            was_enabled=1
            had_generated=1
            had_unit=1
            local_before=$(sha256sum "$local_tuning" | awk '{print $1}')
            rm -rf -- "$work_directory/generated.backup"
            cp -a "$generated_directory" "$work_directory/generated.backup"
            cp -a "$unit_path" "$work_directory/unit.backup"
            stat() {
                case "${!#}" in
                    */socket) printf '%s:caddy:660\n' "$user" ;;
                    *) printf 'root:root:%s\n' "$(command stat -c %a -- "${!#}")" ;;
                esac
            }
            chown() { :; }
            install() {
                local args=()
                while [ "$#" -gt 0 ]; do
                    case "$1" in
                        -o|-g) shift 2 ;;
                        *) args+=("$1"); shift ;;
                    esac
                done
                command install "${args[@]}"
            }
            readlink() { printf '/usr/sbin/php-fpm8.5\n'; }
            mv() {
                command mv "$@"
                if [ "${fault:-}" = after-pool ] && [ "${!#}" = "$generated_directory/pool.conf" ]; then kill -KILL "$BASHPID"; fi
            }
            systemctl() {
                case "$1" in
                    is-active|is-enabled) return 0 ;;
                    show) cat "$fixture/pid" ;;
                    restart)
                        if [ "${fault:-}" = before-restart ]; then kill -KILL "$BASHPID"; fi
                        printf 'restart\n' >> "$fixture/calls"
                        next=$(($(cat "$fixture/pid") + 1))
                        mkdir -p "$proc_root/$next"
                        cp "$proc_root/620/stat" "$proc_root/$next/stat"
                        touch "$proc_root/$next/exe"
                        printf '%s' "$next" > "$fixture/pid"
                        ;;
                    *) printf '%s\n' "$1" >> "$fixture/calls" ;;
                esac
            }
            BASH;
    $functions = ProductionRuntimeGenerationProgram::functions()."\n";

    try {
        new Process(['bash', '-seu'], input: $functions.$setup."\npool_configuration=".escapeshellarg(base64_encode($oldPool))."\nconfirm_runtime_generation\n")->mustRun();
        $run = $functions.$setup."\npool_configuration=".escapeshellarg(base64_encode($newPool))."\n";
        $interrupted = new Process(['bash', '-seu'], input: $run.'fault='.escapeshellarg($fault)."\n".$transition);
        expect(fn (): int => $interrupted->run())->toThrow(ProcessSignaledException::class);
        expect($interrupted->getTermSignal())->toBe(SIGKILL, $interrupted->getErrorOutput())
            ->and(file_get_contents($directory.'/pid'))->toBe('620')
            ->and(is_file($directory.'/runtime/.runtime-generation.pending'))->toBeTrue()
            ->and(file_get_contents($directory.'/runtime/generated/pool.conf'))->toBe($newPool);
        foreach ($desiredFiles as $name => $content) {
            expect(file_get_contents($name === 'unit' ? $directory.'/units/fpm.service' : $directory.'/runtime/generated/'.$name))->toBe($content);
        }
        new Process(['bash', '-seu'], input: $run.$transition)->mustRun();
        expect(file_get_contents($directory.'/pid'))->toBe('621')
            ->and(file_exists($directory.'/runtime/.runtime-generation.pending'))->toBeFalse()
            ->and(substr_count(file_get_contents($directory.'/calls'), 'restart'))->toBe(1);
        $applied = file_get_contents($directory.'/runtime/.runtime-generation.applied');
        $appliedInode = fileinode($directory.'/runtime/.runtime-generation.applied');
        expect(explode("\n", trim($applied)))->toHaveCount(4);
        expect(explode("\n", trim($applied))[2])->toBe('621');
        $files->ensureDirectoryExists($directory.'/work');
        foreach ($desiredFiles as $name => $content) {
            file_put_contents($directory.'/work/'.$name, $content);
        }
        file_put_contents($directory.'/work/local.sha256', $localHash."\n");
        new Process(['bash', '-seu'], input: $run.$transition)->mustRun();
        clearstatcache(true, $directory.'/runtime/.runtime-generation.applied');
        expect(file_get_contents($directory.'/runtime/.runtime-generation.applied'))->toBe($applied)
            ->and(fileinode($directory.'/runtime/.runtime-generation.applied'))->toBe($appliedInode)
            ->and(fileperms($directory.'/runtime/.runtime-generation.applied') & 0777)->toBe(0600)
            ->and(substr_count(file_get_contents($directory.'/calls'), 'restart'))->toBe(1);
    } finally {
        $files->deleteDirectory($directory);
    }
})->with([
    'root after pool publication' => ['public', '', 'after-pool'],
    'root before restart' => ['public', '', 'before-restart'],
    'nested after pool publication' => ['server/web/public', '/server/web', 'after-pool'],
    'nested before restart' => ['server/web/public', '/server/web', 'before-restart'],
]);

it('gives pool.conf one pool owner when service metrics toggle', function (): void {
    [$instance] = orb214_runtime_instance();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());
    $ssh = new AppDevFakeSshExecutor;
    $renderer = new ProductionPhpRuntimeConfigRenderer;
    $manager = new RemoteProductionPhpRuntimeManager(
        renderer: $renderer,
        ssh: orb214_app_prod_ssh($ssh),
    );

    $manager->convergeMonitoring($instance->refresh(), true);
    $manager->convergeMonitoring($instance->refresh(), false);

    $monitorCommands = collect($ssh->commands)
        ->filter(static fn ($command): bool => ($command->arguments[4] ?? null) === 'monitor')
        ->values();
    $enabledCommand = $monitorCommands[0];
    $disabledCommand = $monitorCommands[1];
    $enabledPool = base64_decode($enabledCommand->arguments[18], true);
    $disabledPool = base64_decode($disabledCommand->arguments[18], true);
    $metricsProgram = file_get_contents(resource_path('scripts/service-metrics-fpm.py'));
    $monitorBranchStart = strpos($enabledCommand->input, 'converge_monitoring_pool() {');
    $monitorBranchEnd = strpos($enabledCommand->input, 'operation=$1', $monitorBranchStart);
    $monitorBranch = substr($enabledCommand->input, $monitorBranchStart, $monitorBranchEnd - $monitorBranchStart);
    $candidateCleanup = strpos($enabledCommand->input, 'cleanup_interrupted_monitoring_candidate "$generated_directory" "$runtime_directory"');
    $generatedAllowlist = strpos($enabledCommand->input, 'unexpected_generated=$(find');
    $monitorIdentityGuard = strpos($enabledCommand->input, 'if [ "$operation" = monitor ]');
    $sharedDirectoryConvergence = strpos($enabledCommand->input, 'converge_shared_orbit_directory /etc/orbit 1');
    $syntax = new Process(['bash', '-n']);
    $syntax->setInput($enabledCommand->input);
    $syntax->run();

    expect($syntax->getExitCode())->toBe(0, $syntax->getErrorOutput())
        ->and($monitorCommands)->toHaveCount(2)
        ->and($enabledPool)->toBe($renderer->render($identity, true)->pool)
        ->and($disabledPool)->toBe($renderer->render($identity, false)->pool)
        ->and($enabledPool)->toContain('pm.status_path = /orbit-fpm-status')
        ->and($enabledPool)->toContain('pm.status_listen = '.$identity->socket.'.status')
        ->and($disabledPool)->not->toContain('pm.status_path')
        ->and($disabledPool)->not->toContain('pm.status_listen')
        ->and($enabledCommand->input)->toContain('converge_monitoring_pool', "\nselect_application_pool\n")
        ->and($enabledCommand->arguments[24])->toBe($instance->production_home.'/current')
        ->and($enabledCommand->arguments[25])->toBe($instance->production_home.'/releases/initial')
        ->and($candidateCleanup)->toBeInt()->toBeLessThan($generatedAllowlist)
        ->and($monitorIdentityGuard)->toBeInt()->toBeLessThan($sharedDirectoryConvergence)
        ->and($enabledCommand->input)->toContain('! test -f "$marker_path"', '! test -d "$generated_directory"')
        ->and($monitorBranch)->toContain('systemctl reload "$service"')
        ->and($monitorBranch)->not->toContain('systemctl restart')
        ->and($monitorBranch)->not->toContain('systemctl start')
        ->and($monitorBranch)->not->toContain('systemctl enable')
        ->and($metricsProgram)->toBeString()->not->toContain('os.replace')
        ->and($metricsProgram)->not->toContain('pool.write_text')
        ->and($metricsProgram)->not->toContain('write(pool');
});

it('restores the old pool when only candidate reloads fail', function (): void {
    $root = sys_get_temp_dir().'/orbit-pool-candidate-reload-'.bin2hex(random_bytes(6));
    $runtime = $root.'/runtime';
    $generated = $runtime.'/generated';
    $bin = $root.'/bin';
    $pool = $generated.'/pool.conf';
    $desired = $root.'/desired.conf';
    $rollback = $root.'/rollback.conf';
    $service = 'orbit-recovery-php8.5-fpm.service';
    $original = "[recovery]\nuser = recovery\n";
    $candidate = $original."pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/recovery.sock.status\n";

    try {
        mkdir($generated, 0700, true);
        mkdir($bin, 0700, true);
        file_put_contents($pool, $original);
        file_put_contents($desired, $candidate);
        file_put_contents($rollback, $original);
        orb214_write_pool_recovery_systemctl($bin, 'fail-candidate', $pool);

        $candidateAttempt = orb214_run_monitoring_pool_convergence($pool, $desired, $runtime, $service, 1, $bin);
        $rollbackAttempt = orb214_run_monitoring_pool_convergence($pool, $rollback, $runtime, $service, 1, $bin);

        expect($candidateAttempt->getExitCode())->toBe(1)
            ->and($rollbackAttempt->getExitCode())->toBe(0, $rollbackAttempt->getErrorOutput())
            ->and(file_get_contents($pool))->toBe($original)
            ->and(file_exists($runtime.'/.metrics-pool.pending'))->toBeFalse()
            ->and(file_exists($runtime.'/.metrics-pool.backup'))->toBeFalse()
            ->and(substr_count((string) file_get_contents($root.'/systemctl.log'), 'reload '.$service))->toBe(2);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('recovers an interrupted monitoring pool publication before accepting equal files', function (): void {
    $root = sys_get_temp_dir().'/orbit-pool-recovery-'.bin2hex(random_bytes(6));
    $runtime = $root.'/runtime';
    $generated = $runtime.'/generated';
    $bin = $root.'/bin';
    $pool = $generated.'/pool.conf';
    $desired = $root.'/desired.conf';
    $service = 'orbit-recovery-php8.5-fpm.service';
    $original = "[recovery]\nuser = recovery\n";
    $candidate = $original."pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/recovery.sock.status\n";

    try {
        mkdir($generated, 0700, true);
        mkdir($bin, 0700, true);
        file_put_contents($pool, $candidate);
        file_put_contents($desired, $candidate);
        file_put_contents($runtime.'/.metrics-pool.backup', $original);
        file_put_contents($runtime.'/.metrics-pool.pending', 'active '.hash('sha256', $candidate)."\n");
        chmod($runtime.'/.metrics-pool.backup', 0600);
        chmod($runtime.'/.metrics-pool.pending', 0600);
        orb214_write_pool_recovery_systemctl($bin, 'success');
        $process = orb214_run_monitoring_pool_convergence($pool, $desired, $runtime, $service, 1, $bin);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_exists($runtime.'/.metrics-pool.pending'))->toBeFalse()
            ->and(file_exists($runtime.'/.metrics-pool.backup'))->toBeFalse()
            ->and(file_get_contents($root.'/systemctl.log'))->toContain('reload '.$service);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('restores the prior pool before retrying a recovery reload that also fails', function (): void {
    $root = sys_get_temp_dir().'/orbit-pool-recovery-failure-'.bin2hex(random_bytes(6));
    $runtime = $root.'/runtime';
    $generated = $runtime.'/generated';
    $bin = $root.'/bin';
    $pool = $generated.'/pool.conf';
    $desired = $root.'/desired.conf';
    $rollback = $root.'/rollback.conf';
    $service = 'orbit-recovery-php8.5-fpm.service';
    $original = "[recovery]\nuser = recovery\n";
    $candidate = $original."pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/recovery.sock.status\n";

    try {
        mkdir($generated, 0700, true);
        mkdir($bin, 0700, true);
        file_put_contents($pool, $original);
        file_put_contents($desired, $candidate);
        file_put_contents($rollback, $original);
        orb214_write_pool_recovery_systemctl($bin, 'fail-reload');

        $candidateAttempt = orb214_run_monitoring_pool_convergence($pool, $desired, $runtime, $service, 1, $bin);
        $rollbackAttempt = orb214_run_monitoring_pool_convergence($pool, $rollback, $runtime, $service, 1, $bin);

        expect($candidateAttempt->getExitCode())->toBe(1)
            ->and($rollbackAttempt->getExitCode())->toBe(1)
            ->and(file_get_contents($pool))->toBe($original)
            ->and(file_get_contents($runtime.'/.metrics-pool.backup'))->toBe($original)
            ->and(file_get_contents($runtime.'/.metrics-pool.pending'))->toBe('active '.hash('sha256', $candidate)."\n")
            ->and(file_get_contents($root.'/systemctl.log'))->toContain('reload '.$service)
            ->not->toContain('start '.$service);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('resolves a stopped master monitoring journal without starting or reloading it', function (): void {
    $root = sys_get_temp_dir().'/orbit-pool-recovery-stopped-'.bin2hex(random_bytes(6));
    $runtime = $root.'/runtime';
    $generated = $runtime.'/generated';
    $bin = $root.'/bin';
    $pool = $generated.'/pool.conf';
    $desired = $root.'/desired.conf';
    $service = 'orbit-recovery-php8.5-fpm.service';

    try {
        mkdir($generated, 0700, true);
        mkdir($bin, 0700, true);
        file_put_contents($pool, "[recovery]\nuser = recovery\n");
        file_put_contents($desired, "[recovery]\nuser = recovery\n");
        file_put_contents($runtime.'/.metrics-pool.backup', "[recovery]\nuser = recovery\n");
        file_put_contents($runtime.'/.metrics-pool.pending', 'stopped '.hash_file('sha256', $pool)."\n");
        chmod($runtime.'/.metrics-pool.backup', 0600);
        chmod($runtime.'/.metrics-pool.pending', 0600);
        orb214_write_pool_recovery_systemctl($bin, 'stopped');
        $process = orb214_run_monitoring_pool_convergence($pool, $desired, $runtime, $service, 0, $bin);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_exists($runtime.'/.metrics-pool.pending'))->toBeFalse()
            ->and(file_get_contents($root.'/systemctl.log'))->not->toContain('reload '.$service)
            ->and(file_get_contents($root.'/systemctl.log'))->not->toContain('start '.$service);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('cleans an interrupted rollback candidate before preflight and completes recovery', function (): void {
    $root = sys_get_temp_dir().'/orbit-pool-preflight-'.bin2hex(random_bytes(6));
    $runtime = $root.'/runtime';
    $generated = $runtime.'/generated';
    $pool = $generated.'/pool.conf';
    $backup = $runtime.'/.metrics-pool.backup';
    $pending = $runtime.'/.metrics-pool.pending';
    $rollbackConfig = $root.'/rollback.conf';
    $candidate = $generated.'/.pool.conf.123.candidate';
    $original = "[recovery]\nuser = recovery\n";
    $desired = $original."pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/recovery.sock.status\n";

    try {
        mkdir($generated, 0700, true);
        mkdir($root.'/bin', 0700, true);
        file_put_contents($pool, $desired);
        file_put_contents($backup, $original);
        file_put_contents($rollbackConfig, $original);
        file_put_contents($pending, 'active '.hash('sha256', $desired)."\n");
        file_put_contents($candidate, $original);
        chmod($backup, 0600);
        chmod($pending, 0600);
        chmod($candidate, 0644);

        $preflight = orb214_run_monitoring_pool_preflight($generated, $runtime);

        expect($preflight->getExitCode())->toBe(0, $preflight->getErrorOutput())
            ->and(file_exists($candidate))->toBeFalse()
            ->and(file_get_contents($pool))->toBe($desired);

        orb214_write_pool_recovery_systemctl($root.'/bin', 'success');
        $rollback = orb214_run_monitoring_pool_convergence(
            $pool,
            $rollbackConfig,
            $runtime,
            'orbit-recovery-php8.5-fpm.service',
            1,
            $root.'/bin',
        );
        expect($rollback->getExitCode())->toBe(0, $rollback->getErrorOutput())
            ->and(file_get_contents($pool))->toBe($original)
            ->and(file_exists($pending))->toBeFalse()
            ->and(file_exists($backup))->toBeFalse();

        file_put_contents($generated.'/unrelated.conf', 'operator-owned');
        $unrelated = orb214_run_monitoring_pool_preflight($generated, $runtime);
        expect($unrelated->getExitCode())->toBe(1);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('publishes and removes only the recorded service while preserving local tuning', function (): void {
    [$instance, $node] = orb214_runtime_instance();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteProductionPhpRuntimeManager(
        renderer: new ProductionPhpRuntimeConfigRenderer,
        ssh: orb214_app_prod_ssh($ssh),
    );

    $manager->converge($instance->refresh());
    $manager->remove($instance->refresh());

    $publish = collect($ssh->commands)
        ->first(static fn ($command): bool => in_array('converge', $command->arguments, true));
    $remove = collect($ssh->commands)
        ->first(static fn ($command): bool => in_array('remove', $command->arguments, true));

    expect($publish?->arguments)
        ->toContain(
            $identity->service,
            $identity->pool,
            $identity->socket,
            $identity->runtimeDirectory,
        )
        ->and($publish?->input)
        ->toContain(
            'if [ ! -e "$local_tuning" ]',
            'local_before=$(sha256sum -- "$local_tuning"',
            'cp -- "$work_directory/php-fpm.conf" "$work_directory/php-fpm.validate.conf"',
            'PHP_INI_SCAN_DIR="/etc/php/$version/fpm/conf.d:$work_directory"',
            'PHP %s dedicated fpm does not apply %s = %s from %s.',
            '! -name master.ini',
            'if [ -e "$generated_directory/master.ini" ] || [ -L "$generated_directory/master.ini" ]; then',
            'for comparison in php-fpm.conf pool.conf master.ini local.sha256',
            'local_after=$(sha256sum -- "$local_tuning"',
            'test "$local_before" = "$local_after"',
            'if [ "$was_active" = 1 ] && [ "$runtime_changed" = 0 ]; then',
            'systemctl restart "$service"',
            'systemctl enable --now "$service"',
            'systemctl is-active --quiet "$service"',
            'test -S "$socket"',
        )
        ->not->toContain('php$version-fpm.service')->and($remove?->input)->toContain(
            'orbit-"$user"-php*-fpm.service) ;;',
            'systemctl disable --now "$service"',
            'rm -rf -- "$generated_directory"',
            'rm -f -- "$unit_path"',
            "systemctl daemon-reload\nsystemctl reset-failed \"\$service\" >/dev/null 2>&1 || true\n",
            'test -f "$local_tuning"',
        )
        ->not->toContain('rm -f -- "$local_tuning"', 'rm -rf -- "$runtime_directory"');

    expect($node->wireguard_ip)->toBe('10.44.0.214');
});

it('creates and repairs the shared Orbit directory before preserving protected runtime siblings', function (): void {
    $program = orb304_production_shared_directory_program();
    $root = sys_get_temp_dir().'/orbit-production-shared-parent-'.bin2hex(random_bytes(6));
    $bin = $root.'/bin';
    $missing = $root.'/missing/orbit';
    $existing = $root.'/existing/orbit';

    try {
        mkdir($bin, 0700, true);
        orb304_write_root_identity_commands($bin);
        mkdir(dirname($missing), 0700, true);

        $missingResult = orb304_run_shared_directory_program($program, $missing, 1, $bin);

        expect($missingResult->getExitCode())
            ->toBe(0)
            ->and(substr(sprintf('%o', fileperms($missing)), -3))
            ->toBe('711');

        mkdir($existing.'/php-fpm/protected', 0700, true);
        file_put_contents($existing.'/php-fpm/protected/operator.conf', "operator-tuning\n");
        chmod($existing, 0700);
        chmod($existing.'/php-fpm', 0700);
        chmod($existing.'/php-fpm/protected', 0700);
        chmod($existing.'/php-fpm/protected/operator.conf', 0600);
        $tuningHash = hash_file('sha256', $existing.'/php-fpm/protected/operator.conf');

        $first = orb304_run_shared_directory_program($program, $existing, 1, $bin);
        $retry = orb304_run_shared_directory_program($program, $existing, 1, $bin);

        expect($first->getExitCode())
            ->toBe(0)
            ->and($retry->getExitCode())
            ->toBe(0)
            ->and(substr(sprintf('%o', fileperms($existing)), -3))
            ->toBe('711')
            ->and(substr(sprintf('%o', fileperms($existing.'/php-fpm')), -3))
            ->toBe('700')
            ->and(substr(sprintf('%o', fileperms($existing.'/php-fpm/protected')), -3))
            ->toBe('700')
            ->and(substr(sprintf('%o', fileperms($existing.'/php-fpm/protected/operator.conf')), -3))
            ->toBe('600')
            ->and(hash_file('sha256', $existing.'/php-fpm/protected/operator.conf'))
            ->toBe($tuningHash)
            ->and(mb_strpos($program, 'converge_shared_orbit_directory /etc/orbit 1'))
            ->toBeLessThan(mb_strpos($program, 'if [ ! -e "$runtime_directory" ]'));
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('refuses a conflicting shared Orbit object without changing it or its contents', function (string $conflict): void {
    $program = orb304_production_shared_directory_program();
    $root = sys_get_temp_dir().'/orbit-production-shared-conflict-'.bin2hex(random_bytes(6));
    $parent = $root.'/orbit';
    $target = $root.'/symlink-target';

    try {
        mkdir($root, 0700, true);

        if ($conflict === 'foreign-owned-directory') {
            mkdir($parent, 0700);
            file_put_contents($parent.'/sentinel', "preserve-directory\n");
        } elseif ($conflict === 'symlink') {
            mkdir($target, 0700);
            file_put_contents($target.'/sentinel', "preserve-target\n");
            symlink($target, $parent);
        } else {
            file_put_contents($parent, "preserve-file\n");
            chmod($parent, 0600);
        }

        $before = orb304_conflicting_object_state($parent, $target);
        $result = orb304_run_shared_directory_program($program, $parent, 1);

        expect($result->getExitCode())
            ->toBe(1)
            ->and(orb304_conflicting_object_state($parent, $target))
            ->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'foreign-owned directory' => ['foreign-owned-directory'],
    'symlink' => ['symlink'],
    'non-directory' => ['non-directory'],
]);

it('does not delete runtime state while a partially unlinked service still has an active master', function (): void {
    [$instance] = orb214_runtime_instance();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteProductionPhpRuntimeManager(
        renderer: new ProductionPhpRuntimeConfigRenderer,
        ssh: orb214_app_prod_ssh($ssh),
    );
    $manager->remove($instance->refresh());
    $script = $ssh->commands[0]->input ?? '';
    $start = mb_strpos($script, 'if ! systemctl disable --now "$service"');
    $end = mb_strpos($script, 'rm -rf -- "$generated_directory"');
    $block = substr($script, (int) $start, (int) $end - (int) $start);
    $root = sys_get_temp_dir().'/orbit-production-runtime-remove-'.bin2hex(random_bytes(6));

    try {
        mkdir($root, 0700, true);
        $process = new Process(['bash', '-seu', '--', $root]);
        $process->setInput(<<<'BASH'
            root=$1
            service=orbit-orbit-app-9-php8.5-fpm.service
            unit_path="$root/missing.service"
            systemctl() {
                case "$1" in
                    disable|stop) return 1 ;;
                    is-active) return 0 ;;
                    show) printf '4242\n' ;;
                    *) return 1 ;;
                esac
            }
            BASH."\n".$block."\n".<<<'BASH'
            touch "$root/cleanup-reached"
            BASH);
        $process->run();

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and(file_exists($root.'/cleanup-reached'))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('keeps a dedicated production route in Caddy and out of shared FPM publication', function (): void {
    [$dedicated, $node] = orb214_runtime_instance();
    $dedicatedIdentity = ProductionPhpRuntimeIdentity::forProvisioning($dedicated, '8.5');
    $dedicated->update([
        ...$dedicatedIdentity->attributes(),
        'status' => InstanceState::SourceResolved,
    ]);
    $dedicatedRoute = Route::query()->create([
        'project_id' => $dedicated->project_id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'domain' => 'dedicated.example.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $dedicatedRoute->targets()->create(['instance_id' => $dedicated->id, 'position' => 0]);
    $dedicatedRoute->update(['status' => RouteStatus::Active]);

    $sharedApp = Project::query()->create([
        'name' => 'Shared PHP',
        'slug' => 'shared-php',
        'repository_url' => 'https://example.test/shared-php.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $shared = Instance::query()->create([
        'project_id' => $sharedApp->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$sharedApp->id}",
        'production_user' => "orbit-app-{$sharedApp->id}",
        'production_home' => "/home/orbit-app-{$sharedApp->id}",
        'app_overrides' => fixture_app_overrides('public'),
        'selected_php_version' => '8.5',
        'status' => InstanceState::SourceResolved,
    ]);
    $sharedRoute = Route::query()->create([
        'project_id' => $shared->project_id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'domain' => 'shared.example.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $sharedRoute->targets()->create(['instance_id' => $shared->id, 'position' => 0]);
    $sharedRoute->update(['status' => RouteStatus::Active]);

    $sites = new DevelopmentSiteRepository()->forNode($node);
    $caddy = new DevelopmentCaddyConfigRenderer()->render($sites);
    $sharedFpm = new DevelopmentPhpFpmConfigRenderer()->render(
        $sites->reject->usesDedicatedPhpRuntime()->values(),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );

    expect($sites)
        ->toHaveCount(2)
        ->and($caddy)
        ->toContain(
            "php_fastcgi unix/{$dedicatedIdentity->socket}",
            "php_fastcgi unix//run/php/orbit-app-instance-{$shared->id}.sock",
        )
        ->and($sharedFpm)
        ->toContain("[orbit-app-instance-{$shared->id}]")
        ->not->toContain(
            "[orbit-app-instance-{$dedicated->id}]",
            $dedicatedIdentity->socket,
        );
});

/** @return array{Instance, Node} */
function orb214_runtime_instance(): array
{
    $project = Project::query()->create([
        'name' => 'Runtime fixture',
        'slug' => 'runtime-fixture',
        'repository_url' => 'https://example.test/runtime-fixture.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $node = Node::query()->create([
        'name' => 'runtime-fixture',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.216',
        'wireguard_ip' => '10.44.0.214',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $user = "orbit-app-{$project->id}";

    return [
        Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'primary',
            'environment' => 'production',
            'checkout_path' => "/home/{$user}",
            'production_user' => $user,
            'production_home' => "/home/{$user}",
            'app_overrides' => fixture_app_overrides('public'),
            'selected_php_version' => '8.5',
        ]),
        $node,
    ];
}

function orb214_app_prod_ssh(AppDevFakeSshExecutor $ssh): ProductionSshExecutor
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
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
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new ProductionSshExecutor($ssh, $keys, $knownHosts);
}

function orb304_production_shared_directory_program(): string
{
    [$instance] = orb214_runtime_instance();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteProductionPhpRuntimeManager(
        renderer: new ProductionPhpRuntimeConfigRenderer,
        ssh: orb214_app_prod_ssh($ssh),
    );

    $manager->converge($instance->refresh());
    $command = collect($ssh->commands)
        ->first(static fn ($command): bool => in_array('converge', $command->arguments, true));

    return $command?->input ?? '';
}

function orb304_run_shared_directory_program(
    string $program,
    string $directory,
    int $conflictExit,
    ?string $bin = null,
): Process {
    $operation = mb_strpos($program, "\noperation=");
    expect($operation)->not->toBeFalse();
    $function = substr($program, 0, (int) $operation);
    $process = new Process(
        ['bash', '-seu', '--', $directory, (string) $conflictExit],
        env: $bin === null ? null : ['PATH' => $bin.':'.getenv('PATH')],
    );
    $process->setInput($function."\n".<<<'BASH'
        converge_shared_orbit_directory "$1" "$2"
        BASH);
    $process->run();

    return $process;
}

function orb304_write_root_identity_commands(string $bin): void
{
    file_put_contents($bin.'/install', <<<'BASH'
        #!/bin/sh
        for argument do directory=$argument; done
        mkdir -m 0711 -- "$directory"
        BASH);
    chmod($bin.'/install', 0700);
    file_put_contents($bin.'/stat', HostBinary::expand(<<<'BASH'
        #!/bin/sh
        if [ "$1" = -c ] && [ "$2" = %U:%G ]; then
            printf 'root:root\n'
            exit 0
        fi
        if [ "$1" = -c ] && [ "$2" = %U:%G:%a ]; then
            shift 2
            [ "$1" = -- ] && shift
            printf 'root:root:%s\n' "$({{host:stat}} -c %a -- "$1")"
            exit 0
        fi
        exec {{host:stat}} "$@"
        BASH));
    chmod($bin.'/stat', 0700);
}

function orb214_run_monitoring_pool_convergence(
    string $pool,
    string $desired,
    string $runtime,
    string $service,
    int $wasActive,
    string $bin,
): Process {
    $method = new ReflectionMethod(RemoteProductionPhpRuntimeManager::class, 'monitoringPoolConvergenceFunction');
    $method->setAccessible(true);
    $function = (string) $method->invoke(null);
    $process = new Process(
        ['bash', '-seu', '--', $pool, $desired, $runtime, $service, (string) $wasActive],
        env: ['PATH' => $bin.':'.getenv('PATH')],
    );
    $process->setInput(orb214_pool_recovery_test_shell()."\n".$function."\n".'converge_monitoring_pool "$1" "$2" "$3" "$4" "$5" apply');
    $process->run();

    return $process;
}

function orb214_run_monitoring_pool_preflight(string $generated, string $runtime): Process
{
    $method = new ReflectionMethod(RemoteProductionPhpRuntimeManager::class, 'cleanupInterruptedMonitoringCandidateFunction');
    $method->setAccessible(true);
    $function = (string) $method->invoke(null);
    $process = new Process(['bash', '-seu', '--', $generated, $runtime]);
    $process->setInput(orb214_pool_recovery_test_shell()."\n".$function."\n".<<<'BASH'
        cleanup_interrupted_monitoring_candidate "$1" "$2"
        unexpected_generated=$(find -P "$1" -mindepth 1 -maxdepth 1 \
            ! -name php-fpm.conf ! -name pool.conf ! -name master.ini ! -name local.sha256 -print -quit)
        test -z "$unexpected_generated"
        BASH);
    $process->run();

    return $process;
}

function orb214_pool_recovery_test_shell(): string
{
    return <<<'BASH'
        install() {
            local -a arguments=()
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    -o|-g) shift 2 ;;
                    *) arguments+=("$1"); shift ;;
                esac
            done
            command install "${arguments[@]}"
        }
        chown() { return 0; }
        stat() {
            if [ "$1" = -c ] && [ "$2" = %U:%G:%a ]; then
                shift 2
                [ "$1" = -- ] && shift
                printf 'root:root:%s\n' "$(command stat -c %a -- "$1")"
            else
                command stat "$@"
            fi
        }
        BASH;
}

function orb214_write_pool_recovery_systemctl(string $bin, string $mode, ?string $pool = null): void
{
    $log = escapeshellarg(dirname($bin).'/systemctl.log');
    $poolPath = escapeshellarg($pool ?? '');
    $body = match ($mode) {
        'stopped' => <<<'SH'
            case "$1" in is-active) exit 1 ;; reload|start) exit 91 ;; *) exit 0 ;; esac
            SH,
        'fail-candidate' => str_replace('__POOL__', $poolPath, <<<'SH'
            case "$1" in
                is-active) exit 0 ;;
                reload) if grep -qF 'pm.status_path = /orbit-fpm-status' __POOL__; then exit 1; fi ;;
                start) exit 91 ;;
            esac
            exit 0
            SH),
        'fail-reload' => <<<'SH'
            case "$1" in is-active) exit 0 ;; reload) exit 1 ;; start) exit 91 ;; *) exit 0 ;; esac
            SH,
        default => <<<'SH'
            case "$1" in is-active|reload) exit 0 ;; start) exit 91 ;; *) exit 0 ;; esac
            SH,
    };
    $program = sprintf(<<<'SH'
        #!/bin/sh
        printf '%%s\n' "$*" >> %s
        %s
        SH, $log, $body);
    file_put_contents($bin.'/systemctl', $program);
    chmod($bin.'/systemctl', 0700);
}

/** @return array{type: string, mode: string, value: string} */
function orb304_conflicting_object_state(string $parent, string $target): array
{
    if (is_link($parent)) {
        return [
            'type' => 'symlink',
            'mode' => substr(sprintf('%o', lstat($parent)['mode']), -3),
            'value' => (string) readlink($parent).'|'.hash_file('sha256', $target.'/sentinel'),
        ];
    }

    if (is_dir($parent)) {
        return [
            'type' => 'directory',
            'mode' => substr(sprintf('%o', fileperms($parent)), -3),
            'value' => hash_file('sha256', $parent.'/sentinel'),
        ];
    }

    return [
        'type' => 'file',
        'mode' => substr(sprintf('%o', fileperms($parent)), -3),
        'value' => hash_file('sha256', $parent),
    ];
}
