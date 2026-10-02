<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\ProjectInspectionData;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeProjectStateInspector;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\UnixSocketDirectory;

it('checks only selected-node app projections through the fixed SSH boundary', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_app_instance($project, $node);
    application_app_instance($project, application_inspector_node(), 'other-node');
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(1, true))
        ->and($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $project->repository_url,
            $instance->checkout_path,
            '/srv/users/nckrtl/apps',
            'nckrtl',
            '',
            '',
            'app-dev',
            '/srv/users/nckrtl/apps',
        ])
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

it('excludes removing Instances from App checkout inspection', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    application_app_instance($project, $node, 'active');
    $removing = application_app_instance($project, $node, 'removing');
    application_mark_removing($removing);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(1, true))
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('excludes in-flight App checkouts while preserving settled checkout failures', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $settled = application_app_instance($project, $node, 'settled');
    $provisioning = application_app_instance($project, $node, 'provisioning');
    $provisioning->update([
        'status' => InstanceState::CheckoutPrepared,
        'provisioning_step' => 'checkout',
        'environment' => 'production',
    ]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result('', exitCode: 1)]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(1, true, [], [(int) $settled->id]))
        ->and($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->arguments[4])
        ->toBe($settled->checkout_path);
});

it('keeps per-checkout app failures bounded and continues inspecting other Instances', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $failed = application_app_instance($project, $node, 'failed');
    application_app_instance($project, $node, 'healthy');
    $ssh = new AppDevFakeSshExecutor([
        app_inspector_result('', exitCode: 1),
        app_inspector_result("1\n"),
    ]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(2, true, [], [(int) $failed->id]))
        ->and($ssh->commands)
        ->toHaveCount(2);
});

it('checks app-production origins as the app owner within its production root', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_production_app_instance($project, $node, 'base64:'.str_repeat('A', 44));
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(1, true))
        ->and($ssh->connections[0]->user)
        ->toBe('nckrtl')
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $project->repository_url,
            $instance->checkout_path,
            $instance->production_home,
            $instance->production_user,
            $project->slug,
            $instance->name,
            'app-prod',
            '',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain('sudo -u "$user" -H -- git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" config --get remote.origin.url');
});

it('returns a bounded mismatch for an app-production origin', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_production_app_instance($project, $node, 'base64:'.str_repeat('B', 44));
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("0\n")]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(1, false, [(int) $instance->id]))
        ->and($ssh->commands[0]->input)
        ->toContain('test "$(sudo -u "$user" -H -- stat -c %U "$checkout")" = "$user"');
});

it('returns a bounded app mismatch and a healthy empty selection', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_app_instance($project, $node);
    $mismatch = application_app_inspector(new AppDevFakeSshExecutor([
        app_inspector_result("0\n"),
    ]))
        ->inspect($project, $node);
    $empty = application_app_inspector(new AppDevFakeSshExecutor)->inspect($project, application_inspector_node());

    expect($mismatch)
        ->toEqual(new ProjectInspectionData(1, false, [(int) $instance->id]))
        ->and($empty)
        ->toEqual(new ProjectInspectionData(0, true));
});

it('fails app inspection closed for invalid intent and failed observations', function (
    string $repository,
    CommandResult $result,
): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $instance = application_app_instance($project, $node);
    $project->repository_url = $repository;

    $inspection = fn (): ProjectInspectionData => application_app_inspector(
        new AppDevFakeSshExecutor([$result]),
    )->inspect($project, $node);

    if ($repository === 'not a repository') {
        expect($inspection)->toThrow(DoctorInspectionException::class, '');

        return;
    }

    expect($inspection())
        ->toEqual(new ProjectInspectionData(1, true, [], [(int) $instance->id]));
})->with([
    'invalid origin' => ['not a repository', app_inspector_result("1\n")],
    'command failure' => ['https://github.com/acme/project.git', app_inspector_result('', exitCode: 1)],
    'malformed output' => ['https://github.com/acme/project.git', app_inspector_result('private-output')],
    'truncated output' => ['https://github.com/acme/project.git', app_inspector_result("1\n", truncated: true)],
]);

it('observes only Instance source evidence through the fixed SSH boundary', function (): void {
    $node = application_inspector_node();
    $instance = application_app_instance(application_inspector_app(), $node);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);

    expect($inspection)
        ->toEqual(new InstanceInspectionData(true, true, true, true))
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $instance->project->repository_url,
            $instance->checkout_path,
            '/srv/users/nckrtl/apps',
            'nckrtl',
            'nckrtl',
            $instance->source_layout,
            $instance->starting_commit,
        ])
        ->and($ssh->commands[0]->input)
        ->toContain('repository_layout_matches', 'origin_matches', 'source_identity_matches')
        ->not->toContain('caddy', 'php', 'certificate', 'dns', 'hostname');
});

it('maps each Instance source observation without retaining diagnostics', function (
    string $remote,
    InstanceInspectionData $expected,
): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $ssh = new AppDevFakeSshExecutor([app_inspector_result($remote, stderr: 'private-stderr')]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);

    expect($inspection)->toEqual($expected)->and(json_encode($inspection))->not->toContain('private');
})->with([
    'checkout missing' => ["0\n1\n1\n1\n", new InstanceInspectionData(false, true, true, true)],
    'repository not independent' => ["1\n0\n1\n1\n", new InstanceInspectionData(true, false, true, true)],
    'origin mismatch' => ["1\n1\n0\n1\n", new InstanceInspectionData(true, true, false, true)],
    'source identity mismatch' => ["1\n1\n1\n0\n", new InstanceInspectionData(true, true, true, false)],
]);

it('rejects an unavailable marker from the non-nullable development observation', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());

    expect(fn (): InstanceInspectionData => application_instance_inspector(
        new AppDevFakeSshExecutor([app_inspector_result("2\n1\n1\n1\n")]),
    )->inspect($instance))->toThrow(DoctorInspectionException::class, '');
});

it('observes production projections through fixed arguments and protected input', function (): void {
    $secret = 'doctor-production-secret';
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        $secret,
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);
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
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance);

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
        ->not->toContain($secret, $instance->production_home)
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

it('inspects non-PHP production Instances without requiring or probing a PHP-FPM service', function (): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'doctor-static-secret',
    );
    $instance->update([
        'selected_php_version' => null,
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result(implode(PHP_EOL, ['1', '1', '1', '1', '1', '1', '']))]);

    $inspection = application_instance_inspector($ssh)->inspect($instance->refresh());
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance->refresh());
    $input = $ssh->commands[0]->protectedInput;
    if (! $input instanceof ProtectedInput) {
        throw new RuntimeException('Expected protected production inspection input.');
    }
    $program = stream_get_contents($input->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }

    expect($inspection->phpFpmProjectionMatches)->toBeTrue()
        ->and($inspection->caddyProjectionMatches)->toBeTrue()
        ->and($expectation->associationMatches)->toBeTrue()
        ->and($expectation->runtime)->toBeNull()
        ->and($expectation->runtimeConfiguration)->toBeNull()
        ->and($program)->toContain("runtime_expected='0'");
});

it('inspects a Laravel package with selected PHP but no PHP-FPM identity as having no runtime', function (): void {
    $project = application_inspector_app();
    $project->update(['type' => ProjectType::LaravelPackage]);
    $instance = application_production_app_instance(
        $project,
        application_inspector_node(),
        'doctor-package-secret',
    );
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result(implode(PHP_EOL, ['1', '1', '1', '1', '1', '1', '']))]);

    $inspection = application_instance_inspector($ssh)->inspect($instance->refresh());
    $expectation = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance->refresh());
    $input = $ssh->commands[0]->protectedInput;
    if (! $input instanceof ProtectedInput) {
        throw new RuntimeException('Expected readable production inspection input.');
    }
    $program = stream_get_contents($input->stream());
    if (! is_string($program)) {
        throw new RuntimeException('Expected readable production inspection input.');
    }

    expect($inspection->phpFpmProjectionMatches)->toBeTrue()
        ->and($expectation->associationMatches)->toBeTrue()
        ->and($expectation->runtime)->toBeNull()
        ->and($expectation->runtimeConfiguration)->toBeNull()
        ->and($program)->toContain("runtime_expected='0'");
});

it('refuses PHP production inspection when its dedicated service is missing', function (): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'doctor-php-secret',
    );
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);

    expect(fn () => app(ProductionInstanceInspectionExpectationFactory::class)->make($instance->refresh()))
        ->toThrow(InvalidArgumentException::class, 'The production PHP Instance requires a dedicated PHP-FPM service and PHP version.');
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
    $children = match ($condition) {
        'idle' => '',
        'exited before a UID mismatch' => "{$workerPid} ".($workerPid + 1)."\n",
        default => "{$workerPid}\n",
    };
    file_put_contents("{$procRoot}/{$mainPid}/task/{$mainPid}/children", $children);
    $writeWorker = static function (int $pid, int $uid, string $state = 'S', int $flags = 0) use ($files, $procRoot, $mainPid, $sandbox, $condition): void {
        $gid = $condition === 'gid mismatch' ? posix_getegid() + 1 : posix_getegid();
        $parent = $condition === 'reparented' ? $mainPid + 1 : $mainPid;
        $files->makeDirectory("{$procRoot}/{$pid}", 0o755, true);
        $status = "State:\t{$state}\nPPid:\t{$parent}\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\nGid:\t{$gid}\t{$gid}\t{$gid}\t{$gid}\n";
        file_put_contents("{$procRoot}/{$pid}/status", $status);
        file_put_contents("{$procRoot}/{$pid}/stat", application_process_stat($pid, $parent, 9001, $flags));
        if ($state !== 'Z' && $flags === 0) {
            symlink($condition === 'root mismatch' ? $sandbox : '/', "{$procRoot}/{$pid}/root");
        }
    };

    match ($condition) {
        'idle', 'exited' => null,
        'exited before a UID mismatch' => $writeWorker($workerPid + 1, posix_geteuid() + 1),
        'zombie' => $writeWorker($workerPid, posix_geteuid(), 'Z'),
        'exiting' => $writeWorker($workerPid, posix_geteuid(), 'S', 0x4),
        default => $writeWorker($workerPid, $condition === 'uid mismatch' ? posix_geteuid() + 1 : posix_geteuid()),
    };

    if ($condition === 'missing status') {
        unlink("{$procRoot}/{$workerPid}/status");
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
    'unreadable status of a running worker' => ['missing status', '2'],
    'reparented worker' => ['reparented', '2'],
    'worker that exited after the children list' => ['exited', '1'],
    'worker that is exiting as a zombie' => ['zombie', '1'],
    'worker the kernel marks exiting' => ['exiting', '1'],
    'exited worker before a live UID mismatch' => ['exited before a UID mismatch', '0'],
]);

it('executes socket and service association outcomes from the production program', function (
    string $condition,
    string $expected,
): void {
    $sandbox = UnixSocketDirectory::create();
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
    $table = "Num       RefCount Protocol Flags    Type St Inode Path\n"
        ."0000000000000000: 00000002 00000000 00010000 0001 01 {$inode} {$socket}\n";
    if ($condition === 'connections in flight') {
        // Each accepted connection repeats the path with its own inode and the connected state.
        $table .= '0000000000000001: 00000003 00000000 00000000 0001 03 '.($inode + 1)." {$socket}\n"
            .'0000000000000002: 00000003 00000000 00000000 0001 03 '.($inode + 2)." {$socket}\n";
    }
    if ($condition === 'two listeners') {
        $table .= '0000000000000003: 00000002 00000000 00010000 0001 01 '.($inode + 3)." {$socket}\n";
    }
    file_put_contents("{$procRoot}/net/unix", $table);
    if ($condition === 'closed descriptor') {
        // readlink fails on an entry that is no longer a descriptor link, as when the master closes it mid-scan.
        file_put_contents("{$procRoot}/{$mainPid}/fd/7", '');
    }
    symlink(
        $condition === 'mismatch' ? 'socket:[999999]' : "socket:[{$inode}]",
        "{$procRoot}/{$mainPid}/fd/8",
    );
    if ($condition === 'unavailable') {
        unlink("{$procRoot}/net/unix");
    }
    if ($condition === 'master exited') {
        // The master exits after systemd reported its PID, so its /proc entry is gone before the descriptor scan.
        $files->deleteDirectory("{$procRoot}/{$mainPid}");
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
    'accepted connections in flight' => ['connections in flight', '1'],
    'two listening entries' => ['two listeners', '2'],
    'descriptor closed during the scan' => ['closed descriptor', '1'],
    'master that exited during the scan' => ['master exited', '2'],
]);

it('maps each production projection without retaining protected diagnostics', function (
    string $remote,
    string $field,
): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result($remote)]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);

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

it('finds each of the Instance site blocks unchanged in the one live Caddyfile', function (string $layout, string $expected): void {
    $sandbox = sys_get_temp_dir().'/orbit-doctor-caddy-'.bin2hex(random_bytes(6));
    $live = $layout === 'fragment' ? "{$sandbox}/v1/Caddyfile" : "{$sandbox}/v2/Caddyfile";
    $program = application_production_observation_program('caddy_matches', "readlink() { printf '%s\\n' '{$live}'; }");
    $instance = Instance::query()->latest('id')->firstOrFail();
    $sites = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance)->caddySites;
    $render = app(NodeCaddyfileRenderer::class)->render($instance->node)->content;
    $files = new Filesystem;
    $files->ensureDirectoryExists("{$sandbox}/v1/fragments");
    $files->ensureDirectoryExists("{$sandbox}/v2");

    try {
        expect($sites)->toHaveCount(1)
            ->and($sites[0])->toStartWith("# orbit: app-prod app-instance-{$instance->id}\n")
            ->and($render)->toContain($sites[0]);

        file_put_contents($live, match ($layout) {
            'build' => $render,
            'edit inside' => str_replace($sites[0], str_replace("\n}\n", "\n    respond hand-edit\n}\n", $sites[0]), $render),
            'edit elsewhere' => $render."\nhand.example.test {\n    respond hi\n}\n",
            'fragment' => "import {$sandbox}/v1/fragments/*.caddy\n",
        });
        file_put_contents("{$sandbox}/v1/fragments/app-dev.caddy", $sites[0]);

        expect(application_run(['bash'], $program)->stdout)->toBe("{$expected}\n");
    } finally {
        $files->deleteDirectory($sandbox);
    }
})->with([
    'a Node Caddy build that matches a fresh render' => ['build', '1'],
    'a hand edit inside the Instance site' => ['edit inside', '0'],
    'a hand edit elsewhere in the file, which role.caddy_build_drift reports' => ['edit elsewhere', '1'],
    'the fragment layout of an earlier release, which Doctor no longer reads' => ['fragment', '0'],
]);

it('keeps an unavailable production runtime observation distinct from drift', function (): void {
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("0\n1\n1\n1\n2\n1\n")]);

    $inspection = application_instance_inspector($ssh)->inspect($instance);

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
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );

    $exception = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([$result]),
        )->inspect($instance),
    );

    application_assert_sanitized($exception, 'private-production-value');
})->with([
    'failure' => [app_inspector_result('', exitCode: 1, stderr: 'private-production-value')],
    'malformed' => [app_inspector_result('private-production-value')],
    'truncated' => [app_inspector_result("1\n1\n1\n1\n1\n1\n", truncated: true)],
    'stderr' => [app_inspector_result("1\n1\n1\n1\n1\n1\n", stderr: 'private-production-value')],
]);

it('reports shared Instance Git administration as non-independent', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
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
                $instance->project->repository_url,
                $fixture['checkout'],
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                $fixture['startingCommit'],
            ],
            $script,
        );

        expect($result->stdout)->toBe("1\n0\n1\n1\n");
    } finally {
        $files->deleteDirectory($fixture['sandbox']);
    }
});

it('accepts a development checkout that switched branches within the recorded history', function (
    Closure $switch,
): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    $switch($fixture);

    try {
        $result = application_run(application_instance_source_arguments($instance, $fixture), $script);

        expect($result->stdout)->toBe("1\n1\n1\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
})->with([
    'feature branch with new commits' => [function (array $fixture): void {
        application_run(['git', '-C', $fixture['checkout'], 'switch', '--quiet', '--create', 'fix/feature']);
        application_commit($fixture['checkout'], 'feature.md');
    }],
    'branch that starts before the starting commit' => [function (array $fixture): void {
        application_run(['git', '-C', $fixture['checkout'], 'switch', '--quiet', '--create', 'maintenance/older', "{$fixture['startingCommit']}~1"]);
        application_commit($fixture['checkout'], 'maintenance.md');
    }],
    'detached HEAD' => [function (array $fixture): void {
        application_run(['git', '-C', $fixture['checkout'], 'switch', '--quiet', '--detach', "{$fixture['startingCommit']}~1"]);
    }],
]);

it('reports a development checkout whose history was replaced', function (Closure $replace): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    $replace($fixture);

    try {
        $result = application_run(application_instance_source_arguments($instance, $fixture), $script);

        expect($result->stdout)->toBe("1\n1\n1\n0\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
})->with([
    'unrelated history in the same checkout' => [function (array $fixture): void {
        application_run(['git', '-C', $fixture['checkout'], 'switch', '--quiet', '--orphan', 'unrelated']);
        application_commit($fixture['checkout'], 'unrelated.md');
    }],
    'another repository with the same origin' => [function (array $fixture): void {
        $files = new Filesystem;
        $files->deleteDirectory($fixture['checkout']);
        application_run(['git', 'init', '--quiet', '--initial-branch=development', $fixture['checkout']]);
        application_run(['git', '-C', $fixture['checkout'], 'config', 'user.name', 'Orbit Test']);
        application_run(['git', '-C', $fixture['checkout'], 'config', 'user.email', 'orbit@example.test']);
        application_run(['git', '-C', $fixture['checkout'], 'remote', 'add', 'origin', $fixture['repository']]);
        application_commit($fixture['checkout'], 'other.md');
    }],
]);

it('reports a development checkout without a recorded starting commit', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    $fixture['startingCommit'] = '';

    try {
        $result = application_run(application_instance_source_arguments($instance, $fixture), $script);

        expect($result->stdout)->toBe("1\n1\n1\n0\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('compares the configured Instance origin, not the insteadOf rewrite Git applies', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    application_run(['git', '-C', $fixture['checkout'], 'config', 'url.git@git.example.test:.insteadOf', 'https://git.example.test/']);

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $instance->project->repository_url,
                $fixture['checkout'],
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                $fixture['startingCommit'],
            ],
            $script,
        );

        expect($result->stdout)->toBe("1\n1\n1\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('checks a development checkout under the Node apps root', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $node->update(['settings' => ['apps' => ['path' => '/fast/apps']]]);
    $instance = application_app_instance($project, $node);
    $instance->update(['checkout_path' => "/fast/apps/{$project->slug}/development"]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);

    $inspection = application_app_inspector($ssh)->inspect($project, $node);

    expect($inspection)
        ->toEqual(new ProjectInspectionData(1, true))
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            $project->repository_url,
            "/fast/apps/{$project->slug}/development",
            '/fast/apps',
            'nckrtl',
            '',
            '',
            'app-dev',
            '/fast/apps',
        ]);
});

it('matches an app origin under the Node apps root despite an insteadOf rewrite', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $fixture = application_instance_repository_fixture($project->repository_url);
    application_run(['git', '-C', $fixture['checkout'], 'config', 'url.git@git.example.test:.insteadOf', 'https://git.example.test/']);
    $node->update(['settings' => ['apps' => ['path' => $fixture['allowedRoot']]]]);
    application_app_instance($project, $node)->update(['checkout_path' => $fixture['checkout']]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);
    application_app_inspector($ssh)->inspect($project, $node);

    try {
        $result = application_run($ssh->commands[0]->arguments, $ssh->commands[0]->input);

        expect($result->stdout)->toBe("1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('still reports a truly different Instance origin despite an insteadOf rule', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    application_run(['git', '-C', $fixture['checkout'], 'config', 'url.git@git.example.test:.insteadOf', 'https://git.example.test/']);
    application_run(['git', '-C', $fixture['checkout'], 'remote', 'set-url', 'origin', 'https://git.example.test/acme/other.git']);

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $instance->project->repository_url,
                $fixture['checkout'],
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                $fixture['startingCommit'],
            ],
            $script,
        );

        expect($result->stdout)->toBe("1\n1\n0\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('still reports a truly different app origin despite an insteadOf rule', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $fixture = application_instance_repository_fixture($project->repository_url);
    application_run(['git', '-C', $fixture['checkout'], 'config', 'url.git@git.example.test:.insteadOf', 'https://git.example.test/']);
    application_run(['git', '-C', $fixture['checkout'], 'remote', 'set-url', 'origin', 'https://git.example.test/acme/other.git']);
    $node->update(['settings' => ['apps' => ['path' => $fixture['allowedRoot']]]]);
    application_app_instance($project, $node)->update(['checkout_path' => $fixture['checkout']]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("0\n")]);
    application_app_inspector($ssh)->inspect($project, $node);

    try {
        $result = application_run($ssh->commands[0]->arguments, $ssh->commands[0]->input);

        expect($result->stdout)->toBe("0\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('fails app inspection for a checkout outside the effective apps root', function (): void {
    $project = application_inspector_app();
    $node = application_inspector_node();
    $fixture = application_instance_repository_fixture($project->repository_url);
    $node->update(['settings' => ['apps' => ['path' => "{$fixture['sandbox']}/configured"]]]);
    application_app_instance($project, $node)->update(['checkout_path' => $fixture['checkout']]);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n")]);
    application_app_inspector($ssh)->inspect($project, $node);

    try {
        $result = new NativeProcessRunner()->run(
            new ProcessInvocation($ssh->commands[0]->arguments, input: $ssh->commands[0]->input),
        );

        expect($ssh->commands[0]->arguments[5])->toBe("{$fixture['sandbox']}/configured")
            ->and($result->succeeded())->toBeFalse()
            ->and($result->stdout)->toBe('');
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('keeps a symlink checkout false when ownership lookup succeeds', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    $symlink = "{$fixture['allowedRoot']}/acme/symlink";
    symlink($fixture['checkout'], $symlink);

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $instance->project->repository_url,
                $symlink,
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                $fixture['startingCommit'],
            ],
            $script,
        );

        expect($result->stdout)->toBe("0\n0\n1\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('keeps a non-canonical checkout false when ownership lookup succeeds', function (): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());
    $script = application_instance_remote_script($instance);
    $fixture = application_instance_repository_fixture($instance->project->repository_url);
    $nonCanonicalCheckout = "{$fixture['allowedRoot']}/acme/../acme/development";

    try {
        $result = application_run(
            [
                'bash',
                '-seu',
                '--',
                $instance->project->repository_url,
                $nonCanonicalCheckout,
                $fixture['allowedRoot'],
                $fixture['user'],
                $fixture['group'],
                'checkout',
                $fixture['startingCommit'],
            ],
            $script,
        );

        expect($result->stdout)->toBe("0\n0\n1\n1\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['sandbox']);
    }
});

it('fails instance observations closed on remote errors', function (
    CommandResult $remote,
): void {
    $instance = application_app_instance(application_inspector_app(), application_inspector_node());

    expect(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([$remote]),
        )
            ->inspect($instance),
    )
        ->toThrow(DoctorInspectionException::class, '');
})->with([
    'remote failure' => [app_inspector_result('', exitCode: 1, stderr: 'private')],
    'remote malformed' => [app_inspector_result('private-output')],
    'remote truncated' => [app_inspector_result("1\n1\n1\n1\n1\n", truncated: true)],
]);

it('redacts thrown remote timeouts while preserving the capped deadlines', function (): void {
    $node = application_inspector_node();
    $instance = application_app_instance(application_inspector_app(), $node);
    $remoteTimeout = application_timeout('remote-secret-token');
    expect((string) $remoteTimeout)->toContain('remote-secret-token');
    $remote = new ApplicationInspectorTimeoutSshExecutor($remoteTimeout);
    $remoteException = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector($remote)->inspect($instance),
    );
    application_assert_sanitized($remoteException, sentinel: 'remote-secret-token');
    expect($remote->connections[0]->commandTimeout)
        ->toBe(30.0)
        ->and(json_encode($remote->connections))
        ->not->toContain('sentinel');
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

function application_instance_remote_script(Instance $instance): string
{
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n")]);
    application_instance_inspector($ssh)->inspect($instance);

    return $ssh->commands[0]->input;
}

function application_production_observation_program(string $observation, string $setup): string
{
    $instance = application_production_app_instance(
        application_inspector_app(),
        application_inspector_node(),
        'private-production-value',
    );
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n1\n")]);
    application_instance_inspector($ssh)->inspect($instance);
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

function application_process_stat(int $pid, int $parentPid, int $startTime, int $flags = 0): string
{
    return implode(' ', [
        (string) $pid,
        '(php-fpm worker)',
        'S',
        (string) $parentPid,
        ...array_fill(0, 4, '0'),
        (string) $flags,
        ...array_fill(0, 12, '0'),
        (string) $startTime,
    ])."\n";
}

/** @return array{sandbox: string, repository: string, allowedRoot: string, checkout: string, startingCommit: string, user: string, group: string} */
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
    application_commit($checkout, 'CHANGELOG.md');
    application_commit($checkout, 'README.md');
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
        'repository' => $repository,
        'allowedRoot' => $allowedRoot,
        'checkout' => $checkout,
        'startingCommit' => $startingCommit,
        'user' => $user,
        'group' => $group,
    ];
}

function application_commit(string $checkout, string $file): void
{
    file_put_contents("{$checkout}/{$file}", "{$file}\n");
    application_run(['git', '-C', $checkout, 'add', $file]);
    application_run(['git', '-C', $checkout, 'commit', '--quiet', '-m', "Add {$file}"]);
}

/**
 * @param  array{sandbox: string, repository: string, allowedRoot: string, checkout: string, startingCommit: string, user: string, group: string}  $fixture
 * @return non-empty-list<string>
 */
function application_instance_source_arguments(Instance $instance, array $fixture): array
{
    return [
        'bash',
        '-seu',
        '--',
        $instance->project->repository_url,
        $fixture['checkout'],
        $fixture['allowedRoot'],
        $fixture['user'],
        $fixture['group'],
        'checkout',
        $fixture['startingCommit'],
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

function application_inspector_app(): Project
{
    static $number = 0;
    $number++;

    return Project::query()->create([
        'name' => "Project {$number}",
        'slug' => "project-{$number}",
        'repository_url' => "https://git.example.test/acme/project-{$number}.git",
    ]);
}

function application_app_instance(Project $project, Node $node, string $name = 'development'): Instance
{
    $project->update(['default_branch' => 'main', 'root' => 'public']);
    if (! $node->roles()->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])->exists()) {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    }

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/users/nckrtl/apps/{$project->slug}/{$name}",
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
}

function application_mark_removing(Instance $instance): void
{
    $trigger = DB::table('sqlite_master')
        ->where('type', 'trigger')
        ->where('name', 'instances_removal_status_update')
        ->value('sql');
    expect($trigger)->toBeString();
    DB::statement('DROP TRIGGER instances_removal_status_update');

    try {
        $instance->update(['status' => InstanceState::Removing]);
    } finally {
        DB::statement($trigger);
    }
}

function application_app_inspector(AppDevFakeSshExecutor $ssh): NativeProjectStateInspector
{
    return new NativeProjectStateInspector(
        $ssh,
        application_inspector_keys(),
        application_inspector_hosts(),
        new CommandDeadline,
        application_inspector_accounts(),
        app(StorageRootResolver::class),
        new NodeSettingsNormalizer,
    );
}

function application_instance_inspector(
    SshExecutor $ssh,
): NativeInstanceStateInspector {
    return new NativeInstanceStateInspector(
        new DevelopmentSshExecutor($ssh, application_inspector_keys(), application_inspector_hosts()),
        new CommandDeadline,
        application_inspector_accounts(),
        new CheckoutRemovalBoundary(new ProtectedPathCatalog),
        app(ProductionInstanceInspectionExpectationFactory::class),
    );
}

function application_production_app_instance(Project $project, Node $node, string $secret): Instance
{
    NodeRole::query()->firstOrCreate(
        ['node_id' => $node->id, 'role' => RoleName::AppProd],
        ['status' => LifecycleStatus::Active],
    );
    $project->update(['default_branch' => 'main', 'root' => 'public']);
    $user = "orbit-app-{$project->id}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
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
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => "{$project->slug}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $secret]);

    return $instance;
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
