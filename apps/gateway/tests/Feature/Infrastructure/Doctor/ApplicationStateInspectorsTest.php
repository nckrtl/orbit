<?php

declare(strict_types=1);

use App\Domain\Doctor\AppInspectionData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\WorkspaceInspectionData;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
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
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Workspace;
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
            'https://github.com/acme/project.git',
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
        ->toBe($node->wireguard_address)
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

it('observes every app-development instance projection with shared renderers', function (): void {
    [, $node, $instance] = application_inspector_models();
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n")]);
    $processes = new ApplicationInspectorProcessRunner(app_inspector_result("1\n"));

    $inspection = application_instance_inspector($ssh, $processes)->inspect($instance);

    expect($inspection)
        ->toEqual(new InstanceInspectionData(true, true, true, true, true, true))
        ->and($ssh->commands[0]->arguments[0])
        ->toBe('sudo')
        ->and($ssh->commands[0]->arguments)
        ->toContain(
            'app-dev',
            $instance->checkout_path,
            '/etc/caddy/orbit-certificates/instance-'.$instance->id.'/current',
            base64_encode(application_dev_caddy($instance)),
            base64_encode(application_dev_fpm($instance)),
        )
        ->and($ssh->commands[0]->input)
        ->toContain('case "$mode:$checkout" in', 'contains_exact_block')
        ->not->toContain(
            $instance->checkout_path,
            $instance->hostname,
        )->and($processes->invocations[0]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            "host-record={$instance->hostname},{$node->wireguard_address}",
        ])->and($processes->invocations[0]->timeout)->toBe(30.0)->and($processes->invocations[0]->input)
        ->not->toContain($instance->hostname);
});

it('uses the tenth positional argument as the managed home in the instance script', function (): void {
    [, , $instance] = application_inspector_models();
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n")]);

    application_instance_inspector($ssh, new ApplicationInspectorProcessRunner(app_inspector_result("1\n")))
        ->inspect($instance);

    expect($ssh->commands[0]->input)
        ->toContain('managed_home=${10}')
        ->not->toContain('managed_home=$10');
});

it('maps each app-development instance observation without retaining diagnostics', function (
    string $remote,
    string $dns,
    InstanceInspectionData $expected,
): void {
    [, , $instance] = application_inspector_models();
    $ssh = new AppDevFakeSshExecutor([app_inspector_result($remote, stderr: 'private-stderr')]);
    $processes = new ApplicationInspectorProcessRunner(app_inspector_result($dns, stderr: 'private-dns'));

    $inspection = application_instance_inspector($ssh, $processes)->inspect($instance);

    expect($inspection)->toEqual($expected)->and(json_encode($inspection))->not->toContain('private');
})->with([
    'checkout missing' => ["0\n1\n1\n1\n1\n", "1\n", new InstanceInspectionData(false, true, true, true, true, true)],
    'document root missing' => [
        "1\n0\n1\n1\n1\n",
        "1\n",
        new InstanceInspectionData(true, false, true, true, true, true),
    ],
    'Caddy mismatch' => ["1\n1\n0\n1\n1\n", "1\n", new InstanceInspectionData(true, true, false, true, true, true)],
    'PHP-FPM mismatch' => ["1\n1\n1\n0\n1\n", "1\n", new InstanceInspectionData(true, true, true, false, true, true)],
    'certificate mismatch' => [
        "1\n1\n1\n1\n0\n",
        "1\n",
        new InstanceInspectionData(true, true, true, true, false, true),
    ],
    'DNS mismatch' => ["1\n1\n1\n1\n1\n", "0\n", new InstanceInspectionData(true, true, true, true, true, false)],
]);

it('uses production projections and marks certificate and private DNS not applicable', function (): void {
    $node = application_inspector_node();
    $app = application_inspector_app();
    $instance = application_inspector_instance($app, $node, CertificateMode::Acme);
    $ssh = new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n")]);
    $processes = new ApplicationInspectorProcessRunner(app_inspector_result('private-output'));

    $inspection = application_instance_inspector($ssh, $processes)->inspect($instance);

    expect($inspection)
        ->toEqual(new InstanceInspectionData(true, true, true, true, null, null))
        ->and($ssh->commands[0]->arguments)
        ->toContain(
            'app-prod',
            base64_encode(application_prod_caddy($instance)),
            base64_encode(application_prod_fpm($instance)),
        )
        ->and($processes->invocations)
        ->toBeEmpty();
});

it('fails closed when instance assignment and certificate mode disagree', function (
    string $assignment,
    CertificateMode $certificateMode,
): void {
    [, $node, $instance] = application_inspector_models();
    $instance->update([
        'certificate_mode' => $certificateMode,
    ]);
    NodeRole::query()->where('node_id', $node->id)->delete();
    if ($assignment !== 'none') {
        NodeRole::query()->create([
            'node_id' => $node->id,
            'role' => $assignment === 'conflict' ? RoleName::AppDev->value : $assignment,
            'status' => LifecycleStatus::Active,
        ]);
        if ($assignment === 'conflict') {
            NodeRole::query()->create([
                'node_id' => $node->id,
                'role' => RoleName::AppProd,
                'status' => LifecycleStatus::Active,
            ]);
        }
    }

    expect(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([app_inspector_result("1\n1\n1\n1\n1\n")]),
            new ApplicationInspectorProcessRunner(app_inspector_result("1\n")),
        )->inspect($instance),
    )
        ->toThrow(DoctorInspectionException::class, '');
})->with([
    'no assignment' => ['none', CertificateMode::OrbitCa],
    'conflicting assignments' => ['conflict', CertificateMode::OrbitCa],
    'development assignment with production mode' => [RoleName::AppDev->value, CertificateMode::Acme],
    'production assignment with development mode' => [RoleName::AppProd->value, CertificateMode::OrbitCa],
]);

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
        ->toBe(['bash', '-seu', '--', "host-record={$workspace->hostname},{$node->wireguard_address}"]);
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

    expect(
        fn (): InstanceInspectionData => application_instance_inspector(
            new AppDevFakeSshExecutor([$remote]),
            new ApplicationInspectorProcessRunner($local),
        )->inspect($instance),
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
    $remoteTimeout = application_timeout('remote-secret-token');
    expect((string) $remoteTimeout)->toContain('remote-secret-token');
    $remote = new ApplicationInspectorTimeoutSshExecutor($remoteTimeout);
    $remoteException = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector(
            $remote,
            new ApplicationInspectorProcessRunner(app_inspector_result("1\n")),
        )->inspect($instance),
    );
    application_assert_sanitized($remoteException, sentinel: 'remote-secret-token');
    expect($remote->connections[0]->commandTimeout)
        ->toBe(30.0)
        ->and(json_encode($remote->connections))
        ->not->toContain('sentinel');

    $localTimeout = application_timeout('local-secret-token');
    expect((string) $localTimeout)->toContain('local-secret-token');
    $processes = new ApplicationInspectorProcessRunner($localTimeout);
    $localException = application_capture_exception(
        fn (): InstanceInspectionData => application_instance_inspector(new AppDevFakeSshExecutor([app_inspector_result(
            "1\n1\n1\n1\n1\n",
        )]), $processes)->inspect($instance),
    );
    application_assert_sanitized($localException, sentinel: 'local-secret-token');
    expect($processes->invocations[0]->timeout)
        ->toBe(30.0)
        ->and(json_encode($localTimeout))
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
        'wireguard_address' => "10.44.0.{$number}",
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
        'repository_url' => 'https://github.com/acme/project.git',
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
    ApplicationInspectorProcessRunner $processes,
): NativeInstanceStateInspector {
    return new NativeInstanceStateInspector(
        new AppDevSshExecutor($ssh, application_inspector_keys(), application_inspector_hosts()),
        $processes,
        new AppDevCaddyConfigRenderer,
        new AppDevPhpFpmConfigRenderer,
        new AppProdCaddyConfigRenderer,
        new AppProdPhpFpmConfigRenderer,
        new CommandDeadline,
        application_inspector_accounts(),
    );
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
    return new class implements ManagedUserAccountResolver {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
        }
    };
}

function application_inspector_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider {
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
    return new class implements KnownHostsStore {
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
        $instance->node->wireguard_address ?? '',
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
        $instance->node->wireguard_address ?? '',
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
        private CommandResult|\Throwable $result,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

/** @mago-expect lint:single-class-per-file Test-local timeout fake keeps boundary coverage focused. */
final class ApplicationInspectorTimeoutSshExecutor implements \App\Infrastructure\Ssh\SshExecutor
{
    public array $connections = [];

    public function __construct(
        private \Throwable $timeout,
    ) {}

    public function execute(
        \App\Infrastructure\Ssh\SshConnection $connection,
        \App\Infrastructure\Ssh\RemoteCommand $command,
    ): CommandResult {
        $this->connections[] = $connection;
        throw $this->timeout;
    }
}
