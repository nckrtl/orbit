<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceSourceLifecycle;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
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
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

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
    $accounts = new class($account) implements ManagedUserAccountResolver {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $this->transport = new Orb76LocalSourceSshExecutor($this->remoteOrigin, $this->repository);
    $ssh = new AppDevSshExecutor(
        $this->transport,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
    $this->source = new RemoteDevelopmentAppInstanceSourceLifecycle(
        $ssh,
        $accounts,
        new CheckoutRemovalBoundary(new ProtectedPathCatalog),
    );

    $cluster = Cluster::query()->create(['name' => 'development', 'state' => ClusterState::Active]);
    $this->node = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => $user,
    ]);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => $this->remoteOrigin,
        'main_branch' => 'main',
        'root' => 'public',
    ]);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->sandbox);
});

it('creates independent clones from an existing remote branch and the exact fetched main branch', function (): void {
    $existing = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $fallback = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'feature');

    $this->source->prepare($existing);
    $existingResolution = $this->source->resolve($existing);
    $this->source->prepare($fallback);
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

it('makes preparation idempotent and uses only fixed source-control commands', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');

    $this->source->prepare($instance);
    $this->source->prepare($instance);
    $this->source->inspectPrepared($instance);
    $resolution = $this->source->resolve($instance);
    $this->source->inspectResolved($instance);

    $inputs = implode("\n", array_map(
        static fn (RemoteCommand $command): string => $command->input ?? '',
        $this->transport->commands,
    ));

    expect($resolution->branch)
        ->toBe('dev')
        ->and(substr_count($inputs, 'git clone --no-checkout --origin origin --'))
        ->toBe(2)
        ->and($inputs)
        ->not->toContain('caddy', 'certificate', 'dns', 'php-fpm', 'systemctl', 'hostname');

    foreach ($this->transport->commands as $command) {
        expect(array_slice($command->arguments, 0, 3))->toBe(['bash', '-seu', '--']);
    }
});

it('refuses dirty and unpublished source unless discard is explicit', function (string $mutation): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::Active,
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

    expect(fn () => $this->source->remove($instance, false))->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))->toBeTrue();

    $this->source->remove($instance, true);
    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with(['dirty', 'unpublished']);

it('does not let discard waive origin or symlink identity checks', function (string $mutation): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::Active,
    ]);
    $decoy = $this->sandbox.'/decoy';
    $this->files->makeDirectory($decoy, 0o755, true);
    file_put_contents($decoy.'/sentinel', 'keep');

    if ($mutation === 'origin') {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'ssh://git@example.test/wrong.git',
        ]);
    } else {
        $this->files->deleteDirectory($instance->checkout_path);
        symlink($decoy, $instance->checkout_path);
    }

    expect(fn () => $this->source->remove($instance, true))->toThrow(RuntimeConvergenceException::class);
    expect(file_exists($decoy.'/sentinel'))
        ->toBeTrue()
        ->and(file_exists($instance->checkout_path) || is_link($instance->checkout_path))
        ->toBeTrue();
})->with(['origin', 'symlink']);

it('refuses a recorded path that is outside the exact App and instance identity', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->update(['checkout_path' => $this->sandbox.'/unrelated']);

    expect(fn () => $this->source->remove($instance, true))->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

it('fails closed before source resolution when the stored App main branch is incomplete', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->app->main_branch = null;

    expect(fn () => $this->source->resolve($instance))->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

it('fails closed before removal when stored source identity is incomplete', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');

    expect(fn () => $this->source->remove($instance, true))->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

function orb76_source_instance(
    OrbitApp $app,
    Node $node,
    string $appsRoot,
    string $name,
): AppInstance {
    return AppInstance::query()
        ->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'cluster_id' => $node->cluster_id,
            'name' => $name,
            'checkout_path' => "{$appsRoot}/{$app->slug}/{$name}",
            'status' => AppInstanceState::Reserved,
        ])
        ->load(['app', 'node', 'cluster']);
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

final class Orb76LocalSourceSshExecutor implements \App\Infrastructure\Ssh\SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(
        private readonly string $remoteOrigin,
        private readonly string $localOrigin,
    ) {}

    public function execute(
        \App\Infrastructure\Ssh\SshConnection $connection,
        \App\Infrastructure\Ssh\RemoteCommand $command,
    ): \App\Infrastructure\Processes\CommandResult {
        $this->commands[] = $command;
        $arguments = array_map(
            fn (string $argument): string => $argument === $this->remoteOrigin ? $this->localOrigin : $argument,
            $command->arguments,
        );

        return new NativeProcessRunner()->run(new ProcessInvocation(
            arguments: $arguments,
            input: $command->input,
        ));
    }
}
