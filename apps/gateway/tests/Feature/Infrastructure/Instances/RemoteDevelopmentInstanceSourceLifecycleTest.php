<?php

declare(strict_types=1);

use App\Actions\Instances\RemoveInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\DependencyCopy\InstanceDependencyCopier;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Infrastructure\Instances\RemoteInstanceDependencyCopier;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/orbit-app-instance-source-'.Str::uuid();
    $this->repository = $this->sandbox.'/origin.git';
    $this->appsRoot = $this->sandbox.'/apps';
    $this->remoteOrigin = 'ssh://git@example.test/acme/site.git';
    $this->files->makeDirectory($this->sandbox, 0o755, true);
    orb76_create_remote_repository($this->sandbox, $this->repository);

    $identity = posix_getpwuid(posix_geteuid());
    $groupIdentity = posix_getgrgid(posix_getegid());
    $user = is_array($identity) && is_string($identity['name'] ?? null) ? $identity['name'] : 'orbit';
    $group = is_array($groupIdentity) && is_string($groupIdentity['name'] ?? null)
        ? $groupIdentity['name']
        : $user;
    $account = new ManagedUserAccount($user, $group, $this->sandbox.'/home');
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $this->accounts = $accounts;
    $this->transport = new Orb76LocalSourceSshExecutor($this->remoteOrigin, $this->repository);
    // Give nobody an isolated global config instead of writing its host home.
    $this->workerHome = $this->sandbox.'/worker-home';
    $this->files->makeDirectory($this->workerHome);
    chmod($this->workerHome, 0777);
    $this->transport->workerGlobalConfig = $this->workerHome.'/.gitconfig';
    $this->sourceLock = new NativeAppDevSourceOperationLock($this->sandbox.'/locks');
    $ssh = new DevelopmentSshExecutor(
        $this->transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
    $this->ssh = $ssh;
    $this->boundary = new CheckoutRemovalBoundary(new ProtectedPathCatalog);
    $this->source = new RemoteDevelopmentInstanceSourceLifecycle(
        $ssh,
        $accounts,
        $this->boundary,
        app(RepositoryReadAccess::class),
    );
    $this->removal = new RemoteDevelopmentInstanceSourceRemoval(
        $ssh,
        $accounts,
        $this->boundary,
        $this->sourceLock,
        app(RepositoryReadAccess::class),
    );

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => $user,
    ]);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => $this->remoteOrigin,
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->sandbox);
});

describe('TaskCheckWorkerUser', function (): void {
    it('restores copied dependency access before exposing a task workspace and refuses a failed ACL repair', function (bool $visitable, string $copyResult): void {
        config()->set('orbit.tasks.worker_user', null);
        $default = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'default');
        $route = Route::query()->create([
            'project_id' => $default->project_id,
            'node_id' => $default->node_id,
            'generation_basis_node_id' => $default->node_id,
            'domain' => 'default.acme.test',
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $default->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $default->update(['status' => InstanceState::Active]);
        foreach (['vendor', 'node_modules'] as $directory) {
            $path = $default->checkout_path.'/'.$directory;
            mkdir($path.'/package', 0o700, true);
            file_put_contents($path.'/package/installed', 'original');
            chmod($path.'/package/installed', 0o600);
            orb76_run(['setfacl', '-R', '-b', '-k', '--', $path]);
            expect(orb178_run_allow_failure(['sudo', '-n', '-u', 'nobody', '-H', '--', 'sh', '-c', 'test -r "$1"', 'sh', $path.'/package/installed'])->succeeded())->toBeFalse();
        }
        $this->node->update(['settings' => ['apps' => ['path' => $this->appsRoot]]]);
        $this->node->processes()->create([
            'name' => 'pi-server',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => $this->sandbox,
            'runtime_config' => ['command' => ['pi-server', 'serve']],
            'restart_policy' => 'always',
            'keep_alive' => true,
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);
        $group = Task::topLevel()->create([
            'project_id' => $this->orbitApp->id,
            'title' => 'Copy dependencies',
            'brief' => 'Use a default Instance predating worker rollout.',
            'status' => 'reserved',
            'implementer_agent_driver' => 'pi',
            'reviewer_agent_driver' => 'pi',
        ]);
        $workspace = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, TaskWorkspaceName::for($group), TaskWorkspaceName::for($group));
        $workspace->update(['task_workspace_routed' => $visitable, 'root' => $visitable ? 'public' : null]);
        config()->set('orbit.tasks.worker_user', 'nobody');
        if ($copyResult !== 'complete') {
            $bin = $this->sandbox.'/bin';
            mkdir($bin);
            if ($copyResult === 'partial') {
                file_put_contents($bin.'/cp', "#!/bin/sh\nfor argument; do case \"\$argument\" in */node_modules) exit 73;; esac; done\nexec /usr/bin/cp \"\$@\"\n");
                chmod($bin.'/cp', 0o755);
            } else {
                file_put_contents($bin.'/setfacl', '#!/bin/sh'."\n".'[ ! -d '.escapeshellarg($workspace->checkout_path.'/vendor').' ] || exit 73'."\n".'exec /usr/bin/setfacl "$@"'."\n");
                chmod($bin.'/setfacl', 0o755);
            }
            $this->transport->environment = ['PATH' => $bin.':'.getenv('PATH')];
        }
        $verifyAccess = static function (Instance $instance) use ($copyResult): void {
            foreach ($copyResult === 'partial' ? ['vendor'] : ['vendor', 'node_modules'] as $directory) {
                $path = $instance->checkout_path.'/'.$directory;
                $access = orb178_run_allow_failure(['sudo', '-n', '-u', 'nobody', '-H', '--', 'sh', '-c', 'test -w "$1"', 'sh', $path.'/package/installed']);
                expect($access->succeeded())->toBeTrue(orb76_run(['getfacl', '-p', $instance->checkout_path, $path, $path.'/package', $path.'/package/installed'])->stdout);
            }
        };
        $development = new class($verifyAccess) implements DevelopmentInstanceProvisioner
        {
            public int $reserves = 0;

            public function __construct(private readonly Closure $verifyAccess) {}

            public function reserve(Instance $instance, ?string $domain): void
            {
                ($this->verifyAccess)($instance);
                $this->reserves++;
            }

            public function complete(Instance $instance, ?string $domain, bool $setupPending = false): Instance
            {
                return $instance;
            }
        };
        app()->instance(ManagedUserAccountResolver::class, $this->accounts);
        app()->instance(AppDevSourceOperationLock::class, $this->sourceLock);
        app()->instance(DevelopmentInstanceSourceLifecycle::class, $this->source);
        app()->instance(DevelopmentInstanceProvisioner::class, $development);
        app()->instance(InstanceDependencyCopier::class, new RemoteInstanceDependencyCopier($this->transport, app(SshKeyProvider::class), app(KnownHostsStore::class)));

        $result = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, $visitable));

        if ($copyResult === 'acl-refused') {
            expect($result)->toBeNull()
                ->and($development->reserves)->toBe(0)
                ->and(file_get_contents($workspace->checkout_path.'/vendor/package/installed'))->toBe('original');

            return;
        }
        expect($result)->toBeInstanceOf(Instance::class)
            ->and($result?->id)->toBe($workspace->id)
            ->and($development->reserves)->toBe($visitable ? 1 : 0)
            ->and(is_dir($workspace->checkout_path.'/node_modules'))->toBe($copyResult === 'complete');
        $verifyAccess($workspace);
        foreach ($copyResult === 'partial' ? ['vendor'] : ['vendor', 'node_modules'] as $directory) {
            $path = $workspace->checkout_path.'/'.$directory;
            $acl = orb76_run(['getfacl', '-cp', $path.'/package'])->stdout;
            expect($acl)->toContain('default:user:nobody:rwx', 'default:user:'.$this->node->user.':rwx');
            orb76_run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'sh', '-eu', '-c', 'printf updated > "$1/package/installed"; mkdir -p "$1/new/deep"; printf created > "$1/new/deep/file"', 'sh', $path]);
            expect(file_get_contents($path.'/package/installed'))->toBe('updated')
                ->and(fileowner($path.'/new/deep/file'))->toBe(65534)
                ->and(fileowner($path))->toBe(posix_geteuid());
            file_put_contents($path.'/new/deep/file', 'managed update');
            expect(file_get_contents($path.'/new/deep/file'))->toBe('managed update');
        }
        $this->files->deleteDirectory($workspace->checkout_path);
        expect(is_dir($workspace->checkout_path))->toBeFalse();
    })->with([
        'non-visitable complete copy' => [false, 'complete'],
        'visitable complete copy' => [true, 'complete'],
        'non-visitable partial copy' => [false, 'partial'],
        'visitable partial copy' => [true, 'partial'],
        'non-visitable ACL failure' => [false, 'acl-refused'],
        'visitable ACL failure' => [true, 'acl-refused'],
    ]);

    it('keeps removal content filters on the worker while the managed account validates and deletes the tree', function (): void {
        config()->set('orbit.tasks.worker_user', null);
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-worker-removal');
        $checkout = $instance->checkout_path;
        file_put_contents($checkout.'/.gitattributes', "README.md filter=uid\n");
        orb76_run(['git', '-C', $checkout, 'add', '.gitattributes']);
        orb76_run(['git', '-C', $checkout, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'filter attributes']);
        orb76_run(['git', '-C', $checkout, 'push', '-q', $this->repository, 'HEAD:refs/heads/'.$instance->branch]);
        $users = $this->sandbox.'/worker-users';
        file_put_contents($users, '');
        (new Process(['setfacl', '-m', 'u:nobody:rw', $users]))->mustRun();
        orb76_run(['git', '-C', $checkout, 'config', 'filter.uid.clean', 'printf "%s:%s\\n" "$(id -u)" "${GIT_CONFIG_VALUE_0-absent}" >> '.escapeshellarg($users).'; cat']);
        config()->set('orbit.tasks.worker_user', 'nobody');
        $this->source->inspectPrepared($instance);
        touch($checkout.'/README.md', time() - 10);

        $member = orb180_record_source($this->removal, $instance, false);
        expect(trim((string) file_get_contents($users)))->toContain('65534:absent');
        file_put_contents($users, '');
        $this->removal->prepare($member);
        touch($checkout.'/README.md', time() - 20);
        $this->removal->finalize($member);

        expect(file_exists($checkout))->toBeFalse();
        $records = file($users, FILE_IGNORE_NEW_LINES) ?: [];
        expect($records)->not->toBeEmpty();
        foreach ($records as $record) {
            expect($record)->toBe('65534:absent');
        }
    });

    it('refuses removal content inspection when the configured worker cannot run', function (): void {
        config()->set('orbit.tasks.worker_user', null);
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-missing-worker');
        config()->set('orbit.tasks.worker_user', 'orbit-absent-task-worker');

        expect(fn () => $this->removal->inspect($instance, false))->toThrow(RuntimeConvergenceException::class)
            ->and(is_dir($instance->checkout_path))->toBeTrue();
    });
});

describe('TaskWorkspaceAcl', function (): void {
    it('trusts only the managed checkout for worker Git and removes that trust on teardown', function (string $operation): void {
        config()->set('orbit.tasks.worker_user', null);
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-worker-trust');
        $worker = ['sudo', '-n', '-u', 'nobody', '-H', '--', 'env', 'GIT_CONFIG_GLOBAL='.$this->transport->workerGlobalConfig, 'git'];
        orb76_run([...$worker, 'config', '--global', '--add', 'safe.directory', $this->sandbox.'/another-checkout']);
        config()->set('orbit.tasks.worker_user', 'nobody');

        if ($operation === 'prepare') {
            $this->source->prepare($instance, true);
        } else {
            $this->source->inspectPrepared($instance);
        }
        $this->source->inspectPrepared($instance);

        expect(trim(orb76_run([...$worker, 'config', '--global', '--get-all', 'safe.directory'])->stdout))
            ->toBe($this->sandbox.'/another-checkout'."\n".$instance->checkout_path);
        expect(trim(orb76_run([...$worker, '-C', $instance->checkout_path, 'status', '--porcelain'])->stdout))->toBe('');
        $member = orb180_record_source($this->removal, $instance, true);
        $this->removal->finalize($member);
        $this->removal->finalize($member);

        expect(trim(orb76_run([...$worker, 'config', '--global', '--get-all', 'safe.directory'])->stdout))->toBe($this->sandbox.'/another-checkout');
        expect(file_exists($instance->checkout_path))->toBeFalse();
    })->with(['prepare', 'inspect']);

    it('retries scoped trust cleanup after interruption between quarantine deletion and unset', function (string $retry): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-interrupted-trust');
        $worker = ['sudo', '-n', '-u', 'nobody', '-H', '--', 'env', 'GIT_CONFIG_GLOBAL='.$this->transport->workerGlobalConfig, 'git'];
        orb76_run([...$worker, 'config', '--global', '--add', 'safe.directory', $this->sandbox.'/another-checkout']);
        $member = orb180_record_source($this->removal, $instance, true);
        $this->transport->interruptBeforeTrustCleanup = true;

        expect(fn () => $this->removal->finalize($member))->toThrow(RuntimeConvergenceException::class);

        expect(file_exists($instance->checkout_path))->toBeFalse();
        expect(is_file(orb180_receipt_path($member)))->toBeTrue();
        expect(trim(orb76_run([...$worker, 'config', '--global', '--get-all', 'safe.directory'])->stdout))
            ->toBe($instance->checkout_path."\n".$this->sandbox.'/another-checkout');
        $configLock = $this->transport->workerGlobalConfig.'.lock';
        file_put_contents($configLock, 'another writer');
        expect(fn () => $this->removal->{$retry}($member))->toThrow(RuntimeConvergenceException::class);
        expect(trim(orb76_run([...$worker, 'config', '--global', '--get-all', 'safe.directory'])->stdout))
            ->toBe($instance->checkout_path."\n".$this->sandbox.'/another-checkout');
        unlink($configLock);
        if ($retry === 'revalidate') {
            expect($this->removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Completed);
        } else {
            expect($this->removal->finalize($member))->toBe(trim(file_get_contents(orb180_receipt_path($member))));
        }

        expect(trim(orb76_run([...$worker, 'config', '--global', '--get-all', 'safe.directory'])->stdout))->toBe($this->sandbox.'/another-checkout');
        expect(is_dir(dirname($instance->checkout_path)))->toBeFalse();
    })->with(['revalidate', 'finalize']);

    it('keeps worker-created entries writable and removable after an interrupted access grant and retry', function (string $operation): void {
        config()->set('orbit.tasks.worker_user', null);
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-partial-acl');
        if ($operation === 'inspect') {
            $this->source->prepare($instance, false);
        }
        $workerDirectory = $instance->checkout_path.'/partial-worker-directory';
        $realSetfacl = trim(orb76_run(['bash', '-c', 'command -v setfacl'])->stdout);
        $bin = $this->sandbox.'/bin';
        $this->files->makeDirectory($bin);
        file_put_contents($bin.'/setfacl', <<<'BASH'
            #!/bin/bash
            set -eu
            for argument in "$@"; do
                case "$argument" in
                    u:nobody:rwX*)
                        "$ORBIT_TEST_REAL_SETFACL" -m "u:nobody:rwx,u:$ORBIT_TEST_MANAGED_USER:rwx" -- "$ORBIT_TEST_CHECKOUT"
                        printf 'Injected ACL access failure after mutation\n' >&2
                        exit 1
                        ;;
                esac
            done
            exec "$ORBIT_TEST_REAL_SETFACL" "$@"
            BASH);
        chmod($bin.'/setfacl', 0755);
        $this->transport->environment = [
            'PATH' => $bin.':'.getenv('PATH'),
            'ORBIT_TEST_REAL_SETFACL' => $realSetfacl,
            'ORBIT_TEST_MANAGED_USER' => $this->node->user,
            'ORBIT_TEST_CHECKOUT' => $instance->checkout_path,
        ];
        config()->set('orbit.tasks.worker_user', 'nobody');
        $worker = ['sudo', '-n', '-u', 'nobody', '--'];

        try {
            expect(fn () => $operation === 'prepare'
                ? $this->source->prepare($instance, false)
                : $this->source->inspectPrepared($instance))
                ->toThrow(function (RuntimeConvergenceException $exception) use ($operation): void {
                    expect($exception->errorCode)->toBe($operation === 'prepare' ? 'instance.clone_failed' : 'instance.source_identity_invalid')
                        ->and($exception->result?->stderr)->toContain('Injected ACL access failure after mutation');
                });
            orb76_run([...$worker, 'bash', '-seu', '--', $workerDirectory], <<<'BASH'
                umask 077
                mkdir -p "$1/nested"
                printf 'worker\n' > "$1/nested/file"
                BASH);
            expect(trim(orb76_run([...$worker, 'stat', '-c', '%U', $workerDirectory.'/nested/file'])->stdout))->toBe('nobody');
            $this->transport->environment = [];

            $this->source->prepare($instance, true);
            $this->source->inspectPrepared($instance);
            file_put_contents($workerDirectory.'/nested/file', "node\n", FILE_APPEND);
            clearstatcache();
            expect(file_get_contents($workerDirectory.'/nested/file'))->toBe("worker\nnode\n")
                ->and(fileowner($workerDirectory.'/nested/file'))->toBe(65534)
                ->and(fileowner($instance->checkout_path))->toBe(posix_geteuid())
                ->and(filegroup($instance->checkout_path))->toBe(posix_getegid());
            $resolution = $this->source->resolve($instance);
            $instance->update([
                'branch' => $resolution->branch,
                'starting_commit' => $resolution->startingCommit,
                'status' => InstanceState::SourceResolved,
            ]);
            $member = orb180_record_source($this->removal, $instance->refresh(), true);

            expect($this->removal->finalize($member))->not->toBeNull()
                ->and(file_exists($instance->checkout_path))->toBeFalse();
        } finally {
            orb76_run([...$worker, 'rm', '-rf', '--', $workerDirectory]);
        }
    })->with(['prepare', 'inspect']);

    it('fails prepare and inspection when the ACL command fails', function (): void {
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-failed-acl');
        $bin = $this->sandbox.'/bin';
        $this->files->makeDirectory($bin);
        file_put_contents($bin.'/setfacl', "#!/bin/sh\nexit 1\n");
        chmod($bin.'/setfacl', 0755);
        $this->transport->environment = ['PATH' => $bin.':'.getenv('PATH')];
        config()->set('orbit.tasks.worker_user', 'nobody');

        expect(fn () => $this->source->prepare($instance, false))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_failed');
        });
        expect(fn () => $this->source->inspectPrepared($instance))->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_identity_invalid');
        });
        expect($instance->refresh()->starting_commit)->toBeNull();
    });

    it('refuses a symlink in the shared Orbit directory before granting ACLs', function (): void {
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-symlink-acl');
        $outside = $this->sandbox.'/outside';
        $this->files->makeDirectory($outside, 0700);
        symlink($outside, $instance->checkout_path.'/.git/orbit');
        config()->set('orbit.tasks.worker_user', 'nobody');

        expect(fn () => $this->source->inspectPrepared($instance))->toThrow(RuntimeConvergenceException::class);

        expect(orb76_run(['getfacl', '-cp', $outside])->stdout)->not->toContain('nobody');
    });

    it('rejects an invalid or privileged worker name before cloning', function (string $worker): void {
        config()->set('orbit.tasks.worker_user', $worker);
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-invalid-acl');

        expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);

        expect(is_dir($instance->checkout_path))->toBeFalse();
    })->with(['root', 'worker:rwX', '-R']);

    it('grants checkout access and inherited write access without changing the owner', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-acl');
        $checkout = $instance->checkout_path;
        $worker = ['sudo', '-n', '-u', 'nobody', '--'];

        orb76_run([...$worker, 'bash', '-seu', '--', $checkout], <<<'BASH'
            checkout=$1
            test -w "$checkout/README.md"
            test -w "$checkout/.git"
            test ! -w "$checkout/.git/config"
            test ! -w "$checkout/.git/hooks"
            test ! -w "$checkout/.git/hooks/pre-commit.sample"
            umask 077
            mkdir "$checkout/worker-directory"
            printf 'worker\n' > "$checkout/worker-directory/file"
            printf 'output\n' > "$checkout/.git/orbit/worker-output"
            BASH);
        file_put_contents($checkout.'/worker-directory/file', "node\n", FILE_APPEND);
        file_put_contents($checkout.'/.git/orbit/worker-output', "node\n", FILE_APPEND);
        $this->source->prepare($instance, true);
        $this->source->inspectPrepared($instance);
        clearstatcache();

        expect(file_get_contents($checkout.'/worker-directory/file'))->toBe("worker\nnode\n")
            ->and(file_get_contents($checkout.'/.git/orbit/worker-output'))->toBe("output\nnode\n")
            ->and(fileowner($checkout.'/worker-directory/file'))->toBe(65534)
            ->and(fileowner($checkout))->toBe(posix_geteuid())
            ->and(filegroup($checkout))->toBe(posix_getegid())
            ->and(fileperms($checkout.'/.git/orbit') & 0777)->toBe(0775);
        $acl = orb76_run(['getfacl', '-cp', $checkout])->stdout;
        expect($acl)->toContain('user:nobody:rwx', 'default:user:nobody:rwx', 'default:user:'.$this->node->user.':rwx');
        expect(orb76_run(['getfacl', '-cp', $this->appsRoot])->stdout)->not->toContain('nobody');
    });

    it('removes a checkout containing worker-owned directories and files', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-removal-acl');
        orb76_run(['sudo', '-n', '-u', 'nobody', '--', 'bash', '-seu', '--', $instance->checkout_path], <<<'BASH'
            mkdir "$1/worker-directory"
            printf 'worker\n' > "$1/worker-directory/file"
            BASH);
        $member = orb180_record_source($this->removal, $instance, true);

        $receipt = $this->removal->finalize($member);

        expect($receipt)->not->toBeNull()
            ->and(file_exists($instance->checkout_path))->toBeFalse();
    });

    it('removes a worker-created mkdir-p tree with sticky and setgid directories under a default ACL', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-sticky-removal');
        $tree = $instance->checkout_path.'/worker-directory';
        expect(orb76_run(['getfacl', '-cp', $instance->checkout_path])->stdout)
            ->toContain('default:user:nobody:rwx', 'default:user:'.$this->node->user.':rwx');
        orb76_run(['sudo', '-n', '-u', 'nobody', '--', 'bash', '-seu', '--', $tree], <<<'BASH'
            mkdir -p "$1/nested/deep"
            printf 'worker\n' > "$1/nested/deep/file"
            # Reproduce uutils mkdir 0.8.0 on hosts whose mkdir does not inherit these bits.
            chmod g+s,+t -- "$1" "$1/nested" "$1/nested/deep"
            # An explicit 0755 mode, as Pest uses for its TIA graph, narrows the ACL mask to r-x.
            python3 -c 'import os, sys; os.mkdir(sys.argv[1], 0o755)' "$1/graph"
            printf 'worker\n' > "$1/graph/graph.json"
            BASH);
        expect(fileperms($tree.'/graph') & 0o777)->toBe(0o755);
        expect(trim(orb76_run(['stat', '-c', '%U', $tree.'/nested/deep'])->stdout))->toBe('nobody');
        expect(fileperms($tree.'/nested/deep') & 0o3000)->toBe(0o3000);
        $member = orb180_record_source($this->removal, $instance, true);
        $state = dirname(orb180_receipt_path($member));
        $workerStateAccess = ['sudo', '-n', '-u', 'nobody', '--', 'test', '-x', $state];
        expect(orb178_run_allow_failure($workerStateAccess)->succeeded())->toBeFalse();

        $receipt = $this->removal->finalize($member);

        expect($receipt)->toBe(trim(file_get_contents(orb180_receipt_path($member))));
        expect(file_exists($instance->checkout_path))->toBeFalse();
        expect(file_exists(orb180_quarantine_path($member)))->toBeFalse();
        expect(orb178_run_allow_failure($workerStateAccess)->succeeded())->toBeFalse();
    });

    it('finishes a worker-owned workspace removal after partial deletion leaves a damaged quarantined Git directory', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-partial-removal');
        orb76_run(['sudo', '-n', '-u', 'nobody', '--', 'bash', '-seu', '--', $instance->checkout_path], <<<'BASH'
            mkdir -p "$1/worker-directory/nested"
            printf 'worker\n' > "$1/worker-directory/nested/file"
            chmod g+s,+t -- "$1/worker-directory" "$1/worker-directory/nested"
            BASH);
        $member = orb180_record_source($this->removal, $instance, true);
        $this->transport->interruptDuringSourceDeletion = true;
        expect(fn () => $this->removal->finalize($member))->toThrow(RuntimeConvergenceException::class);
        $quarantine = orb180_quarantine_path($member);
        expect(is_dir($quarantine.'/.git'))->toBeTrue();
        expect(file_exists($quarantine.'/.git/HEAD'))->toBeFalse();
        expect(is_file($quarantine.'/worker-directory/nested/file'))->toBeTrue();
        expect(is_file(orb180_receipt_path($member)))->toBeTrue();
        expect($this->removal->revalidate($member))->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup);

        $receipt = $this->removal->finalize($member);

        expect($receipt)->toBe(trim(file_get_contents(orb180_receipt_path($member))));
        expect(file_exists($quarantine))->toBeFalse();
        expect(file_exists($instance->checkout_path))->toBeFalse();
        expect(is_dir(dirname($instance->checkout_path)))->toBeFalse();
        expect($this->removal->finalize($member))->toBe($receipt);
    });

    it('keeps existing behavior when the worker is unset or absent', function (?string $worker): void {
        config()->set('orbit.tasks.worker_user', $worker);
        $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'task-no-acl');

        $this->source->prepare($instance, false);
        $this->source->inspectPrepared($instance);

        expect(orb76_run(['getfacl', '-cp', $instance->checkout_path])->stdout)->not->toContain('default:user:')
            ->and(is_dir($instance->checkout_path.'/.git/orbit'))->toBeFalse();
    })->with([null, 'orbit-absent-task-worker']);

    it('restores inherited ACLs on an existing checkout during inspection', function (): void {
        config()->set('orbit.tasks.worker_user', null);
        $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'task-existing-acl');
        config()->set('orbit.tasks.worker_user', 'nobody');

        $this->source->inspectPrepared($instance);

        expect(orb76_run(['getfacl', '-cp', $instance->checkout_path.'/README.md'])->stdout)->toContain('user:nobody:rw-')
            ->and(is_dir($instance->checkout_path.'/.git/orbit'))->toBeTrue();
    });
});

it('removes a checkout_prepared failed create with its partial checkout Route and Vite reservation', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-create', 'missing');
    $this->source->prepare($instance, false);
    expect(fn () => $this->source->resolve($instance))->toThrow(RuntimeConvergenceException::class);
    $instance->update([
        'status' => InstanceState::CheckoutPrepared,
        'failed_step' => 'source_resolved',
        'error_code' => 'instance.branch_resolution_failed',
        'vite_port' => 5200,
    ]);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => 'failed-create.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Failed, 'failed_step' => 'source_resolved', 'error_code' => 'instance.branch_resolution_failed']);
    DB::table('vite_port_assignments')->insert(['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'port' => 5200]);
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'cleanup', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $teardown = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $teardown->runner());
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    $removal = $action->execute($instance, $force);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->members->sole()->runtime_published)->toBeFalse()
        ->and(is_dir($instance->checkout_path))->toBeFalse()
        ->and($teardown->inputs)->toBe([]);
    $this->assertModelMissing($instance);
    $this->assertModelMissing($route);
    $this->assertDatabaseMissing('vite_port_assignments', ['instance_id' => $instance->id]);
})->with([false, true]);

it('removes a failed reserved create whose clone never made a checkout', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-clone');
    $this->files->deleteDirectory($this->repository);
    expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);
    $instance->update(['failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    $removal = $action->execute($instance, $force);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->members->sole()->source_identity)->toBe('absent')
        ->and($removal->members->sole()->route_outcome)->toBe('none');
    $this->assertModelMissing($instance);
})->with([false, true]);

it('removes a failed in-place registration without deleting its branch or shared repository', function (bool $force): void {
    $checkout = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'shared');
    $path = $this->appsRoot.'/acme/stuck-registration';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'stuck-registration', $path, 'HEAD']);
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'stuck-registration');
    $instance->update([
        'source_layout' => 'worktree',
        'branch' => 'stuck-registration',
        'starting_commit' => $checkout->starting_commit,
        'failed_step' => 'registration',
        'error_code' => 'instance.registration_incomplete',
        'registration_request_id' => (string) Str::uuid(),
        'registration_original_path' => $path,
        'registration_authoritative_path' => $path,
        'registration_relocation_state' => 'relocating',
        'registration_repository_identity' => $this->orbitApp->repository_identity,
        'registration_common_repository_path' => $checkout->checkout_path.'/.git',
        'registration_source_digest' => str_repeat('a', 64),
        'registration_detached' => false,
    ]);
    file_put_contents($path.'/unfinished-agent-work', 'dirty adopted source');
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    if (! $force) {
        expect(fn () => $action->execute($instance, false))->toThrow(ResourceOperationException::class);
        $this->assertModelExists($instance);
        unlink($path.'/unfinished-agent-work');
    }

    $removal = $action->execute($instance, $force);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(is_dir($path))->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))->toBeTrue()
        ->and(trim(orb76_run(['git', '-C', $checkout->checkout_path, 'rev-parse', 'refs/heads/stuck-registration'])->stdout))->toBe($checkout->starting_commit);
    $this->assertModelMissing($instance);
    $this->assertModelExists($checkout);
})->with([false, true]);

it('does not authorize reserved checkout deletion from incomplete or mismatched registration evidence', function (string $mismatch): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'reserved-existing');
    $instance->update([
        'status' => InstanceState::Reserved,
        'failed_step' => 'registration',
        'error_code' => 'instance.registration_incomplete',
        'registration_request_id' => (string) Str::uuid(),
        'registration_original_path' => $instance->checkout_path,
        'registration_authoritative_path' => $instance->checkout_path,
        'registration_relocation_state' => 'reserved',
        'registration_repository_identity' => $this->orbitApp->repository_identity,
        'registration_common_repository_path' => $instance->checkout_path.'/.git',
        'registration_source_digest' => str_repeat('a', 64),
        'registration_detached' => false,
    ]);
    match ($mismatch) {
        'request' => $instance->update(['registration_request_id' => null]),
        'digest' => $instance->update(['registration_source_digest' => null]),
        'move' => $instance->update(['registration_original_path' => $this->sandbox.'/external']),
        'authority' => $instance->update(['registration_authoritative_path' => $this->sandbox.'/external']),
        'commit' => $instance->update(['starting_commit' => str_repeat('a', 40)]),
        'repository' => $instance->update(['registration_repository_identity' => 'example.test/other']),
        'common' => $instance->update(['registration_common_repository_path' => $this->sandbox.'/other/.git']),
        'detached' => $instance->update(['registration_detached' => true]),
        'clone' => $instance->update(['failed_step' => 'checkout_prepared', 'registration_request_id' => null]),
    };
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, true))->toThrow(ResourceOperationException::class)
        ->and(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertModelExists($instance);
    $this->assertDatabaseCount('instance_removals', 0);
})->with(['request', 'digest', 'move', 'authority', 'commit', 'repository', 'common', 'detached', 'clone']);

it('retries empty Project directory cleanup after absent-source finalization is interrupted', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-clone-retry');
    $this->files->deleteDirectory($this->repository);
    expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);
    $instance->update(['failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');
    $this->transport->failGroupingDirectoryCleanupOnce = true;

    expect(fn () => $action->execute($instance, false))
        ->toThrow(fn (InstanceRemovalException $exception) => expect($exception->errorCode)->toBe('instance.removal_incomplete'));

    $failedRemoval = InstanceRemoval::query()->sole();
    expect($failedRemoval->failed_step)->toBe(InstanceRemovalStep::SourceFinalization)
        ->and(is_dir(dirname($instance->checkout_path)))->toBeTrue()
        ->and(file_exists(orb180_receipt_path($failedRemoval->members->sole())))->toBeFalse();
    $this->assertModelExists($instance);

    $completed = $action->execute($instance->refresh(), false);

    expect($completed->id)->toBe($failedRemoval->id)
        ->and($completed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(is_dir(dirname($instance->checkout_path)))->toBeFalse()
        ->and(file_exists(orb180_receipt_path($completed->members->sole())))->toBeTrue();
    $this->assertModelMissing($instance);
});

it('removes a failed source_resolved create without treating its partial checkout as active source', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'failed-runtime');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update(['status' => InstanceState::SourceResolved, 'branch' => $resolution->branch, 'starting_commit' => $resolution->startingCommit, 'failed_step' => 'active', 'error_code' => 'instance.runtime_failed']);
    file_put_contents($instance->checkout_path.'/partial-runtime', 'unfinished');
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    $removal = $action->execute($instance, false);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(is_dir($instance->checkout_path))->toBeFalse();
    $this->assertModelMissing($instance);
});

it('refuses non-active creation without complete failure evidence', function (?string $step, ?string $code): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'not-failed');
    $this->source->prepare($instance, false);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'failed_step' => $step, 'error_code' => $code]);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));

    $this->assertModelExists($instance);
    expect(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertDatabaseCount('instance_removals', 0);
})->with([[null, null], ['source_resolved', null], [null, 'instance.branch_resolution_failed']]);

it('does not waive source origin ownership for a failed create', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'foreign-source');
    $this->source->prepare($instance, false);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'failed_step' => 'source_resolved', 'error_code' => 'instance.branch_resolution_failed']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'remote.origin.url', 'https://example.test/other.git']);
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.source_origin_mismatch'));

    $this->assertModelExists($instance);
    expect(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertDatabaseCount('instance_removals', 0);
})->with([false, true]);

it('refuses a failed create while its create lifecycle lock is still held', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'running-create', 'missing');
    $this->source->prepare($instance, false);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'failed_step' => 'source_resolved', 'error_code' => 'instance.branch_resolution_failed']);
    $directory = $this->sandbox.'/environment-locks';
    orb895_native_removal_action($this->removal, $this->sourceLock, $directory);
    $clock = 0.0;
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock(
        $directory,
        new CommandDeadline,
        clock: static function () use (&$clock): float {
            return $clock;
        },
        wait: static function () use (&$clock): void {
            $clock += 31.0;
        },
    ));
    $action = app(RemoveInstanceAction::class);
    $createOwner = new NativeInstanceEnvironmentOperationLock($directory, new CommandDeadline);

    $createOwner->run([$instance->id], function () use ($instance, $action, $force): void {
        expect(fn () => $action->execute($instance, $force))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('env.operation_busy'));
    });

    $this->assertModelExists($instance);
    expect(is_dir($instance->checkout_path))->toBeTrue();
    $this->assertDatabaseCount('instance_removals', 0);
})->with([false, true]);

it('creates independent clones from an existing remote branch and the exact fetched default branch', function (): void {
    $existing = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $fallback = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'feature');

    $this->source->prepare($existing, false);
    $existingResolution = $this->source->resolve($existing);
    $this->source->prepare($fallback, false);
    $fallbackResolution = $this->source->resolve($fallback);

    expect($existingResolution->branch)
        ->toBe('dev')
        ->and($existingResolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/dev'])->stdout))
        ->and($fallbackResolution->branch)
        ->toBe('feature')
        ->and($fallbackResolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout))
        ->and(is_dir($existing->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($fallback->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(dirname($existing->checkout_path))
        ->toBe(dirname($fallback->checkout_path));
});

it('clones a gh_cli Project with the Gateway GitHub CLI token only on protected input', function (?string $token): void {
    app()->instance(GitHubCliToken::class, new readonly class($token) implements GitHubCliToken
    {
        public function __construct(private ?string $token) {}

        public function token(): string
        {
            return $this->token ?? throw new ResourceOperationException('github.cli_unauthenticated', 'No login.');
        }
    });
    $recorder = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public ?string $protectedInput = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;
            $stream = $command->protectedInput?->stream();
            $this->protectedInput = is_resource($stream) ? (string) stream_get_contents($stream) : null;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
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
    $source = new RemoteDevelopmentInstanceSourceLifecycle(
        new DevelopmentSshExecutor($recorder, $keys, $knownHosts),
        $this->accounts,
        $this->boundary,
        app(RepositoryReadAccess::class),
    );
    $this->orbitApp->update([
        'repository_url' => 'git@github.com:acme/private.git',
        'source_access' => ProjectSourceAccess::GhCli,
    ]);
    $instance = orb76_source_instance($this->orbitApp->refresh(), $this->node, $this->appsRoot, 'horizon');

    if ($token === null) {
        expect(fn () => $source->prepare($instance, false))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('github.cli_unauthenticated'))
            ->and($recorder->commands)->toBe([]);

        return;
    }

    $source->prepare($instance, false);

    expect($recorder->commands)->toHaveCount(1)
        ->and($recorder->commands[0]->input)->toBeNull()
        ->and(implode(' ', $recorder->commands[0]->arguments))->not->toContain($token)
        ->and($recorder->protectedInput)->toContain(base64_encode("x-access-token:{$token}"), 'git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone');
})->with([
    'logged in' => ['gho_sentinel000000000000000000'],
    'not logged in' => [null],
]);

it('uses the Project default branch for the reserved default identity', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'default');

    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);

    expect($resolution->branch)
        ->toBe('main')
        ->and($resolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout))
        ->and($instance->checkout_path)
        ->toEndWith('/acme/default');
});

it('uses an existing explicit branch for any instance identity', function (): void {
    $instance = orb76_source_instance(
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'default',
        'dev',
    );

    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);

    expect($resolution->branch)
        ->toBe('dev')
        ->and($resolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/dev'])->stdout));
});

it('creates a task-named branch from the default branch when the remote task branch is missing', function (): void {
    $instance = orb76_source_instance(
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'task-12',
        'task-12',
    );

    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);

    expect($resolution->branch)
        ->toBe('task-12')
        ->and($resolution->startingCommit)
        ->toBe(trim(orb76_run(['git', '--git-dir='.$this->repository, 'rev-parse', 'refs/heads/main'])->stdout))
        ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'symbolic-ref', '--short', 'HEAD'])->stdout))
        ->toBe('task-12');
});

it('refuses a missing explicit branch without falling back', function (): void {
    $instance = orb76_source_instance(
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'default',
        'missing',
    );
    $this->source->prepare($instance, false);

    expect(fn () => $this->source->resolve($instance))
        ->toThrow(
            RuntimeConvergenceException::class,
            'App development step [app-instance-source-resolve] failed',
        );
});

it('makes preparation idempotent and uses only fixed source-control commands', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');

    $this->source->prepare($instance, false);
    $this->source->prepare($instance, true);
    $this->source->inspectPrepared($instance);
    $resolution = $this->source->resolve($instance);
    $this->source->inspectResolved($instance);

    $inputs = implode("\n", array_map(
        static fn (RemoteCommand $command): string => $command->input ?? '',
        $this->transport->commands,
    ));

    expect($resolution->branch)
        ->toBe('dev')
        ->and(substr_count($inputs, 'git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone --no-checkout --origin origin --'))
        ->toBe(2)
        ->and($inputs)
        ->not->toContain('caddy', 'certificate', 'dns', 'php-fpm', 'systemctl', 'hostname');

    foreach ($this->transport->commands as $command) {
        expect(array_slice($command->arguments, 0, 3))->toBe(['bash', '-seu', '--']);
    }
});

it('inspects prepared source by its configured origin despite an insteadOf rule', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    orb76_insteadof_rule($instance->checkout_path, $this->sandbox);

    $this->source->inspectPrepared($instance);
    $this->source->prepare($instance, true);

    expect($this->source->resolve($instance)->branch)->toBe('dev');

    orb76_run(['git', '-C', $instance->checkout_path, 'remote', 'set-url', 'origin', $this->sandbox.'/other.git']);

    expect(fn () => $this->source->inspectPrepared($instance))
        ->toThrow(RuntimeConvergenceException::class);
});

it('preserves pre-existing source when fresh creation fails in reserved in either removal mode', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'pre-existing');
    $this->files->makeDirectory(dirname($instance->checkout_path), 0o755, true);
    orb76_run(['git', 'clone', '--no-checkout', '--origin', 'origin', '--', $this->repository, $instance->checkout_path]);
    $sentinel = $instance->checkout_path.'/untracked-source';
    file_put_contents($sentinel, 'pre-existing work');
    expect(fn () => $this->source->prepare($instance, false))->toThrow(RuntimeConvergenceException::class);
    $instance->update(['failed_step' => 'checkout_prepared', 'error_code' => 'instance.clone_failed']);
    $before = $instance->fresh()->getAttributes();
    $action = orb895_native_removal_action($this->removal, $this->sourceLock, $this->sandbox.'/environment-locks');

    expect(fn () => $action->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));

    $this->assertModelExists($instance);
    expect($instance->fresh()->getAttributes())->toBe($before)
        ->and(is_dir($instance->checkout_path.'/.git'))->toBeTrue()
        ->and(file_get_contents($sentinel))->toBe('pre-existing work');
    $this->assertDatabaseCount('instance_removals', 0);
})->with([false, true]);

it('refuses matching pre-existing source for a fresh reservation and resumes it only after an interruption', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->files->makeDirectory(dirname($instance->checkout_path), 0o755, true);
    orb76_run([
        'git',
        'clone',
        '--no-checkout',
        '--origin',
        'origin',
        '--',
        $this->repository,
        $instance->checkout_path,
    ]);

    expect(fn () => $this->source->prepare($instance, false))
        ->toThrow(RuntimeConvergenceException::class);

    $this->source->prepare($instance, true);
    expect(is_dir($instance->checkout_path.'/.git'))->toBeTrue();
});

it('refuses dirty and unpublished source unless force is explicit', function (string $mutation): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);

    if ($mutation === 'dirty') {
        file_put_contents($instance->checkout_path.'/dirty.txt', 'dirty');
    } else {
        orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
        orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
        file_put_contents($instance->checkout_path.'/unpublished.txt', 'unpublished');
        orb76_run(['git', '-C', $instance->checkout_path, 'add', 'unpublished.txt']);
        orb76_run(['git', '-C', $instance->checkout_path, 'commit', '-m', 'Unpublished']);
    }

    expect(fn () => orb178_remove_source($this->removal, $instance, false))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))->toBeTrue();

    orb178_remove_source($this->removal, $instance, true);
    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with(['dirty', 'unpublished']);

it('names the refused identity check without waiving it in normal or forced removal', function (
    string $mutation,
    bool $force,
    string $code,
): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $decoy = $this->sandbox.'/decoy';
    $this->files->makeDirectory($decoy, 0o755, true);
    file_put_contents($decoy.'/sentinel', 'keep');

    match ($mutation) {
        'origin' => orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'ssh://git@example.test/wrong.git',
        ]),
        'symlink' => (function () use ($instance, $decoy): void {
            $this->files->deleteDirectory($instance->checkout_path);
            symlink($decoy, $instance->checkout_path);
        })(),
        'branch' => orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'elsewhere']),
    };

    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(
            function (RuntimeConvergenceException $exception) use ($code, $instance): void {
                expect($exception->errorCode)
                    ->toBe($code)
                    ->and($exception->getMessage())
                    ->toStartWith("Instance [{$instance->name}] source ")
                    ->not->toContain($instance->checkout_path, 'example.test');
            },
        );
    expect(file_exists($decoy.'/sentinel'))
        ->toBeTrue()
        ->and(file_exists($instance->checkout_path) || is_link($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal origin' => ['origin', false, 'instance.source_origin_mismatch'],
    'forced origin' => ['origin', true, 'instance.source_origin_mismatch'],
    'normal symlink' => ['symlink', false, 'instance.source_path_mismatch'],
    'forced symlink' => ['symlink', true, 'instance.source_path_mismatch'],
    'normal branch' => ['branch', false, 'instance.source_branch_mismatch'],
    'forced branch' => ['branch', true, 'instance.source_branch_mismatch'],
]);

it('refuses a checkout with shared Git administration as a layout mismatch in either mode', function (bool $force): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $sharedGitDirectory = $this->sandbox.'/shared.git';
    $this->files->copyDirectory($instance->checkout_path.'/.git', $sharedGitDirectory);
    file_put_contents($instance->checkout_path.'/.git/commondir', "{$sharedGitDirectory}\n");

    expect(trim(orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'rev-parse',
        '--git-common-dir',
    ])->stdout))
        ->toBe($sharedGitDirectory);
    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_layout_mismatch');
        });
    expect(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($sharedGitDirectory))
        ->toBeTrue();
})->with([
    'normal removal' => false,
    'force' => true,
]);

it('uses current remote publication evidence without changing the checkout index refs or object store', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $beforeRefs = orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout;
    $beforeIndex = file_get_contents($instance->checkout_path.'/.git/index');
    $tip = orb178_advance_remote($this->sandbox, 'published-descendant');
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(
        orb178_run_allow_failure([
            'git',
            '-C',
            $instance->checkout_path,
            'cat-file',
            '-e',
            "{$tip}^{commit}",
        ])->succeeded(),
    )
        ->toBeFalse()
        ->and($this->removal->inspect($instance, false)->startingCommit)
        ->toBe($resolution->startingCommit)
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout)
        ->toBe($beforeRefs)
        ->and(file_get_contents($instance->checkout_path.'/.git/index'))
        ->toBe($beforeIndex)
        ->and(
            orb178_run_allow_failure([
                'git',
                '-C',
                $instance->checkout_path,
                'cat-file',
                '-e',
                "{$tip}^{commit}",
            ])->succeeded(),
        )
        ->toBeFalse();
});

it('keeps the Git index byte-for-byte unchanged through clean inspection and later refusal', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $index = $instance->checkout_path.'/.git/index';
    expect(touch($instance->checkout_path.'/README.md', time() + 10))->toBeTrue();
    $beforeBytes = file_get_contents($index);
    $beforeMtime = orb76_run(['stat', '-c', '%y', $index])->stdout;

    expect($this->removal->inspect($instance, false)->checkoutPath)->toBe($instance->checkout_path);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(fn () => $this->removal->inspect($instance, false))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(file_get_contents($index))
        ->toBe($beforeBytes)
        ->and(orb76_run(['stat', '-c', '%y', $index])->stdout)
        ->toBe($beforeMtime)
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'status', '--porcelain=v1'])->stdout)
        ->toBe('');
});

it('skips dirty and remote publication reads for forced removal', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    file_put_contents($instance->checkout_path.'/dirty.txt', 'dirty');
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        'https://example.test/acme/site.git',
    ]);
    $this->transport->commands = [];

    $inventory = $this->removal->inspect($instance, true);
    $this->removal->remove($instance, $inventory, true);

    expect(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and($this->transport->commands)
        ->toHaveCount(2);
});

it('returns linked-worktree inventory and refuses deletion with every path and branch intact', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $worktree = $this->appsRoot.'/acme/linked';
    orb76_run(['git', '-C', $instance->checkout_path, 'worktree', 'add', '-b', 'linked', $worktree, 'HEAD']);
    $refs = orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout;

    $inventory = $this->removal->inspect($instance, true);

    expect($inventory->linkedWorktreePaths)
        ->toBe([$instance->checkout_path, $worktree])
        ->and(fn () => $this->removal->remove($instance, $inventory, true))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_worktrees_mismatch');
        })
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($worktree))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout)
        ->toBe($refs)
        ->and(orb76_run(['git', '--git-dir='.$this->repository, 'show-ref'])->stdout)
        ->toContain('refs/heads/dev');
});

it('removes a checkout whose only other worktree lost its directory', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    // A test run adds a worktree under /tmp, and /tmp is cleared before the workspace is removed.
    $scratch = $this->sandbox.'/tmp-worktree';
    orb76_run(['git', '-C', $instance->checkout_path, 'worktree', 'add', '-b', 'scratch', $scratch, 'HEAD']);
    orb76_run(['rm', '-rf', $scratch]);

    $inventory = $this->removal->inspect($instance, true);
    $this->removal->remove($instance, $inventory, true);

    expect($inventory->linkedWorktreePaths)->toBe([$instance->checkout_path])
        ->and(is_dir($instance->checkout_path))->toBeFalse();
});

it('refuses a replacement or changed canonical origin between inspection and deletion', function (
    string $mutation,
    bool $force,
    string $code,
): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $inventory = $this->removal->inspect($instance, $force);

    if ($mutation === 'replacement') {
        expect(rename($instance->checkout_path, $this->sandbox.'/original'))->toBeTrue();
        orb76_run(['git', 'clone', '--no-checkout', $this->repository, $instance->checkout_path]);
        orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'dev', $resolution->startingCommit]);
    } else {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'ssh://git@example.test/other/site.git',
        ]);
    }

    expect(fn () => $this->removal->remove($instance, $inventory, $force))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($code): void {
            expect($exception->errorCode)->toBe($code);
        })
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal replacement' => ['replacement', false, 'instance.removal_conflict'],
    'forced replacement' => ['replacement', true, 'instance.removal_conflict'],
    'normal origin change' => ['origin', false, 'instance.source_origin_mismatch'],
    'forced origin change' => ['origin', true, 'instance.source_origin_mismatch'],
]);

it('removes a checkout whose origin an insteadOf rule rewrites', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    orb76_insteadof_rule($instance->checkout_path, $this->sandbox);

    orb178_remove_source($this->removal, $instance, $force);

    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with([
    'normal' => false,
    'forced' => true,
]);

it('still refuses a different origin under an insteadOf rule', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    orb76_insteadof_rule($instance->checkout_path, $this->sandbox);
    orb76_run(['git', '-C', $instance->checkout_path, 'remote', 'set-url', 'origin', 'ssh://git@example.test/other/site.git']);

    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        })
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal' => false,
    'forced' => true,
]);

it('accepts an equivalent supported origin at the destructive boundary', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $inventory = $this->removal->inspect($instance, true);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        'https://example.test/acme/site.git',
    ]);

    $this->removal->remove($instance, $inventory, true);

    expect(file_exists($instance->checkout_path))->toBeFalse();
});

it('removes supported URL authorities', function (string $origin, string $identity, bool $force): void {
    $this->orbitApp->forceFill([
        'repository_url' => $origin,
        'repository_identity' => $identity,
    ])->save();
    $this->transport->remoteOrigin = $origin;
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);

    orb178_remove_source($this->removal, $instance, $force);

    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with([
    'normal bracketed IPv6 HTTPS' => [
        'https://[2001:db8::1]:8443/acme/site.git',
        '[2001:db8::1]/acme/site',
        false,
    ],
    'forced bracketed IPv6 HTTPS' => [
        'https://[2001:db8::1]:8443/acme/site.git',
        '[2001:db8::1]/acme/site',
        true,
    ],
    'normal bracketed IPv6 SSH' => [
        'ssh://git@[2001:db8::1]:2222/acme/site.git',
        '[2001:db8::1]/acme/site',
        false,
    ],
    'forced bracketed IPv6 SSH' => [
        'ssh://git@[2001:db8::1]:2222/acme/site.git',
        '[2001:db8::1]/acme/site',
        true,
    ],
    'normal HTTPS with an empty port' => [
        'https://example.test:/acme/site.git',
        'example.test/acme/site',
        false,
    ],
    'forced HTTPS with port zero' => [
        'https://example.test:0/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'normal HTTPS with a leading-zero port' => [
        'https://example.test:00001/acme/site.git',
        'example.test/acme/site',
        false,
    ],
    'forced HTTPS with a signed port' => [
        'https://example.test:+22/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'normal SSH with port zero' => [
        'ssh://git@example.test:0/acme/site.git',
        'example.test/acme/site',
        false,
    ],
    'forced SSH with an empty port' => [
        'ssh://git@example.test:/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'forced SSH with a leading-zero port' => [
        'ssh://git@example.test:00001/acme/site.git',
        'example.test/acme/site',
        true,
    ],
    'normal SSH with a signed port' => [
        'ssh://git@example.test:+22/acme/site.git',
        'example.test/acme/site',
        false,
    ],
]);

it('refuses malformed origin ports at the destructive boundary', function (string $origin, bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $inventory = $this->removal->inspect($instance, $force);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        $origin,
    ]);

    expect(fn () => $this->removal->remove($instance, $inventory, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal HTTPS' => ['https://example.test:notaport/acme/site.git', false],
    'forced HTTPS' => ['https://example.test:notaport/acme/site.git', true],
    'normal SSH' => ['ssh://git@example.test:notaport/acme/site.git', false],
    'forced SSH' => ['ssh://git@example.test:notaport/acme/site.git', true],
    'normal HTTPS with a six-digit port' => ['https://example.test:000022/acme/site.git', false],
    'forced SSH with a six-digit port' => ['ssh://git@example.test:000022/acme/site.git', true],
    'forced HTTPS with an out-of-range port' => ['https://example.test:65536/acme/site.git', true],
    'normal SSH with an out-of-range port' => ['ssh://git@example.test:65536/acme/site.git', false],
]);

it('rejects origins with a trailing line feed during inspection', function (string $origin, bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        "{$origin}\n",
    ]);

    expect(fn () => $this->removal->inspect($instance, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal HTTPS' => ['https://example.test/acme/site.git', false],
    'forced HTTPS' => ['https://example.test/acme/site.git', true],
    'normal SSH' => ['ssh://git@example.test/acme/site.git', false],
    'forced SSH' => ['ssh://git@example.test/acme/site.git', true],
]);

it('refuses origins with a trailing line feed at the destructive boundary', function (
    string $origin,
    bool $force,
): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $inventory = $this->removal->inspect($instance, $force);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'remote',
        'set-url',
        'origin',
        "{$origin}\n",
    ]);

    expect(fn () => $this->removal->remove($instance, $inventory, $force))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_origin_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal HTTPS' => ['https://example.test/acme/site.git', false],
    'forced HTTPS' => ['https://example.test/acme/site.git', true],
    'normal SSH' => ['ssh://git@example.test/acme/site.git', false],
    'forced SSH' => ['ssh://git@example.test/acme/site.git', true],
]);

it('finalizes one recorded checkout with durable matching evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'recorded');
    $member = orb180_record_source($this->removal, $instance, false);
    $receipt = hash(
        'sha256',
        "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    expect($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir(dirname($instance->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue()
        ->and($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and($this->removal->finalize($member))
        ->toBe($receipt);
    expect(file_get_contents(orb180_receipt_path($member)))
        ->toBe("{$receipt}\n");
});

it('removes an empty App slug parent after forced finalization of the last checkout', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'last');
    $member = orb180_record_source($this->removal, $instance, true);
    $grouping = dirname($instance->checkout_path);

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir($grouping))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('retains a Project slug parent that still holds another Orbit checkout', function (): void {
    $first = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'one');
    $second = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'two');
    $member = orb180_record_source($this->removal, $first, true);
    $grouping = dirname($first->checkout_path);

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($first->checkout_path))
        ->toBeFalse()
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($grouping))
        ->toBeTrue()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('retains a Project slug parent that still holds unrelated entries', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'only');
    $member = orb180_record_source($this->removal, $instance, true);
    $grouping = dirname($instance->checkout_path);
    file_put_contents($grouping.'/notes.txt', "keep\n");

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir($grouping))
        ->toBeTrue()
        ->and(is_file($grouping.'/notes.txt'))
        ->toBeTrue()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('removes a detached checkout with forced nullable branch evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'detached');
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--detach']);
    $instance->update(['branch' => null]);

    $inventory = orb178_remove_source($this->removal, $instance->refresh()->load(['project', 'node']), true);

    expect($inventory->branch)
        ->toBeNull()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and(is_dir(dirname($instance->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('finalizes a detached checkout from durable nullable branch evidence', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'recorded-detached',
    );
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--detach']);
    $instance->update(['branch' => null]);
    $member = orb180_record_source($this->removal, $instance->refresh()->load(['project', 'node']), false);

    $receipt = $this->removal->finalize($member);

    expect($member->branch)
        ->toBeNull()
        ->and($receipt)
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::Completed);
});

it('finalizes newer published and forced unpublished commits from immutable evidence', function (
    bool $force,
): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        $force ? 'unpublished-head' : 'published-head',
    );
    $historicalCommit = $instance->starting_commit;
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($instance->checkout_path.'/newer.txt', $force ? 'unpublished' : 'published');
    orb76_run(['git', '-C', $instance->checkout_path, 'add', 'newer.txt']);
    orb76_run(['git', '-C', $instance->checkout_path, 'commit', '-m', 'Advance source']);
    $observedCommit = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout);

    if (! $force) {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'push',
            'origin',
            "HEAD:refs/heads/{$instance->branch}",
        ]);
    }

    $member = orb180_record_source($this->removal, $instance, $force);

    expect($member->starting_commit)
        ->toBe($historicalCommit)
        ->and($member->source_commit)
        ->toBe($observedCommit)
        ->and($member->source_commit)
        ->not
        ->toBe($member->starting_commit)
        ->and($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
})->with([
    'normal removal at a newer published HEAD' => false,
    'forced removal at an unpublished HEAD' => true,
]);

it('refuses normal finalization when the observed commit is no longer published', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'withdrawn-observed-head',
    );
    $historicalCommit = $instance->starting_commit;
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($instance->checkout_path.'/newer.txt', 'published then withdrawn');
    orb76_run(['git', '-C', $instance->checkout_path, 'add', 'newer.txt']);
    orb76_run(['git', '-C', $instance->checkout_path, 'commit', '-m', 'Advance source']);
    orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'push',
        'origin',
        "HEAD:refs/heads/{$instance->branch}",
    ]);
    $member = orb180_record_source($this->removal, $instance, false);
    $observedCommit = $member->source_commit;
    $repository = $this->repository;
    $branch = $instance->branch;
    $this->transport->beforeFinalization = static function () use ($repository, $branch, $historicalCommit): void {
        orb76_run([
            'git',
            "--git-dir={$repository}",
            'update-ref',
            "refs/heads/{$branch}",
            $historicalCommit,
        ]);
    };

    expect($observedCommit)
        ->not
        ->toBe($historicalCommit)
        ->and(fn () => $this->removal->finalize($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
});

it('finalizes one recorded worktree while preserving shared Git state', function (): void {
    $checkout = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'shared');
    $worktreePath = $this->appsRoot.'/acme/feature';
    $siblingPath = $this->appsRoot.'/acme/sibling';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'feature', $worktreePath, 'HEAD']);
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'sibling', $siblingPath, 'HEAD']);
    $worktree = Instance::query()
        ->create([
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $worktreePath,
            'branch' => 'feature',
            'starting_commit' => $checkout->starting_commit,
            'status' => InstanceState::SourceResolved,
        ])
        ->load(['project', 'node']);
    $remoteBranches = orb76_run([
        'git',
        '--git-dir='.$this->repository,
        'for-each-ref',
        '--format=%(refname)',
        'refs/heads',
    ])->stdout;
    $member = orb180_record_source($this->removal, $worktree, false);

    $this->removal->finalize($member);

    $worktrees = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    expect(file_exists($worktreePath))
        ->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue()
        ->and(is_dir(dirname($worktreePath)))
        ->toBeTrue()
        ->and($worktrees)
        ->toContain("worktree {$checkout->checkout_path}")
        ->toContain("worktree {$siblingPath}")
        ->not
        ->toContain("worktree {$worktreePath}")
        ->and(
            orb76_run([
                'git',
                '-C',
                $checkout->checkout_path,
                'show-ref',
                '--verify',
                'refs/heads/feature',
            ])->succeeded(),
        )
        ->toBeTrue()
        ->and(orb76_run([
            'git',
            '--git-dir='.$this->repository,
            'for-each-ref',
            '--format=%(refname)',
            'refs/heads',
        ])->stdout)
        ->toBe($remoteBranches);
});

it('finalizes a recorded fixed set against each expected real Git inventory', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'cascade',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $remoteBranches = orb76_run([
        'git',
        '--git-dir='.$this->repository,
        'for-each-ref',
        '--format=%(refname)',
        'refs/heads',
    ])->stdout;

    $expectation = new InstanceSourceRevalidationExpectation($paths, $paths);
    expect($this->removal->revalidate($members[2], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present);
    $this->removal->prepare($members[0], $expectation);
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $firstReceipt = $this->removal->finalize($members[0], $expectation);
    $members[0]->update(['source_finalized_at' => now(), 'finalization_receipt' => $firstReceipt]);
    $afterFirst = [$checkout->checkout_path, $second->checkout_path];
    sort($afterFirst, SORT_STRING);
    $expectation = new InstanceSourceRevalidationExpectation($afterFirst, $afterFirst);
    $this->removal->prepare($members[1], $expectation);
    $members[1]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[1]);
    $members[1]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);

    expect($this->removal->revalidate($members[1], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present);
    $secondReceipt = $this->removal->finalize($members[1], $expectation);
    $members[1]->update(['source_finalized_at' => now(), 'finalization_receipt' => $secondReceipt]);
    $expectation = new InstanceSourceRevalidationExpectation(
        [$checkout->checkout_path],
        [$checkout->checkout_path],
    );
    $this->removal->prepare($members[2], $expectation);
    $members[2]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[2]);
    $members[2]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);

    expect($this->removal->revalidate($members[2], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present)
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(file_exists($first->checkout_path))
        ->toBeFalse()
        ->and(file_exists($second->checkout_path))
        ->toBeFalse();

    $this->removal->finalize($members[2], $expectation);

    expect(file_exists($checkout->checkout_path))
        ->toBeFalse()
        ->and(is_dir(dirname($checkout->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue()
        ->and($this->removal->revalidate($members[0], $expectation))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and(orb76_run([
            'git',
            '--git-dir='.$this->repository,
            'for-each-ref',
            '--format=%(refname)',
            'refs/heads',
        ])->stdout)
        ->toBe($remoteBranches);
});

it('refuses an unknown real worktree after one accepted member completes', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'unknown',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->removal->prepare(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    $unknown = $this->appsRoot.'/acme/unknown-late';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'unknown-late', $unknown, 'HEAD']);
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);

    expect(fn () => $this->removal->revalidate(
        $members[1],
        new InstanceSourceRevalidationExpectation($expected, $expected),
    ))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($unknown))
        ->toBeTrue()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue();
});

it('refuses an independent replacement at a completed member path', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'completed-replacement',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $expectation = new InstanceSourceRevalidationExpectation($paths, $paths);
    $this->removal->prepare($members[0], $expectation);
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize($members[0], $expectation);
    $members[0]->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => $receipt,
    ]);
    $first->delete();
    $members[0]->update(['runtime_cleaned_at' => now(), 'row_deleted_at' => now()]);
    orb76_run(['git', 'clone', $this->repository, $first->checkout_path]);
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);

    expect(fn () => $this->removal->revalidate(
        $members[0]->refresh(),
        new InstanceSourceRevalidationExpectation($expected, $expected),
    ))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($first->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue();
});

it('authenticates a completed worktree shrink before the database checkpoint', function (): void {
    [$checkout, $first, $second] = orb182_real_source_graph(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'receipt-cascade',
    );
    $members = orb182_record_sources($this->removal, [$first, $second, $checkout], true);
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->removal->prepare(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $this->removal->finalize(
        $members[0],
        new InstanceSourceRevalidationExpectation($paths, $paths),
    );
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);
    $states = [$members[0]->id => InstanceSourceRevalidationState::Completed];
    $expectation = new InstanceSourceRevalidationExpectation($expected, $expected, $states);

    expect($members[0]->refresh()->source_finalized_at)
        ->toBeNull()
        ->and($this->removal->revalidate($members[0], $expectation))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and($this->removal->revalidate($members[1], $expectation))
        ->toBe(InstanceSourceRevalidationState::Present)
        ->and(is_dir($second->checkout_path))
        ->toBeTrue()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue();

    $this->removal->prepare($members[1], $expectation);
    $members[1]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[1]);
    $members[1]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize($members[1], $expectation);
    $members[1]->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    $rootOnly = [$checkout->checkout_path];
    $states[$members[1]->id] = InstanceSourceRevalidationState::Completed;

    expect($this->removal->revalidate(
        $members[0]->refresh(),
        new InstanceSourceRevalidationExpectation($rootOnly, $rootOnly, $states),
    ))
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($second->checkout_path))
        ->toBeFalse();
});

it('refuses to finalize a checkout while a linked worktree depends on it', function (): void {
    $checkout = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'linked-main');
    $siblingPath = $this->appsRoot.'/acme/linked-sibling';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'linked-sibling', $siblingPath, 'HEAD']);

    expect(fn () => orb180_record_source($this->removal, $checkout, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $checkout->checkout_path, 'status', '--porcelain'])->succeeded())
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $siblingPath, 'status', '--porcelain'])->succeeded())
        ->toBeTrue();
});

it('resumes matching quarantine before and after receipt creation', function (bool $withReceipt): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'recovery');
    $member = orb180_record_source($this->removal, $instance, false);
    $quarantine = orb180_quarantine_path($member);
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    $receipt = hash(
        'sha256',
        "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    if ($withReceipt) {
        file_put_contents(orb180_receipt_path($member), "{$receipt}\n");
    }

    expect($this->removal->revalidate($member))
        ->toBe(
            $withReceipt
                ? InstanceSourceRevalidationState::ReceiptPendingCleanup
                : InstanceSourceRevalidationState::Quarantined,
        )
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse();
})->with([
    'before receipt' => false,
    'after receipt' => true,
]);

it('cleans an acknowledged checkout after its Git directory was partially deleted', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'partial-checkout',
    );
    $member = orb180_record_source($this->removal, $instance, true);
    $quarantine = orb180_quarantine_path($member);
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    $receipt = orb180_write_receipt($member);
    expect($this->files->deleteDirectory("{$quarantine}/.git"))->toBeTrue();

    expect($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse()
        ->and(is_dir(dirname($instance->checkout_path)))
        ->toBeFalse()
        ->and(is_dir($this->appsRoot))
        ->toBeTrue();
});

it('cleans an acknowledged worktree after one Git structure was partially deleted', function (string $fault): void {
    [$checkout, $worktree, $siblingPath] = orb180_worktree_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        "partial-{$fault}",
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    [$quarantine, $admin, $receipt] = orb180_stage_worktree_receipt($member);

    match ($fault) {
        'git-file' => unlink("{$quarantine}/.git"),
        'admin-entry' => $this->files->deleteDirectory($admin),
        'quarantine' => $this->files->deleteDirectory($quarantine),
        'admin-gitdir' => unlink("{$admin}/gitdir"),
    };

    expect($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $siblingPath, 'status', '--porcelain'])->succeeded())
        ->toBeTrue()
        ->and(
            orb76_run([
                'git',
                '-C',
                $checkout->checkout_path,
                'show-ref',
                '--verify',
                "refs/heads/partial-{$fault}",
            ])->succeeded(),
        )
        ->toBeTrue();
})->with(['git-file', 'admin-entry', 'quarantine', 'admin-gitdir']);

it('refuses changed or ambiguous worktree administration during receipt recovery', function (string $fault): void {
    [, $worktree, $siblingPath] = orb180_worktree_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        "metadata-{$fault}",
    );
    $member = orb180_record_source($this->removal, $worktree, true);
    [$quarantine, $admin] = orb180_stage_worktree_receipt($member);

    match ($fault) {
        'changed' => file_put_contents("{$admin}/gitdir", "{$siblingPath}/.git\n"),
        'ambiguous' => (function () use ($admin): void {
            expect($this->files->copyDirectory($admin, dirname($admin).'/duplicate'))->toBeTrue();
        })(),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($quarantine))
        ->toBeTrue()
        ->and(is_dir($siblingPath))
        ->toBeTrue();
})->with(['changed', 'ambiguous']);

it('refuses a replaced quarantine after writing the completion receipt', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'replaced-quarantine',
    );
    $member = orb180_record_source($this->removal, $instance, true);
    $quarantine = orb180_quarantine_path($member);
    $preserved = $this->sandbox.'/preserved-quarantine';
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    orb180_write_receipt($member);
    expect(rename($quarantine, $preserved))->toBeTrue();
    expect(mkdir($quarantine))->toBeTrue();

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($preserved.'/.git'))
        ->toBeTrue()
        ->and(is_dir($quarantine))
        ->toBeTrue();
});

it('refuses missing, ambiguous, or mismatched recovery evidence', function (string $fault): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'conflict');
    $member = orb180_record_source($this->removal, $instance, true);
    $preserved = $this->sandbox.'/preserved';

    match ($fault) {
        'missing' => rename($instance->checkout_path, $preserved),
        'ambiguous' => (function () use ($instance, $member): void {
            expect(rename($instance->checkout_path, orb180_quarantine_path($member)))->toBeTrue();
            mkdir($instance->checkout_path);
        })(),
        'journal' => file_put_contents(
            dirname(orb180_receipt_path($member))."/{$member->instance_removal_id}.{$member->id}.journal",
            "mismatched\n",
        ),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(
            file_exists($preserved)
            || file_exists($instance->checkout_path)
            || file_exists(orb180_quarantine_path($member)),
        )
        ->toBeTrue();
})->with(['missing', 'ambiguous', 'journal']);

it('refuses changed recorded source identity before further deletion', function (string $fault): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'changed');
    $member = orb180_record_source($this->removal, $instance, true);
    $preserved = $this->sandbox.'/preserved';

    match ($fault) {
        'physical' => (function () use ($instance, $preserved): void {
            expect(rename($instance->checkout_path, $preserved))->toBeTrue();
            orb76_run([
                'git',
                'clone',
                '--no-checkout',
                '--origin',
                'origin',
                '--',
                $this->repository,
                $instance->checkout_path,
            ]);
            orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'changed', $instance->starting_commit]);
        })(),
        'repository' => orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'https://example.test/other/repository.git',
        ]),
        'layout' => $instance->update(['source_layout' => 'worktree']),
        'placement' => $instance->update(['checkout_path' => $this->appsRoot.'/acme/replacement']),
        'canonical repository' => $instance
            ->project
            ->forceFill([
                'repository_url' => 'https://example.test/other/repository.git',
                'repository_identity' => 'example.test/other/repository',
            ])
            ->save(),
        'physical layout' => orb180_share_git_directory($instance, $this->sandbox),
        'branch' => orb76_run(['git', '-C', $instance->checkout_path, 'branch', '-m', 'changed-branch']),
        'ancestry' => orb180_replace_ancestry($instance),
        'inventory' => orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'worktree',
            'add',
            '-b',
            'late',
            $this->appsRoot.'/acme/late',
            'HEAD',
        ]),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(file_exists((string) $member->checkout_path))
        ->toBeTrue();
})->with([
    'physical',
    'repository',
    'layout',
    'placement',
    'canonical repository',
    'physical layout',
    'branch',
    'ancestry',
    'inventory',
]);

it('refuses mismatched durable completion evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'receipt');
    $member = orb180_record_source($this->removal, $instance, true);
    $this->removal->finalize($member);
    file_put_contents(orb180_receipt_path($member), "mismatched\n");

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class);
});

it('accepts a canonical-equivalent origin between retries', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'equivalent');
    $member = orb180_record_source($this->removal, $instance, true);
    $this->transport->remoteOrigin = 'ssh://git@EXAMPLE.TEST:22/acme/site.git/';

    expect($this->removal->revalidate($member))
        ->toBe(InstanceSourceRevalidationState::Present)
        ->and($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
});

it('refuses newly dirty normal worktree source before moving it', function (): void {
    [$checkout, $instance] = orb182_real_source_graph($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'normal-refusal');
    $member = orb180_record_source($this->removal, $instance, false);
    $worktrees = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    $this->transport->beforeFinalization = static function () use ($instance): void {
        file_put_contents($instance->checkout_path.'/unsafe.txt', 'keep this work');
    };

    expect(fn () => $this->removal->finalize($member))->toThrow(RuntimeConvergenceException::class);

    expect(is_dir($instance->checkout_path))->toBeTrue()
        ->and(file_exists(orb180_quarantine_path($member)))->toBeFalse()
        ->and(file_get_contents($instance->checkout_path.'/unsafe.txt'))->toBe('keep this work')
        ->and(orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout)->toBe($worktrees);
});

it('lets force finish an interrupted normal removal of a quarantined linked worktree', function (): void {
    [$checkout, $instance, $sibling] = orb182_real_source_graph($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'force-takeover');
    $member = orb180_record_source($this->removal, $instance, false);
    $runtimeCleanupIds = [];
    $action = orb895_native_removal_action(
        $this->removal,
        $this->sourceLock,
        $this->sandbox.'/removal-locks',
        cleanupRuntime: static function (InstanceRemovalMember $removed) use (&$runtimeCleanupIds): void {
            $runtimeCleanupIds[] = $removed->instance_id;
        },
    );
    $this->transport->interruptAfterSourceMove = true;

    expect(fn () => $action->execute($instance->refresh(), false, runTeardown: false))
        ->toThrow(InstanceRemovalException::class);
    $quarantine = orb180_quarantine_path($member);
    expect(is_dir($quarantine))->toBeTrue()->and(is_dir($instance->checkout_path))->toBeFalse();
    expect($member->refresh()->removal->failed_step)->toBe(InstanceRemovalStep::SourceFinalization)
        ->and($member->runtime_published)->toBeTrue()
        ->and($runtimeCleanupIds)->toBe([]);
    file_put_contents($quarantine.'/unfinished-work', 'dirty quarantined source');

    $completed = $action->execute($instance->refresh(), true, runTeardown: false);

    expect($completed->id)->toBe($member->instance_removal_id)
        ->and($completed->force)->toBeTrue()
        ->and($completed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($runtimeCleanupIds)->toBe([$instance->id])
        ->and(file_exists($quarantine))->toBeFalse()
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))->toBeTrue()
        ->and(is_dir($sibling->checkout_path))->toBeTrue();
    $worktrees = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    expect($worktrees)->not->toContain($quarantine)->not->toContain($instance->checkout_path)
        ->toContain($sibling->checkout_path);
    expect(orb76_run(['git', '-C', $checkout->checkout_path, 'show-ref', '--verify', 'refs/heads/'.$instance->branch])->succeeded())->toBeTrue();
});

it('refuses an immediate forced finalization race', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'race');
    $member = orb180_record_source($this->removal, $instance, true);
    $this->transport->beforeFinalization = static function () use ($instance): void {
        orb76_run(['git', '-C', $instance->checkout_path, 'branch', '-m', 'raced']);
    };

    expect(fn () => $this->removal->finalize($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
});

it('refuses control bytes during recorded recovery and destructive revalidation', function (string $boundary): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'control');
    $member = orb180_record_source($this->removal, $instance, true);
    $mutate = static function () use ($instance): void {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            "ssh://git@example.test/acme/site.git\n",
        ]);
    };

    if ($boundary === 'recovery') {
        $mutate();
    } else {
        $this->transport->beforeFinalization = $mutate;
    }

    $operation = $boundary === 'recovery'
        ? fn () => $this->removal->revalidate($member)
        : fn () => $this->removal->finalize($member);
    expect($operation)
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with(['recovery', 'finalization']);

it('refuses recorded ownership drift', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'ownership');
    $member = orb180_record_source($this->removal, $instance, true);
    $groups = posix_getgroups();
    $alternateGroup = is_array($groups)
        ? collect($groups)->first(static fn (int $group): bool => $group !== posix_getegid())
        : null;

    if (! is_int($alternateGroup)) {
        $this->markTestSkipped('The ownership revalidation test requires a supplementary group.');
    }

    expect(chgrp($instance->checkout_path, $alternateGroup))->toBeTrue();

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
});

it('reports foreign App ownership drift as a removal conflict before path validation', function (): void {
    $instance = orb180_resolved_source(
        $this->source,
        $this->orbitApp,
        $this->node,
        $this->appsRoot,
        'foreign-app',
    );
    $member = orb180_record_source($this->removal, $instance, true);
    $foreign = Project::query()->create([
        'name' => 'Foreign',
        'slug' => 'foreign',
        'repository_url' => 'https://example.test/foreign/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance->update(['project_id' => $foreign->id]);
    $exception = null;

    try {
        $this->removal->revalidate($member);
    } catch (RuntimeConvergenceException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(RuntimeConvergenceException::class)
        ->and($exception?->errorCode)
        ->toBe('instance.removal_conflict')
        ->and(is_dir((string) $member->checkout_path))
        ->toBeTrue();
});

it('holds the per-Node source lock for every recorded adapter call', function (): void {
    $lock = new Orb180RecordingSourceLock;
    $removal = new RemoteDevelopmentInstanceSourceRemoval(
        $this->ssh,
        $this->accounts,
        $this->boundary,
        $lock,
        app(RepositoryReadAccess::class),
    );
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'locked');
    $member = orb180_record_source($removal, $instance, true);

    expect($removal->revalidate($member))->toBe(InstanceSourceRevalidationState::Present);
    $removal->inspectRecorded($member, InstanceSourceRevalidationState::Present);
    $removal->finalize($member);

    expect($lock->nodes)
        ->toBe([
            $this->node->id,
            $this->node->id,
            $this->node->id,
            $this->node->id,
            $this->node->id,
        ]);
});

it('removes a clean checkout whose HEAD no longer descends from the recorded starting commit', function (
    bool $force,
    bool $published,
): void {
    orb178_advance_remote($this->sandbox, 'dev');
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb285_rewind_head($instance, $published);

    orb178_remove_source($this->removal, $instance, $force);

    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with([
    'normal removal at a published HEAD' => [false, true],
    'forced removal at a published HEAD' => [true, true],
    'forced removal at an unpublished HEAD' => [true, false],
]);

it('refuses normal removal of an unpublished HEAD that no longer descends from the recorded starting commit', function (): void {
    orb178_advance_remote($this->sandbox, 'dev');
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb285_rewind_head($instance, published: false);

    expect(fn () => orb178_remove_source($this->removal, $instance, false))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.remove_refused');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
});

it('finalizes a forced removal whose HEAD no longer descends from the recorded starting commit', function (): void {
    orb178_advance_remote($this->sandbox, 'dev');
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'dev');
    orb285_rewind_head($instance, published: false);
    $member = orb180_record_source($this->removal, $instance, true);

    expect($this->removal->finalize($member))
        ->toBeString()
        ->and($member->source_commit)
        ->not
        ->toBe($member->starting_commit)
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
});

it('refuses grouping-directory ownership drift before deleting the checkout', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);
    $groups = posix_getgroups();
    $alternateGroup = is_array($groups)
        ? collect($groups)->first(static fn (int $group): bool => $group !== posix_getegid())
        : null;

    if (! is_int($alternateGroup)) {
        $this->markTestSkipped('The ownership-ordering test requires a supplementary group.');
    }

    expect(chgrp(dirname($instance->checkout_path), $alternateGroup))->toBeTrue();

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('instance.source_ownership_mismatch');
        });
    expect(is_dir($instance->checkout_path))->toBeTrue();
});

it('refuses a recorded path that is outside the exact App and instance identity', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->update(['checkout_path' => $this->sandbox.'/unrelated']);

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

it('fails closed before source resolution when the stored App default branch is incomplete', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->project->default_branch = null;

    expect(fn () => $this->source->resolve($instance))->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

it('fails closed before removal when stored source identity is incomplete', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

function orb180_resolved_source(
    RemoteDevelopmentInstanceSourceLifecycle $source,
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
): Instance {
    $instance = orb76_source_instance($project, $node, $appsRoot, $name);
    $source->prepare($instance, false);
    $resolution = $source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => InstanceState::SourceResolved,
    ]);

    return $instance->refresh()->load(['project', 'node']);
}

function orb180_record_source(
    RemoteDevelopmentInstanceSourceRemoval $removal,
    Instance $instance,
    bool $force,
): InstanceRemovalMember {
    $inventory = $removal->inspect($instance, $force);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id,
        'domain' => "source-finalization-{$instance->id}.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $operation = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => $inventory->digest,
        'total' => 1,
        'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
    $member = $operation
        ->members()
        ->create([
            'position' => 0,
            'instance_id' => $instance->id,
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->defaultAppEnv(),
            'source_layout' => $inventory->layout,
            'repository_identity' => $inventory->repositoryIdentity,
            'checkout_path' => $inventory->checkoutPath,
            'root' => $instance->effectiveRoot(),
            'branch' => $inventory->branch,
            'starting_commit' => $instance->starting_commit,
            'source_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath,
            'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => $inventory->linkedWorktreePaths,
            'source_digest' => $inventory->digest,
        ]);
    $instance->update(['status' => InstanceState::Removing]);
    $removal->prepare($member);
    $member->update(['source_prepared_at' => now()]);

    return $member->refresh();
}

/**
 * @return array{Instance, Instance, Instance}
 */
function orb182_real_source_graph(
    RemoteDevelopmentInstanceSourceLifecycle $source,
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
): array {
    $checkout = orb180_resolved_source($source, $project, $node, $appsRoot, "{$name}-main");
    $worktrees = [];

    foreach (["{$name}-a", "{$name}-b"] as $worktreeName) {
        $worktreePath = "{$appsRoot}/acme/{$worktreeName}";
        orb76_run([
            'git',
            '-C',
            $checkout->checkout_path,
            'worktree',
            'add',
            '-b',
            $worktreeName,
            $worktreePath,
            'HEAD',
        ]);
        $worktrees[] = Instance::query()
            ->create([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'name' => $worktreeName,
                'source_layout' => 'worktree',
                'checkout_path' => $worktreePath,
                'branch' => $worktreeName,
                'starting_commit' => $checkout->starting_commit,
                'status' => InstanceState::SourceResolved,
            ])
            ->load(['project', 'node']);
    }

    return [$checkout, $worktrees[0], $worktrees[1]];
}

/**
 * @param  list<Instance>  $instances
 * @return list<InstanceRemovalMember>
 */
function orb182_record_sources(
    RemoteDevelopmentInstanceSourceRemoval $removal,
    array $instances,
    bool $force,
): array {
    $inventories = [];

    foreach ($instances as $instance) {
        $inventories[$instance->id] = $removal->inspect($instance, $force);
    }

    $routes = [];

    foreach ($instances as $instance) {
        $route = Route::query()->create([
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'generation_basis_node_id' => $instance->node_id,
            'domain' => "cascade-source-{$instance->id}.test",
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $instance->update(['status' => InstanceState::Active]);
        $routes[$instance->id] = $route;
    }

    $operation = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instances[array_key_last($instances)]->id,
        'requested_name' => $instances[array_key_last($instances)]->name,
        'force' => $force,
        'inventory_digest' => hash('sha256', implode('', array_map(
            static fn (InstanceSourceInventory $inventory): string => $inventory->digest,
            $inventories,
        ))),
        'total' => count($instances),
        'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
    $members = [];

    foreach ($instances as $position => $instance) {
        $inventory = $inventories[$instance->id];
        $route = $routes[$instance->id];
        $members[] = $operation
            ->members()
            ->create([
                'position' => $position,
                'instance_id' => $instance->id,
                'project_id' => $instance->project_id,
                'node_id' => $instance->node_id,
                'route_id' => $route->id,
                'name' => $instance->name,
                'environment' => $instance->defaultAppEnv(),
                'source_layout' => $inventory->layout,
                'repository_identity' => $inventory->repositoryIdentity,
                'checkout_path' => $inventory->checkoutPath,
                'root' => $instance->effectiveRoot(),
                'branch' => $inventory->branch,
                'starting_commit' => $instance->starting_commit,
                'source_commit' => $inventory->startingCommit,
                'common_repository_path' => $inventory->commonRepositoryPath,
                'source_identity' => $inventory->sourceIdentity,
                'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                'source_digest' => $inventory->digest,
            ]);
    }

    Instance::query()
        ->whereKey(array_map(static fn (Instance $instance): int => $instance->id, $instances))
        ->update(['status' => InstanceState::Removing->value]);

    return array_map(
        static fn (InstanceRemovalMember $member): InstanceRemovalMember => $member->refresh(),
        $members,
    );
}

function orb182_clear_test_route(InstanceRemovalMember $member): void
{
    $route = Route::query()->findOrFail($member->route_id);
    $route->targets()->delete();
    $route->delete();
}

/**
 * @return array{0: Instance, 1: Instance, 2: string}
 */
function orb180_worktree_source(
    RemoteDevelopmentInstanceSourceLifecycle $source,
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
): array {
    $checkout = orb180_resolved_source($source, $project, $node, $appsRoot, "{$name}-main");
    $worktreePath = "{$appsRoot}/acme/{$name}";
    $siblingPath = "{$appsRoot}/acme/{$name}-sibling";
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', $name, $worktreePath, 'HEAD']);
    orb76_run([
        'git',
        '-C',
        $checkout->checkout_path,
        'worktree',
        'add',
        '-b',
        "{$name}-sibling",
        $siblingPath,
        'HEAD',
    ]);
    $worktree = Instance::query()
        ->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'worktree',
            'checkout_path' => $worktreePath,
            'branch' => $name,
            'starting_commit' => $checkout->starting_commit,
            'status' => InstanceState::SourceResolved,
        ])
        ->load(['project', 'node']);

    return [$checkout, $worktree, $siblingPath];
}

/** @return array{0: string, 1: string, 2: string} */
function orb180_stage_worktree_receipt(InstanceRemovalMember $member): array
{
    $quarantine = orb180_quarantine_path($member);
    $commonRepository = (string) $member->common_repository_path;
    orb76_run([
        'git',
        "--git-dir={$commonRepository}/.git",
        'worktree',
        'move',
        (string) $member->checkout_path,
        $quarantine,
    ]);
    $admin = trim(orb76_run(['git', '-C', $quarantine, 'rev-parse', '--absolute-git-dir'])->stdout);
    $worktrees = dirname($admin);
    $recovery = dirname(orb180_receipt_path($member))."/{$member->instance_removal_id}.{$member->id}.recovery";
    file_put_contents(
        $recovery,
        base64_encode($admin)
        ."\n"
        .orb180_file_identity($admin)
        ."\n"
        .orb180_file_identity("{$commonRepository}/.git")
        ."\n"
        .orb180_file_identity($worktrees)
        ."\n",
    );
    chmod($recovery, 0o600);

    return [$quarantine, $admin, orb180_write_receipt($member)];
}

function orb180_write_receipt(InstanceRemovalMember $member): string
{
    $receipt = hash(
        'sha256',
        "{$member->instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );
    file_put_contents(orb180_receipt_path($member), "{$receipt}\n");
    chmod(orb180_receipt_path($member), 0o600);

    return $receipt;
}

function orb180_file_identity(string $path): string
{
    $identity = stat($path);
    expect($identity)->toBeArray();

    return "{$identity['dev']}:{$identity['ino']}";
}

function orb180_quarantine_path(InstanceRemovalMember $member): string
{
    return dirname(orb180_receipt_path($member))."/{$member->instance_removal_id}.{$member->id}.quarantine";
}

function orb180_receipt_path(InstanceRemovalMember $member): string
{
    $sourceRoot = dirname(dirname((string) $member->checkout_path));

    return "{$sourceRoot}/.orbit-removals/{$member->instance_removal_id}.{$member->id}.receipt";
}

function orb180_replace_ancestry(Instance $instance): void
{
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    $tree = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD^{tree}'])->stdout);
    $commit = trim(orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'commit-tree',
        $tree,
        '-m',
        'Unrelated recorded source',
    ])->stdout);
    orb76_run(['git', '-C', $instance->checkout_path, 'reset', '--hard', $commit]);
}

function orb285_rewind_head(Instance $instance, bool $published): void
{
    if ($published) {
        orb76_run(['git', '-C', $instance->checkout_path, 'reset', '--hard', 'HEAD~1']);
    } else {
        orb180_replace_ancestry($instance);
    }

    expect(orb178_run_allow_failure([
        'git',
        '-C',
        $instance->checkout_path,
        'merge-base',
        '--is-ancestor',
        (string) $instance->starting_commit,
        'HEAD',
    ])->succeeded())
        ->toBeFalse()
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'status', '--porcelain', '--untracked-files=all'])->stdout)
        ->toBe('');
}

function orb180_share_git_directory(Instance $instance, string $sandbox): void
{
    $shared = $sandbox.'/shared.git';
    expect(new Filesystem()->copyDirectory($instance->checkout_path.'/.git', $shared))->toBeTrue();
    file_put_contents($instance->checkout_path.'/.git/commondir', "{$shared}\n");
}

/**
 * Adds checkout-local insteadOf rules that change the URL `git remote get-url` reports while the
 * configured origin and the repository it reaches stay the same.
 */
function orb76_insteadof_rule(string $checkout, string $sandbox): void
{
    orb76_run(['git', '-C', $checkout, 'config', "url.{$sandbox}/./.insteadOf", "{$sandbox}/"]);
    orb76_run(['git', '-C', $checkout, 'config', 'url.https://rewritten.example.test/.insteadOf', 'ssh://git@example.test/']);
}

function orb178_remove_source(
    RemoteDevelopmentInstanceSourceRemoval $removal,
    Instance $instance,
    bool $force,
): InstanceSourceInventory {
    $inventory = $removal->inspect($instance, $force);
    $removal->remove($instance, $inventory, $force);

    return $inventory;
}

function orb895_native_removal_action(
    RemoteDevelopmentInstanceSourceRemoval $source,
    AppDevSourceOperationLock $sourceLock,
    string $lockDirectory,
    ?Closure $cleanupRuntime = null,
): RemoveInstanceAction {
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $source);
    app()->instance(AppDevSourceOperationLock::class, $sourceLock);
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock($lockDirectory, new CommandDeadline));
    app()->instance(InstanceRemovalProjector::class, new class($cleanupRuntime) implements InstanceRemovalProjector
    {
        public function __construct(private readonly ?Closure $cleanup) {}

        public function clearRouteTarget(InstanceRemovalMember $member): string
        {
            Route::query()->findOrFail($member->route_id)->delete();

            return 'deleted';
        }

        public function cleanupRuntime(InstanceRemovalMember $member): void
        {
            if ($this->cleanup === null) {
                throw new LogicException('A failed create has no published runtime.');
            }

            ($this->cleanup)($member);
        }
    });

    return app(RemoveInstanceAction::class);
}

function orb76_source_instance(
    Project $project,
    Node $node,
    string $appsRoot,
    string $name,
    ?string $branchOverride = null,
): Instance {
    if (! $node->roles()->where('role', 'app-dev')->exists()) {
        $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    }

    return Instance::query()
        ->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'checkout',
            'checkout_path' => "{$appsRoot}/{$project->slug}/{$name}",
            'branch_override' => $branchOverride,
            'status' => InstanceState::Reserved,
        ])
        ->load(['project', 'node']);
}

function orb76_create_remote_repository(string $sandbox, string $repository): void
{
    $work = $sandbox.'/work';
    orb76_run(['git', 'init', '--initial-branch=main', $work]);
    orb76_run(['git', '-C', $work, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $work, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($work.'/README.md', "main\n");
    orb76_run(['git', '-C', $work, 'add', 'README.md']);
    orb76_run(['git', '-C', $work, 'commit', '-m', 'Main']);
    orb76_run(['git', '-C', $work, 'branch', 'dev']);
    orb76_run(['git', 'init', '--bare', $repository]);
    orb76_run(['git', '-C', $work, 'remote', 'add', 'origin', $repository]);
    orb76_run(['git', '-C', $work, 'push', 'origin', 'main', 'dev']);
}

/** @param non-empty-list<string> $arguments */
function orb76_run(array $arguments, ?string $input = null): CommandResult
{
    $result = new NativeProcessRunner()->run(new ProcessInvocation($arguments, input: $input));
    expect($result->succeeded())->toBeTrue($result->stderr);

    return $result;
}

/** @param non-empty-list<string> $arguments */
function orb178_run_allow_failure(array $arguments): CommandResult
{
    return new NativeProcessRunner()->run(new ProcessInvocation($arguments));
}

function orb178_advance_remote(string $sandbox, string $ref): string
{
    $work = $sandbox.'/work';
    file_put_contents($work.'/README.md', "{$ref}\n", FILE_APPEND);
    orb76_run(['git', '-C', $work, 'add', 'README.md']);
    orb76_run(['git', '-C', $work, 'commit', '-m', "Advance {$ref}"]);
    orb76_run(['git', '-C', $work, 'push', 'origin', "HEAD:refs/heads/{$ref}"]);

    return trim(orb76_run(['git', '-C', $work, 'rev-parse', 'HEAD'])->stdout);
}

final class Orb180RecordingSourceLock implements AppDevSourceOperationLock
{
    /** @var list<int> */
    public array $nodes = [];

    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        $this->nodes[] = $nodeId;

        return $operation();
    }
}

final class Orb76LocalSourceSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public ?Closure $beforeFinalization = null;

    public ?string $workerGlobalConfig = null;

    public bool $interruptBeforeTrustCleanup = false;

    public bool $interruptDuringSourceDeletion = false;

    public bool $interruptAfterSourceMove = false;

    /** @var array<string, string> */
    public array $environment = [];

    public bool $failGroupingDirectoryCleanupOnce = false;

    public function __construct(
        public string $remoteOrigin,
        private readonly string $localOrigin,
    ) {}

    public function execute(
        SshConnection $connection,
        RemoteCommand $command,
    ): CommandResult {
        $this->commands[] = $command;
        $input = $command->input;
        if (is_string($input) && $this->interruptDuringSourceDeletion && str_contains($input, 'expected_origin=$6')) {
            $this->interruptDuringSourceDeletion = false;
            $input = str_replace(
                ['checkout) rm -rf -- "$quarantine" ;;', 'checkout) remove_source_tree "$quarantine" ;;'],
                'checkout) find -P "$quarantine/.git" -mindepth 1 -delete; exit 74 ;;',
                $input,
            );
        }
        if (is_string($input) && $this->interruptBeforeTrustCleanup && (
            str_contains($input, 'rm -rf -- "$quarantine"') || str_contains($input, 'remove_source_tree "$quarantine"')
        )) {
            $this->interruptBeforeTrustCleanup = false;
            $input = str_replace('release_empty_grouping_directory "$grouping_directory"', 'exit 73', $input);
        }
        if (is_string($input) && $this->workerGlobalConfig !== null) {
            $input = str_replace('-- git config --global', '-- env '.escapeshellarg('GIT_CONFIG_GLOBAL='.$this->workerGlobalConfig).' git config --global', $input);
        }

        if (is_string($input) && $this->interruptAfterSourceMove && str_contains($input, 'expected_origin=$6')) {
            $this->interruptAfterSourceMove = false;
            $input = str_replace('physical=$quarantine', 'physical=$quarantine; exit 74', $input);
        }

        if (is_string($input) && str_contains($input, 'expected_origin=$6')) {
            $callback = $this->beforeFinalization;
            $this->beforeFinalization = null;
            $callback?->__invoke();

            if ($this->failGroupingDirectoryCleanupOnce) {
                $this->failGroupingDirectoryCleanupOnce = false;
                $input = str_replace('rmdir -- "$grouping_directory"', 'exit 97', $input);
            }
        }

        if (is_string($input) && str_contains($input, 'expected_repository_identity=$1')) {
            $checkout = $command->arguments[3] ?? null;

            if (is_string($checkout) && is_dir($checkout)) {
                $configuredOrigin = trim(orb76_run([
                    'git',
                    '-C',
                    $checkout,
                    'config',
                    '--get',
                    'remote.origin.url',
                ])->stdout);

                if ($configuredOrigin === $this->localOrigin) {
                    orb76_run(['git', '-C', $checkout, 'remote', 'set-url', 'origin', $this->remoteOrigin]);
                }
            }

            $input = str_replace(
                'git --git-dir="$scratch/repository.git" remote add origin "$origin"',
                "git --git-dir=\"\$scratch/repository.git\" remote add origin '{$this->localOrigin}'",
                $input,
            );
        }
        $arguments = array_map(
            fn (string $argument): string => $argument === $this->remoteOrigin ? $this->localOrigin : $argument,
            $command->arguments,
        );

        $result = new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: $arguments,
            input: $input,
            environment: $this->environment,
        ));

        return new CommandResult(
            exitCode: $result->exitCode,
            stdout: str_replace(
                [$this->localOrigin, base64_encode($this->localOrigin)],
                [$this->remoteOrigin, base64_encode($this->remoteOrigin)],
                $result->stdout,
            ),
            stderr: $result->stderr,
            durationMs: $result->durationMs,
            truncated: $result->truncated,
        );
    }
}
