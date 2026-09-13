<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Doctor\AppInspectionData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\WorkspaceInspectionData;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppProd\AppProdCaddyConfigRenderer;
use App\Infrastructure\AppProd\AppProdPhpFpmConfigRenderer;
use App\Infrastructure\AppProd\AppProdSite;
use App\Infrastructure\Doctor\NativeAppStateInspector;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeWorkspaceStateInspector;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use App\Models\Workspace;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

it('checks only selected-node app projections through the fixed SSH boundary', function (): void {
    [$app, $node, $instance, $workspace] = application_inspector_models();
    application_inspector_instance($app, application_inspector_node(), CertificateMode::OrbitCa);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n"), app_inspector_result("1\n")]);

    $inspection = application_app_inspector($ssh)->inspect($app, $node);

    expect($inspection)
        ->toEqual(new AppInspectionData(2, true))
        ->and($ssh->commands)
        ->toHaveCount(2)
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $app->repository_url,
            $instance->checkout_path,
            '/srv/users/nckrtl',
            'nckrtl',
            '',
            '',
            'app-dev',
            '/srv/users/nckrtl',
        ])
        ->and($ssh->commands[1]->arguments[4])
        ->toBe($workspace->checkout_path)
        ->and($ssh->connections[0]->host)
        ->toBe($node->wireguard_ip)
        ->and($ssh->connections[0]->user)
        ->toBe('nckrtl')
        ->and($ssh->connections[0]->port)
        ->toBe(22)
        ->and($ssh->connections[0]->identityFile)
        ->toBe('/tmp/doctor-key')
        ->and($ssh->connections[0]->knownHostsFile)
        ->toBe('/tmp/doctor-known-hosts')
        ->and($ssh->connections[0]->commandTimeout)
        ->toBe(30.0);
});

it('checks app-production origins as the app owner within its production root', function (): void {
    [$app, $node, $instance] = application_inspector_models();
    $instance->update([
        'certificate_mode' => CertificateMode::Acme,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/var/www/{$app->slug}/production",
    ]);
    $instance->workspaces()->delete();
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);

    $inspection = application_app_inspector($ssh)->inspect($app, $node);

    expect($inspection)
        ->toEqual(new AppInspectionData(1, true))
        ->and($ssh->connections[0]->user)
        ->toBe('nckrtl')
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $app->repository_url,
            $instance->checkout_path,
            "/var/www/{$app->slug}",
            "orbit-{$app->slug}",
            $app->slug,
            $instance->name,
            'app-prod',
            '',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain('sudo -u "$user" -H -- git -C "$checkout" remote get-url origin');
});

it('returns a bounded mismatch for an app-production origin', function (): void {
    [$app, $node, $instance] = application_inspector_models();
    $instance->update([
        'certificate_mode' => CertificateMode::Acme,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/var/www/{$app->slug}/production",
    ]);
    $instance->workspaces()->delete();
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("0\n")]);

    $inspection = application_app_inspector($ssh)->inspect($app, $node);

    expect($inspection)
        ->toEqual(new AppInspectionData(1, false))
        ->and($ssh->commands[0]->input)
        ->toContain('test "$(sudo -u "$user" -H -- stat -c %U "$checkout")" = "$user"');
});

it('returns a bounded app mismatch and a healthy empty selection', function (): void {
    [$app, $node] = application_inspector_models();
    $mismatch = application_app_inspector(new AppDevFakeSshExecutor([
        app_inspector_result("0\n"),
        app_inspector_result("1\n"),
    ]))
        ->inspect($app, $node);
    $empty = application_app_inspector(new AppDevFakeSshExecutor)->inspect($app, application_inspector_node());

    expect($mismatch)
        ->toEqual(new AppInspectionData(2, false))
        ->and($empty)
        ->toEqual(new AppInspectionData(0, true));
});

it('fails app inspection closed for invalid intent and failed observations', function (
    string $repository,
    CommandResult $result,
): void {
    [$app, $node] = application_inspector_models();
    $app->repository_url = $repository;

    expect(
        fn (): AppInspectionData => application_app_inspector(
            new AppDevFakeSshExecutor([$result]),
        )
            ->inspect($app, $node),
    )
        ->toThrow(DoctorInspectionException::class, '');
})->with([
    'invalid origin' => ['not a repository', app_inspector_result("1\n")],
    'command failure' => ['https://github.com/acme/project.git', app_inspector_result('', exitCode: 1)],
    'malformed output' => ['https://github.com/acme/project.git', app_inspector_result('private-output')],
    'truncated output' => ['https://github.com/acme/project.git', app_inspector_result("1\n", truncated: true)],
]);

it('observes only AppInstance source evidence through the fixed SSH boundary', function (): void {
    $node = application_inspector_node();
    $appInstance = application_app_instance(application_inspector_app(), $node);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($appInstance);

    expect($inspection)
        ->toEqual(new InstanceInspectionData(true, true, true, true))
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $appInstance->app->repository_url,
            $appInstance->checkout_path,
            '/srv/users/nckrtl/apps',
            'nckrtl',
            'nckrtl',
            $appInstance->source_layout,
            $appInstance->branch,
            $appInstance->starting_commit,
            '0',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain('repository_layout_matches', 'origin_matches', 'source_identity_matches')
        ->not->toContain('caddy', 'php', 'certificate', 'dns', 'hostname');
});

it('maps each AppInstance source observation without retaining diagnostics', function (
    string $remote,
    InstanceInspectionData $expected,
): void {
    $appInstance = application_app_instance(application_inspector_app(), application_inspector_node());
    $ssh = new AppDevFakeSshExecutor([app_inspector_result($remote, stderr: 'private-stderr')]);

    $inspection = application_instance_inspector($ssh)->inspect($appInstance);

    expect($inspection)->toEqual($expected)->and(json_encode($inspection))->not->toContain('private');
})->with([
    'checkout missing' => ["0\n1\n1\n1\n", new InstanceInspectionData(false, true, true, true)],
    'repository not independent' => ["1\n0\n1\n1\n", new InstanceInspectionData(true, false, true, true)],
    'origin mismatch' => ["1\n1\n0\n1\n", new InstanceInspectionData(true, true, false, true)],
    'source identity mismatch' => ["1\n1\n1\n0\n", new InstanceInspectionData(true, true, true, false)],
]);

it('rejects an unavailable marker from the non-nullable development observation', function (): void {
    $appInstance = application_app_instance(application_inspector_app(), application_inspector_node());

    expect(fn (): InstanceInspectionData => application_instance_inspector(
        new AppDevFakeSshExecutor([app_inspector_result("2\n1\n1\n1\n")]),
    )->inspect($appInstance))->toThrow(DoctorInspectionException::class, '');
});

it('observes production projections through fixed arguments and protected input', function (): void {
    $secret = 'doctor-production-secret';
    $appInstance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        $secret,
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($appInstance);
    $command = $ssh->commands[0];
    $protected = $command->protectedInput;
    if (! $protected instanceof ProtectedInput) {
        throw new RuntimeException('Expected protected production inspection input.');
    }
    $program = stream_get_contents($protected->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }
    application_run(['bash', '-n'], $program);
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($appInstance);

    expect($inspection)
        ->toEqual(new InstanceInspectionData(
            true,
            true,
            true,
            true,
            productionHomeMatches: true,
            releaseSelectionMatches: true,
            selectedReleaseRootMatches: true,
            environmentProjectionMatches: true,
            phpFpmProjectionMatches: true,
            caddyProjectionMatches: true,
        ))
        ->and($command->arguments)
        ->toBe(['sudo', 'bash', '-s', '--'])
        ->and($command->input)
        ->toBeNull()
        ->and($command->protectedInput)
        ->toBeInstanceOf(ProtectedInput::class)
        ->and($command->maxOutputBytes)
        ->toBe(128)
        ->and(json_encode($command, JSON_THROW_ON_ERROR))
        ->not->toContain($secret, $appInstance->production_home)
        ->and($program)
        ->toContain(
            base64_encode("APP_KEY=\"{$secret}\"\n"),
            'emit release_selection_matches',
            'emit php_fpm_matches',
            'loaded_service_matches',
            'systemctl show --property=ExecStart --value',
            'systemctl show --property=Environment --value',
            'process_runtime_matches',
            'proc_root=/proc',
            '$proc_root/net/unix',
            '$proc_root/$main_pid/fd/',
            'worker_identity_matches',
            '/^Gid:/',
            '$proc_root/$worker_pid/root',
            'local_tuning_matches || return 1',
            'chdir|chroot|env\[home\]',
        )
        ->not->toContain(
            $secret,
            ' -t ',
            'cgi-fcgi',
            'curl ',
            'kill ',
            '/proc/$main_pid/cmdline',
            '/proc/$main_pid/environ',
            '/var/log/php-fpm.log',
            'systemctl restart',
            'systemctl reload',
            'rm -',
            'install ',
            'mv ',
        )
        ->and(json_encode($expectation, JSON_THROW_ON_ERROR))
        ->not->toContain($secret)
        ->and($expectation->__debugInfo())
        ->toBe(['environment' => '[PROTECTED]'])
        ->and($ssh->connections[0]->commandTimeout)
        ->toBe(30.0);
});

it('executes loaded service association outcomes from the production program', function (
    string $systemctl,
    string $expected,
): void {
    $program = application_production_observation_program('loaded_service_matches', $systemctl);
    $result = application_run(['bash'], $program);

    expect($result->stdout)->toBe("{$expected}\n");
})->with([
    'loaded command and environment match' => [
        <<<'BASH'
            systemctl() {
                case "$2" in
                    --property=ExecStart)
                        printf '{ path=%s ; argv[]=%s --nodaemonize --fpm-config %s/php-fpm.conf ; }\n' "$executable" "$executable" "$generated_directory"
                        ;;
                    --property=Environment) printf '%s\n' "$expected_environment" ;;
                    *) return 1 ;;
                esac
            }
            BASH,
        '1',
    ],
    'recognizable loaded command mismatches' => [
        <<<'BASH'
            systemctl() {
                case "$2" in
                    --property=ExecStart) printf '{ path=/usr/sbin/php-fpm8.4 ; argv[]=/usr/sbin/php-fpm8.4 ; }\n' ;;
                    --property=Environment) printf '%s\n' "$expected_environment" ;;
                    *) return 1 ;;
                esac
            }
            BASH,
        '0',
    ],
    'unreadable loaded command is unavailable' => [
        <<<'BASH'
            systemctl() { return 1; }
            BASH,
        '2',
    ],
]);

it('executes active service and main PID outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-service-'.Str::uuid();
    $runtime = "{$sandbox}/runtime";
    $generated = "{$runtime}/generated";
    $counter = "{$sandbox}/main-pid-count";
    $files = new Filesystem;
    $files->makeDirectory($generated, 0o755, true);
    file_put_contents($counter, '0');
    $activeState = $condition === 'inactive' ? 'inactive' : 'active';
    $mainPid = $condition === 'malformed PID' ? 'private-invalid' : '620';
    $activeResult = $condition === 'unreadable state' ? 'return 1' : "printf '%s\\n' '{$activeState}'";
    $pidResult = $condition === 'changed PID'
        ? <<<'BASH'
            count=$(cat "$counter")
            count=$((count + 1))
            printf '%s' "$count" > "$counter"
            if test "$count" -eq 1; then printf '620\n'; else printf '621\n'; fi
            BASH
        : "printf '%s\\n' '{$mainPid}'";
    $setup = <<<BASH
        runtime_directory={$runtime}
        generated_directory={$generated}
        counter={$counter}
        exact_file() { return 0; }
        local_tuning_matches() { return 0; }
        loaded_service_matches() { return 0; }
        process_runtime_matches() { return 0; }
        worker_identity_matches() { return 0; }
        socket_service_matches() { return 0; }
        stat() { printf 'root:root:755\n'; }
        systemctl() {
            case "\$2" in
                --property=ActiveState) {$activeResult} ;;
                --property=User) printf '\n' ;;
                --property=MainPID) {$pidResult} ;;
                *) return 1 ;;
            esac
        }
        BASH;

    try {
        $program = application_production_observation_program('php_fpm_matches', $setup);
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n");
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'active service with stable main PID' => ['matches', '1'],
    'inactive service is drift' => ['inactive', '0'],
    'unreadable active state is unavailable' => ['unreadable state', '2'],
    'malformed main PID is unavailable' => ['malformed PID', '2'],
    'changed main PID is unavailable' => ['changed PID', '2'],
]);

it('executes stable master process outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-master-'.Str::uuid();
    $procRoot = "{$sandbox}/proc";
    $mainPid = 610;
    $counter = "{$sandbox}/start-count";
    $files = new Filesystem;
    $files->makeDirectory("{$procRoot}/{$mainPid}", 0o755, true);
    file_put_contents("{$procRoot}/{$mainPid}/stat", application_process_stat($mainPid, 1, 8001));
    file_put_contents("{$procRoot}/{$mainPid}/status", "Uid:\t0\t0\t0\t0\n");
    symlink($condition === 'executable mismatch' ? '/usr/bin/bash' : '/usr/sbin/php-fpm8.5', "{$procRoot}/{$mainPid}/exe");
    if ($condition === 'missing status') {
        unlink("{$procRoot}/{$mainPid}/status");
    }
    $stability = '';
    if ($condition === 'changed start time') {
        file_put_contents($counter, '0');
        $stability = <<<BASH
            counter={$counter}
            process_start_time() {
                count=\$(cat "\$counter")
                count=\$((count + 1))
                printf '%s' "\$count" > "\$counter"
                if test "\$count" -eq 1; then printf '8001\n'; else printf '8002\n'; fi
            }
            BASH;
    }

    try {
        $setup = "proc_root={$procRoot}\nmain_pid={$mainPid}\n{$stability}";
        $program = application_production_observation_program('process_runtime_matches', $setup);
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n");
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'expected executable root identity and stable start time' => ['matches', '1'],
    'executable mismatch' => ['executable mismatch', '0'],
    'missing status is unavailable' => ['missing status', '2'],
    'changed start time is unavailable' => ['changed start time', '2'],
]);

it('executes current worker identity outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-workers-'.Str::uuid();
    $procRoot = "{$sandbox}/proc";
    $mainPid = 410;
    $workerPid = 411;
    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;
    $files = new Filesystem;
    $files->makeDirectory("{$procRoot}/{$mainPid}/task/{$mainPid}", 0o755, true);
    $files->makeDirectory("{$procRoot}/{$workerPid}", 0o755, true);
    file_put_contents("{$procRoot}/{$mainPid}/task/{$mainPid}/children", $condition === 'idle' ? '' : "{$workerPid}\n");

    if ($condition !== 'idle') {
        $uid = $condition === 'uid mismatch' ? posix_geteuid() + 1 : posix_geteuid();
        $gid = $condition === 'gid mismatch' ? posix_getegid() + 1 : posix_getegid();
        $parent = $condition === 'reparented' ? $mainPid + 1 : $mainPid;
        $status = "PPid:\t{$parent}\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\nGid:\t{$gid}\t{$gid}\t{$gid}\t{$gid}\n";
        file_put_contents("{$procRoot}/{$workerPid}/status", $status);
        file_put_contents("{$procRoot}/{$workerPid}/stat", application_process_stat($workerPid, $parent, 9001));
        symlink($condition === 'root mismatch' ? $sandbox : '/', "{$procRoot}/{$workerPid}/root");

        if ($condition === 'missing status') {
            unlink("{$procRoot}/{$workerPid}/status");
        }
    }

    try {
        $setup = sprintf(
            "proc_root=%s\nmain_pid=%d\nuser=%s",
            escapeshellarg($procRoot),
            $mainPid,
            escapeshellarg($user),
        );
        $program = application_production_observation_program('worker_identity_matches', $setup);
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n")
            ->and($group)->not->toBeEmpty();
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'idle ondemand pool' => ['idle', '1'],
    'matching UID GID and root' => ['matches', '1'],
    'UID mismatch' => ['uid mismatch', '0'],
    'GID mismatch' => ['gid mismatch', '0'],
    'process root mismatch' => ['root mismatch', '0'],
    'disappearing status' => ['missing status', '2'],
    'reparented worker' => ['reparented', '2'],
]);

it('executes socket and service association outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-socket-'.Str::uuid();
    $procRoot = "{$sandbox}/proc";
    $mainPid = 510;
    $socket = "{$sandbox}/php.sock";
    $files = new Filesystem;
    $files->makeDirectory("{$procRoot}/net", 0o755, true);
    $files->makeDirectory("{$procRoot}/{$mainPid}/fd", 0o755, true);
    $listener = stream_socket_server("unix://{$socket}", $errorCode, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException("Could not create Unix socket: {$errorCode} {$errorMessage}");
    }
    chmod($socket, 0o660);
    $metadata = stat($socket);
    if (! is_array($metadata)) {
        throw new RuntimeException('Could not inspect Unix socket fixture.');
    }
    $inode = (string) $metadata['ino'];
    file_put_contents("{$procRoot}/net/unix", "a b c d e f {$inode} {$socket}\n");
    symlink(
        $condition === 'mismatch' ? 'socket:[999999]' : "socket:[{$inode}]",
        "{$procRoot}/{$mainPid}/fd/8",
    );
    if ($condition === 'unavailable') {
        unlink("{$procRoot}/net/unix");
    }
    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;

    try {
        $setup = sprintf(
            "proc_root=%s\nmain_pid=%d\nsocket=%s\nuser=%s",
            escapeshellarg($procRoot),
            $mainPid,
            escapeshellarg($socket),
            escapeshellarg($user),
        );
        $program = application_production_observation_program('socket_service_matches', $setup);
        $program = str_replace(
            'test "$socket_metadata" = "$user:caddy:660"',
            'test "$socket_metadata" = "$user:'.addslashes($group).':660"',
            $program,
        );
        $result = application_run(['bash'], $program);

        expect($result->stdout)->toBe("{$expected}\n");
    } finally {
        fclose($listener);
        $files->deleteDirectory($sandbox);
    }
})->with([
    'master owns expected socket inode' => ['matches', '1'],
    'master owns another socket' => ['mismatch', '0'],
    'socket table disappears' => ['unavailable', '2'],
]);

it('maps each production projection without retaining protected diagnostics', function (
    string $remote,
    string $field,
): void {
    $appInstance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result($remote)]);

    $inspection = application_instance_inspector($ssh)->inspect($appInstance);

    expect($inspection->{$field})
        ->toBeFalse()
        ->and(json_encode($inspection, JSON_THROW_ON_ERROR))
        ->not->toContain('private-production-value');
})->with([
    'production home' => ["0\n1\n1\n1\n1\n1\n", 'productionHomeMatches'],
    'release selection' => ["1\n0\n1\n1\n1\n1\n", 'releaseSelectionMatches'],
    'selected release root' => ["1\n1\n0\n1\n1\n1\n", 'selectedReleaseRootMatches'],
    'environment' => ["1\n1\n1\n0\n1\n1\n", 'environmentProjectionMatches'],
    'PHP-FPM' => ["1\n1\n1\n1\n0\n1\n", 'phpFpmProjectionMatches'],
    'Caddy' => ["1\n1\n1\n1\n1\n0\n", 'caddyProjectionMatches'],
]);

it('keeps an unavailable production runtime observation distinct from drift', function (): void {
    $appInstance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("0\n1\n1\n1\n2\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($appInstance);

    expect($inspection->productionHomeMatches)
        ->toBeFalse()
        ->and($inspection->phpFpmProjectionMatches)
        ->toBeNull()
        ->and(json_encode($inspection, JSON_THROW_ON_ERROR))
        ->not->toContain('private-production-value');
});

it('fails production inspection closed for malformed, failed, truncated, and diagnostic output', function (
    CommandResult $result,
): void {
    $appInstance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );

    $exception = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([$result]),
        )->inspect($appInstance),
    );

    application_assert_sanitized($exception, 'private-production-value');
})->with([
    'failure' => [app_inspector_result('', exitCode: 1, stderr: 'private-production-value')],
    'malformed' => [app_inspector_result('private-production-value')],
    'truncated' => [app_inspector_result("1\n1\n1\n1\n1\n1\n", truncated: true)],
    'stderr' => [app_inspector_result("1\n1\n1\n1\n1\n1\n", stderr: 'private-production-value')],
]);

it('reports shared AppInstance Git administration as non-independent', function (): void {
    $appInstance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($appInstance);
    $fixture = application_instance_repository_fixture($appInstance->app->repository_url);
    $sharedGitDirectory = "{$fixture['sandbox']}/shared.git";
    $files = new Filesystem;
    $files->copyDirectory("{$fixture['checkout']}/.git", $sharedGitDirectory);
    file_put_contents("{$fixture['checkout']}/.git/commondir", "{$sharedGitDirectory}\n");

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $appInstance->app->repository_url,
                $fixture['checkout'],
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                'development',
                $fixture['startingCommit'],
                '0',
            ],
            $script,
        );

        expect($result->stdout)->toBe("1\n0\n1\n1\n");
    } finally {
        $files->deleteDirectory($fixture['sandbox']);
    }
});

it('keeps a wrong branch false when the ancestry check succeeds', function (): void {
    $appInstance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($appInstance);
    $fixture = application_instance_repository_fixture($appInstance->app->repository_url);

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $appInstance->app->repository_url,
                $fixture['checkout'],
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                'wrong-branch',
                $fixture['startingCommit'],
                '0',
            ],
            $script,
        );

        expect($result->stdout)->toBe("1\n1\n1\n0\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('keeps a symlink checkout false when ownership lookup succeeds', function (): void {
    $appInstance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($appInstance);
    $fixture = application_instance_repository_fixture($appInstance->app->repository_url);
    $symlink = "{$fixture['allowedRoot']}/acme/symlink";
    symlink($fixture['checkout'], $symlink);

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $appInstance->app->repository_url,
                $symlink,
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                'development',
                $fixture['startingCommit'],
                '0',
            ],
            $script,
        );

        expect($result->stdout)->toBe("0\n0\n1\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('keeps a non-canonical checkout false when ownership lookup succeeds', function (): void {
    $appInstance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($appInstance);
    $fixture = application_instance_repository_fixture($appInstance->app->repository_url);
    $nonCanonicalCheckout = "{$fixture['allowedRoot']}/acme/../acme/development";

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $appInstance->app->repository_url,
                $nonCanonicalCheckout,
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                'development',
                $fixture['startingCommit'],
                '0',
            ],
            $script,
        );

        expect($result->stdout)->toBe("0\n0\n1\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('observes every workspace projection in one bounded remote tuple and local DNS check', function (): void {
    [, $node, $instance, $workspace] = application_inspector_models();
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n1\n")]);
    $processes = new ApplicationInspectorProcessRunner(app_inspector_result("1\n"));

    $inspection = application_workspace_inspector($ssh, $processes)->inspect($workspace);

    expect($inspection)
        ->toEqual(new WorkspaceInspectionData(true, true, true, true, true, true, true, true))
        ->and($ssh->commands[0]->arguments)
        ->toContain(
            $instance->checkout_path,
            $workspace->checkout_path,
            $workspace->branch,
            base64_encode(application_workspace_caddy($workspace)),
            base64_encode(application_workspace_fpm($workspace)),
        )
        ->and($ssh->commands[0]->input)
        ->toContain('worktree list --porcelain', 'symbolic-ref --quiet --short HEAD')
        ->not
        ->toContain($workspace->checkout_path, $workspace->branch)
        ->and($processes->invocations[0]->arguments)
        ->toBe(['bash', '-seu', '--', "host-record={$workspace->hostname},{$node->wireguard_ip}"]);
});

it('maps each workspace observation field', function (
    string $remote,
    string $dns,
    WorkspaceInspectionData $expected,
): void {
    [, , , $workspace] = application_inspector_models();

    $inspection = application_workspace_inspector(
        new AppDevFakeSshExecutor([app_inspector_result($remote)]),
        new ApplicationInspectorProcessRunner(app_inspector_result($dns)),
    )->inspect($workspace);

    expect($inspection)->toEqual($expected);
})->with([
    'checkout missing' => [
        "0\n1\n1\n1\n1\n1\n1\n",
        "1\n",
        new WorkspaceInspectionData(false, true, true, true, true, true, true, true),
    ],
    'worktree missing' => [
        "1\n0\n1\n1\n1\n1\n1\n",
        "1\n",
        new WorkspaceInspectionData(true, false, true, true, true, true, true, true),
    ],
    'branch mismatch' => [
        "1\n1\n0\n1\n1\n1\n1\n",
        "1\n",
        new WorkspaceInspectionData(true, true, false, true, true, true, true, true),
    ],
    'document root missing' => [
        "1\n1\n1\n0\n1\n1\n1\n",
        "1\n",
        new WorkspaceInspectionData(true, true, true, false, true, true, true, true),
    ],
    'Caddy mismatch' => [
        "1\n1\n1\n1\n0\n1\n1\n",
        "1\n",
        new WorkspaceInspectionData(true, true, true, true, false, true, true, true),
    ],
    'PHP-FPM mismatch' => [
        "1\n1\n1\n1\n1\n0\n1\n",
        "1\n",
        new WorkspaceInspectionData(true, true, true, true, true, false, true, true),
    ],
    'certificate mismatch' => [
        "1\n1\n1\n1\n1\n1\n0\n",
        "1\n",
        new WorkspaceInspectionData(true, true, true, true, true, true, false, true),
    ],
    'DNS mismatch' => [
        "1\n1\n1\n1\n1\n1\n1\n",
        "0\n",
        new WorkspaceInspectionData(true, true, true, true, true, true, true, false),
    ],
]);

it('fails instance and workspace observations closed on remote and local errors', function (
    CommandResult $remote,
    CommandResult $local,
): void {
    [, , $instance, $workspace] = application_inspector_models();
    $appInstance = application_app_instance(application_inspector_app(), $instance->node);

    expect(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([$remote]),
        )
            ->inspect($appInstance),
    )
        ->toThrow(DoctorInspectionException::class, '');
    expect(
        fn (): WorkspaceInspectionData => application_workspace_inspector(
            new AppDevFakeSshExecutor([$remote]),
            new ApplicationInspectorProcessRunner($local),
        )->inspect($workspace),
    )
        ->toThrow(DoctorInspectionException::class, '');
})->with([
    'remote failure' => [app_inspector_result('', exitCode: 1, stderr: 'private'), app_inspector_result("1\n")],
    'remote malformed' => [app_inspector_result('private-output'), app_inspector_result("1\n")],
    'remote truncated' => [app_inspector_result("1\n1\n1\n1\n1\n", truncated: true), app_inspector_result("1\n")],
    'local failure' => [
        app_inspector_result("1\n1\n1\n1\n1\n1\n1\n"),
        app_inspector_result('', exitCode: 1, stderr: 'private'),
    ],
    'local malformed' => [app_inspector_result("1\n1\n1\n1\n1\n1\n1\n"), app_inspector_result('private-output')],
    'local truncated' => [
        app_inspector_result("1\n1\n1\n1\n1\n1\n1\n"),
        app_inspector_result('', truncated: true),
    ],
]);

it('redacts thrown remote and local timeouts while preserving the capped deadlines', function (): void {
    [, $node, $instance, $workspace] = application_inspector_models();
    $appInstance = application_app_instance(application_inspector_app(), $node);
    $remoteTimeout = application_timeout('remote-secret-token');
    expect((string) $remoteTimeout)->toContain('remote-secret-token');
    $remote = new ApplicationInspectorTimeoutSshExecutor($remoteTimeout);
    $remoteException = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector($remote)->inspect($appInstance),
    );
    application_assert_sanitized($remoteException, sentinel: 'remote-secret-token');
    expect($remote->connections[0]->commandTimeout)
        ->toBe(30.0)
        ->and(json_encode($remote->connections))
        ->not->toContain('sentinel');

    $workspaceRemote = new ApplicationInspectorTimeoutSshExecutor(application_timeout('workspace-remote-secret'));
    $workspaceRemoteException = application_capture_exception(
        fn (): WorkspaceInspectionData => application_workspace_inspector(
            $workspaceRemote,
            new ApplicationInspectorProcessRunner(app_inspector_result("1\n")),
        )->inspect($workspace),
    );
    application_assert_sanitized($workspaceRemoteException, sentinel: 'workspace-remote-secret');
    expect($workspaceRemote->connections[0]->commandTimeout)->toBe(30.0);

    $workspaceProcesses = new ApplicationInspectorProcessRunner(application_timeout('workspace-local-secret'));
    $workspaceLocalException = application_capture_exception(
        fn (): WorkspaceInspectionData => application_workspace_inspector(
            new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n1\n")]),
            $workspaceProcesses,
        )->inspect($workspace),
    );
    application_assert_sanitized($workspaceLocalException, sentinel: 'workspace-local-secret');
    expect($workspaceProcesses->invocations[0]->timeout)->toBe(30.0);
});

function application_timeout(string $sentinel): ProcessTimedOutException
{
    return new ProcessTimedOutException(
        new Process(['bash', '-c', $sentinel])->setTimeout(30),
        ProcessTimedOutException::TYPE_GENERAL,
    );
}

/** @param non-empty-list<string> $arguments */
function application_run(array $arguments, ?string $input = null): CommandResult
{
    $result = new NativeProcessRunner()->run(new ProcessInvocation($arguments, input: $input));
    expect($result->succeeded())->toBeTrue($result->stderr);

    return $result;
}

function application_instance_remote_script(AppInstance $appInstance): string
{
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n")]);
    application_instance_inspector($ssh)->inspect($appInstance);

    return $ssh->commands[0]->input;
}

function application_production_observation_program(string $observation, string $setup): string
{
    $appInstance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n")]);
    application_instance_inspector($ssh)->inspect($appInstance);
    $protected = $ssh->commands[0]->protectedInput;
    if (! $protected instanceof ProtectedInput) {
        throw new RuntimeException('Expected protected production inspection input.');
    }
    $program = stream_get_contents($protected->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }
    $emissions = <<<'BASH'
        emit home_matches
        emit release_selection_matches
        emit selected_root_matches
        emit environment_matches
        emit php_fpm_matches
        emit caddy_matches
        BASH;
    $replacement = trim($setup)."\nemit {$observation}";
    $program = str_replace($emissions, $replacement, $program, $count);
    if ($count !== 1) {
        throw new RuntimeException('Could not isolate the production observation.');
    }

    return $program;
}

function application_process_stat(int $pid, int $parentPid, int $startTime): string
{
    return implode(' ', [
        (string) $pid,
        '(php-fpm worker)',
        'S',
        (string) $parentPid,
        ...array_fill(0, 17, '0'),
        (string) $startTime,
    ])."\n";
}

/** @return array{sandbox: string, allowedRoot: string, checkout: string, startingCommit: string, user: string, group: string} */
function application_instance_repository_fixture(string $repository): array
{
    $sandbox = sys_get_temp_dir().'/orbit-doctor-instance-'.Str::uuid();
    $allowedRoot = "{$sandbox}/apps";
    $checkout = "{$allowedRoot}/acme/development";
    $files = new Filesystem;
    $files->makeDirectory(dirname($checkout), 0o755, true);
    application_run(['git', 'init', '--initial-branch=development', $checkout]);
    application_run(['git', '-C', $checkout, 'config', 'user.name', 'Orbit Test']);
    application_run(['git', '-C', $checkout, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents("{$checkout}/README.md", "managed\n");
    application_run(['git', '-C', $checkout, 'add', 'README.md']);
    application_run(['git', '-C', $checkout, 'commit', '-m', 'Managed']);
    application_run(['git', '-C', $checkout, 'remote', 'add', 'origin', $repository]);
    $startingCommit = trim(application_run(['git', '-C', $checkout, 'rev-parse', 'HEAD'])->stdout);
    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;

    return [
        'sandbox' => $sandbox,
        'allowedRoot' => $allowedRoot,
        'checkout' => $checkout,
        'startingCommit' => $startingCommit,
        'user' => $user,
        'group' => $group,
    ];
}

function application_capture_exception(Closure $operation): DoctorInspectionException
{
    try {
        $operation();
    } catch (DoctorInspectionException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected inspection failure.');
}

function application_assert_sanitized(DoctorInspectionException $exception, string $sentinel): void
{
    expect($exception->getMessage())
        ->toBeEmpty()
        ->and((string) $exception)
        ->not->toContain($sentinel)->and(json_encode($exception))
        ->not->toContain($sentinel);
}

/** @return array{App, Node, Instance, Workspace} */
function application_inspector_models(): array
{
    $node = application_inspector_node();
    $app = application_inspector_app();
    $instance = application_inspector_instance($app, $node, CertificateMode::OrbitCa);
    $workspace = Workspace::query()->create([
        'instance_id' => $instance->id,
        'name' => 'workspace-'.$instance->id,
        'branch' => 'feature-'.$instance->id,
        'checkout_path' => "/home/orbit/workspaces/{$instance->id}",
        'hostname' => "workspace-{$instance->id}.test",
        'status' => LifecycleStatus::Active,
    ]);

    return [$app, $node, $instance, $workspace];
}

function application_inspector_node(): Node
{
    static $number = 20;
    $number++;

    return Node::query()->create([
        'name' => "doctor-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => "10.44.0.{$number}",
        'tld' => "node-{$number}.test",
    ]);
}

function application_inspector_app(): App
{
    static $number = 0;
    $number++;

    return App::query()->create([
        'name' => "Project {$number}",
        'slug' => "project-{$number}",
        'repository_url' => "https://github.com/acme/project-{$number}.git",
    ]);
}

function application_inspector_instance(App $app, Node $node, CertificateMode $mode): Instance
{
    $name = $mode === CertificateMode::Acme ? 'production' : 'development';
    $checkout = $mode === CertificateMode::Acme
        ? "/var/www/{$app->slug}/{$name}"
        : "/srv/users/nckrtl/apps/{$app->slug}";

    $instance = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $name,
        'checkout_path' => $checkout,
        'document_root' => 'public',
        'php_version' => '8.5',
        'hostname' => "{$app->slug}-{$node->id}-{$name}.test",
        'certificate_mode' => $mode,
        'status' => LifecycleStatus::Active,
    ]);

    NodeRole::query()->create([
        'node_id' => $node->id,
        'role' => $mode === CertificateMode::Acme ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);

    return $instance;
}

function application_app_instance(App $app, Node $node): AppInstance
{
    $app->update(['default_branch' => 'main', 'root' => 'public']);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'development',
        'checkout_path' => "/srv/users/nckrtl/apps/{$app->slug}/development",
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);
}

function application_app_inspector(AppDevFakeSshExecutor $ssh): NativeAppStateInspector
{
    return new NativeAppStateInspector(
        $ssh,
        application_inspector_keys(),
        application_inspector_hosts(),
        new CommandDeadline,
        application_inspector_accounts(),
    );
}

function application_instance_inspector(
    SshExecutor $ssh,
): NativeInstanceStateInspector {
    return new NativeInstanceStateInspector(
        new AppDevSshExecutor($ssh, application_inspector_keys(), application_inspector_hosts()),
        new CommandDeadline,
        application_inspector_accounts(),
        new CheckoutRemovalBoundary(new ProtectedPathCatalog),
        app(ProductionInstanceInspectionExpectationFactory::class),
    );
}

function application_production_app_instance(App $app, Node $node, string $secret): AppInstance
{
    $app->update(['default_branch' => 'main', 'root' => 'public']);
    $user = "orbit-app-{$app->id}";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => "{$app->slug}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $secret]);

    return $instance;
}

function application_workspace_inspector(
    SshExecutor $ssh,
    ApplicationInspectorProcessRunner $processes,
): NativeWorkspaceStateInspector {
    return new NativeWorkspaceStateInspector(
        new AppDevSshExecutor($ssh, application_inspector_keys(), application_inspector_hosts()),
        $processes,
        new AppDevCaddyConfigRenderer,
        new AppDevPhpFpmConfigRenderer,
        new CommandDeadline,
        application_inspector_accounts(),
    );
}

function application_inspector_accounts(): ManagedUserAccountResolver
{
    return new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
        }
    };
}

function application_inspector_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/doctor-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
}

function application_inspector_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/doctor-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

function app_inspector_result(
    string $stdout,
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 1, $truncated);
}

function application_dev_site(Instance $instance): AppDevSite
{
    return new AppDevSite(
        $instance->node_id,
        $instance->node->wireguard_ip ?? '',
        "instance-{$instance->id}",
        $instance->checkout_path,
        $instance->document_root,
        $instance->php_version,
        $instance->hostname,
    );
}

function application_dev_caddy(Instance $instance): string
{
    return new AppDevCaddyConfigRenderer()->render(collect([application_dev_site($instance)]));
}

function application_dev_fpm(Instance $instance): string
{
    return new AppDevPhpFpmConfigRenderer()->render(
        collect([application_dev_site($instance)]),
        new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl'),
    );
}

function application_prod_site(Instance $instance): AppProdSite
{
    return new AppProdSite(
        $instance->node_id,
        $instance->app->slug,
        $instance->name,
        $instance->checkout_path,
        $instance->document_root,
        $instance->php_version,
        $instance->hostname,
        $instance->id,
    );
}

function application_prod_caddy(Instance $instance): string
{
    return new AppProdCaddyConfigRenderer()->render(collect([application_prod_site($instance)]));
}

function application_prod_fpm(Instance $instance): string
{
    return new AppProdPhpFpmConfigRenderer()->render(collect([application_prod_site($instance)]));
}

function application_workspace_site(Workspace $workspace): AppDevSite
{
    $instance = $workspace->instance;

    return new AppDevSite(
        $instance->node_id,
        $instance->node->wireguard_ip ?? '',
        "workspace-{$workspace->id}",
        $workspace->checkout_path,
        $instance->document_root,
        $workspace->php_version ?? $instance->php_version,
        $workspace->hostname,
    );
}

function application_workspace_caddy(Workspace $workspace): string
{
    return new AppDevCaddyConfigRenderer()->render(collect([application_workspace_site($workspace)]));
}

function application_workspace_fpm(Workspace $workspace): string
{
    return new AppDevPhpFpmConfigRenderer()->render(
        collect([application_workspace_site($workspace)]),
        new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl'),
    );
}

final class ApplicationInspectorProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function __construct(
        private CommandResult|Throwable $result,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class ApplicationInspectorTimeoutSshExecutor implements SshExecutor
{
    public array $connections = [];

    public function __construct(
        private Throwable $timeout,
    ) {}

    public function execute(
        SshConnection $connection,
        RemoteCommand $command,
    ): CommandResult {
        $this->connections[] = $connection;
        throw $this->timeout;
    }
}
