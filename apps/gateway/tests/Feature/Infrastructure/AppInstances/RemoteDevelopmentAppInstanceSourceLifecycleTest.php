<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationExpectation;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceSourceLifecycle;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceSourceRemoval;
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
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\Node;
use App\Models\Route;
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
    $this->sourceLock = new NativeAppDevSourceOperationLock($this->sandbox.'/locks');
    $ssh = new AppDevSshExecutor(
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
    $this->source = new RemoteDevelopmentAppInstanceSourceLifecycle(
        $ssh,
        $accounts,
        $this->boundary,
    );
    $this->removal = new RemoteDevelopmentAppInstanceSourceRemoval(
        $ssh,
        $accounts,
        $this->boundary,
        $this->sourceLock,
    );

    $this->node = Node::query()->create([
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
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->sandbox);
});

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

it('uses the App default branch for the reserved default identity', function (): void {
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
        ->and(substr_count($inputs, 'git clone --no-checkout --origin origin --'))
        ->toBe(2)
        ->and($inputs)
        ->not->toContain('caddy', 'certificate', 'dns', 'php-fpm', 'systemctl', 'hostname');

    foreach ($this->transport->commands as $command) {
        expect(array_slice($command->arguments, 0, 3))->toBe(['bash', '-seu', '--']);
    }
});

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
        'status' => AppInstanceState::SourceResolved,
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

it('does not let force waive origin or symlink identity checks', function (string $mutation): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
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

    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(
            function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('instance.force_failed');
            },
        );
    expect(file_exists($decoy.'/sentinel'))
        ->toBeTrue()
        ->and(file_exists($instance->checkout_path) || is_link($instance->checkout_path))
        ->toBeTrue();
})->with(['origin', 'symlink']);

it('does not let force remove a checkout with shared Git administration', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
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
    expect(fn () => orb178_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($sharedGitDirectory))
        ->toBeTrue();
});

it('uses current remote publication evidence without changing the checkout index refs or object store', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
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
        'status' => AppInstanceState::SourceResolved,
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
        'status' => AppInstanceState::SourceResolved,
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
        'status' => AppInstanceState::SourceResolved,
    ]);
    $worktree = $this->appsRoot.'/acme/linked';
    orb76_run(['git', '-C', $instance->checkout_path, 'worktree', 'add', '-b', 'linked', $worktree, 'HEAD']);
    $refs = orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout;

    $inventory = $this->removal->inspect($instance, true);

    expect($inventory->linkedWorktreePaths)
        ->toBe([$instance->checkout_path, $worktree])
        ->and(fn () => $this->removal->remove($instance, $inventory, true))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($worktree))
        ->toBeTrue()
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout)
        ->toBe($refs)
        ->and(orb76_run(['git', '--git-dir='.$this->repository, 'show-ref'])->stdout)
        ->toContain('refs/heads/dev');
});

it('refuses a replacement or changed canonical origin between inspection and deletion', function (
    string $mutation,
    bool $force,
): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
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
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue();
})->with([
    'normal replacement' => ['replacement', false],
    'forced replacement' => ['replacement', true],
    'normal origin change' => ['origin', false],
    'forced origin change' => ['origin', true],
]);

it('accepts an equivalent supported origin at the destructive boundary', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
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
        'status' => AppInstanceState::SourceResolved,
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
        'status' => AppInstanceState::SourceResolved,
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
        ->toThrow(RuntimeConvergenceException::class);
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
        'status' => AppInstanceState::SourceResolved,
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
        ->toThrow(RuntimeConvergenceException::class);
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
        'status' => AppInstanceState::SourceResolved,
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
        ->toThrow(RuntimeConvergenceException::class);
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
        "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    expect($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and($this->removal->revalidate($member))
        ->toBe(AppInstanceSourceRevalidationState::Completed)
        ->and($this->removal->finalize($member))
        ->toBe($receipt);
    expect(file_get_contents(orb180_receipt_path($member)))
        ->toBe("{$receipt}\n");
});

it('removes a detached checkout with forced nullable branch evidence', function (): void {
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'detached');
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '--detach']);
    $instance->update(['branch' => null]);

    $inventory = orb178_remove_source($this->removal, $instance->refresh()->load(['app', 'node']), true);

    expect($inventory->branch)
        ->toBeNull()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
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
    $member = orb180_record_source($this->removal, $instance->refresh()->load(['app', 'node']), false);

    $receipt = $this->removal->finalize($member);

    expect($member->branch)
        ->toBeNull()
        ->and($receipt)
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse()
        ->and($this->removal->revalidate($member))
        ->toBe(AppInstanceSourceRevalidationState::Completed);
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
    $worktree = AppInstance::query()
        ->create([
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'feature',
            'source_layout' => 'worktree',
            'checkout_path' => $worktreePath,
            'branch' => 'feature',
            'starting_commit' => $checkout->starting_commit,
            'status' => AppInstanceState::SourceResolved,
        ])
        ->load(['app', 'node']);
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

    $expectation = new AppInstanceSourceRevalidationExpectation($paths, $paths);
    expect($this->removal->revalidate($members[2], $expectation))
        ->toBe(AppInstanceSourceRevalidationState::Present);
    $this->removal->prepare($members[0], $expectation);
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $firstReceipt = $this->removal->finalize($members[0], $expectation);
    $members[0]->update(['source_finalized_at' => now(), 'finalization_receipt' => $firstReceipt]);
    $afterFirst = [$checkout->checkout_path, $second->checkout_path];
    sort($afterFirst, SORT_STRING);
    $expectation = new AppInstanceSourceRevalidationExpectation($afterFirst, $afterFirst);
    $this->removal->prepare($members[1], $expectation);
    $members[1]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[1]);
    $members[1]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);

    expect($this->removal->revalidate($members[1], $expectation))
        ->toBe(AppInstanceSourceRevalidationState::Present);
    $secondReceipt = $this->removal->finalize($members[1], $expectation);
    $members[1]->update(['source_finalized_at' => now(), 'finalization_receipt' => $secondReceipt]);
    $expectation = new AppInstanceSourceRevalidationExpectation(
        [$checkout->checkout_path],
        [$checkout->checkout_path],
    );
    $this->removal->prepare($members[2], $expectation);
    $members[2]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[2]);
    $members[2]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);

    expect($this->removal->revalidate($members[2], $expectation))
        ->toBe(AppInstanceSourceRevalidationState::Present)
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and(file_exists($first->checkout_path))
        ->toBeFalse()
        ->and(file_exists($second->checkout_path))
        ->toBeFalse();

    $this->removal->finalize($members[2], $expectation);

    expect(file_exists($checkout->checkout_path))
        ->toBeFalse()
        ->and($this->removal->revalidate($members[0], $expectation))
        ->toBe(AppInstanceSourceRevalidationState::Completed)
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
        new AppInstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $receipt = $this->removal->finalize(
        $members[0],
        new AppInstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    $unknown = $this->appsRoot.'/acme/unknown-late';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'unknown-late', $unknown, 'HEAD']);
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);

    expect(fn () => $this->removal->revalidate(
        $members[1],
        new AppInstanceSourceRevalidationExpectation($expected, $expected),
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
    $expectation = new AppInstanceSourceRevalidationExpectation($paths, $paths);
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
        new AppInstanceSourceRevalidationExpectation($expected, $expected),
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
        new AppInstanceSourceRevalidationExpectation($paths, $paths),
    );
    $members[0]->update(['source_prepared_at' => now()]);
    orb182_clear_test_route($members[0]);
    $members[0]->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $this->removal->finalize(
        $members[0],
        new AppInstanceSourceRevalidationExpectation($paths, $paths),
    );
    $expected = [$checkout->checkout_path, $second->checkout_path];
    sort($expected, SORT_STRING);
    $states = [$members[0]->id => AppInstanceSourceRevalidationState::Completed];
    $expectation = new AppInstanceSourceRevalidationExpectation($expected, $expected, $states);

    expect($members[0]->refresh()->source_finalized_at)
        ->toBeNull()
        ->and($this->removal->revalidate($members[0], $expectation))
        ->toBe(AppInstanceSourceRevalidationState::Completed)
        ->and($this->removal->revalidate($members[1], $expectation))
        ->toBe(AppInstanceSourceRevalidationState::Present)
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
    $states[$members[1]->id] = AppInstanceSourceRevalidationState::Completed;

    expect($this->removal->revalidate(
        $members[0]->refresh(),
        new AppInstanceSourceRevalidationExpectation($rootOnly, $rootOnly, $states),
    ))
        ->toBe(AppInstanceSourceRevalidationState::Completed)
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
        "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    if ($withReceipt) {
        file_put_contents(orb180_receipt_path($member), "{$receipt}\n");
    }

    expect($this->removal->revalidate($member))
        ->toBe(
            $withReceipt
                ? AppInstanceSourceRevalidationState::ReceiptPendingCleanup
                : AppInstanceSourceRevalidationState::Quarantined,
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
        ->toBe(AppInstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->removal->finalize($member))
        ->toBe($receipt)
        ->and(file_exists($quarantine))
        ->toBeFalse();
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
        ->toBe(AppInstanceSourceRevalidationState::ReceiptPendingCleanup)
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
            dirname(orb180_receipt_path($member))."/{$member->app_instance_removal_id}.{$member->id}.journal",
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
            ->app
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
        ->toBe(AppInstanceSourceRevalidationState::Present)
        ->and($this->removal->finalize($member))
        ->toBeString()
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
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
    $foreign = OrbitApp::query()->create([
        'name' => 'Foreign',
        'slug' => 'foreign',
        'repository_url' => 'https://example.test/foreign/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance->update(['app_id' => $foreign->id]);
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
    $removal = new RemoteDevelopmentAppInstanceSourceRemoval(
        $this->ssh,
        $this->accounts,
        $this->boundary,
        $lock,
    );
    $instance = orb180_resolved_source($this->source, $this->orbitApp, $this->node, $this->appsRoot, 'locked');
    $member = orb180_record_source($removal, $instance, true);

    expect($removal->revalidate($member))->toBe(AppInstanceSourceRevalidationState::Present);
    $removal->inspectRecorded($member, AppInstanceSourceRevalidationState::Present);
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

it('does not let removal waive the recorded starting commit ancestry', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $instance->checkout_path, 'config', 'user.email', 'orbit@example.test']);
    $tree = trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD^{tree}'])->stdout);
    $unrelatedCommit = trim(orb76_run([
        'git',
        '-C',
        $instance->checkout_path,
        'commit-tree',
        $tree,
        '-m',
        'Unrelated source identity',
    ])->stdout);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $unrelatedCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);

    expect(fn () => orb178_remove_source($this->removal, $instance, $force))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))->toBeTrue();
})->with([
    'normal removal' => false,
    'force' => true,
]);

it('refuses grouping-directory ownership drift before deleting the checkout', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
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
        ->toThrow(RuntimeConvergenceException::class);
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
    $instance->app->default_branch = null;

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
    RemoteDevelopmentAppInstanceSourceLifecycle $source,
    OrbitApp $app,
    Node $node,
    string $appsRoot,
    string $name,
): AppInstance {
    $instance = orb76_source_instance($app, $node, $appsRoot, $name);
    $source->prepare($instance, false);
    $resolution = $source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);

    return $instance->refresh()->load(['app', 'node']);
}

function orb180_record_source(
    RemoteDevelopmentAppInstanceSourceRemoval $removal,
    AppInstance $instance,
    bool $force,
): AppInstanceRemovalMember {
    $inventory = $removal->inspect($instance, $force);
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id,
        'hostname' => "source-finalization-{$instance->id}.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);
    $operation = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => $inventory->digest,
        'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $member = $operation
        ->members()
        ->create([
            'position' => 0,
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->environment,
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
    $instance->update(['status' => AppInstanceState::Removing]);
    $removal->prepare($member);
    $member->update(['source_prepared_at' => now()]);

    return $member->refresh();
}

/**
 * @return array{AppInstance, AppInstance, AppInstance}
 */
function orb182_real_source_graph(
    RemoteDevelopmentAppInstanceSourceLifecycle $source,
    OrbitApp $app,
    Node $node,
    string $appsRoot,
    string $name,
): array {
    $checkout = orb180_resolved_source($source, $app, $node, $appsRoot, "{$name}-main");
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
        $worktrees[] = AppInstance::query()
            ->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'name' => $worktreeName,
                'source_layout' => 'worktree',
                'checkout_path' => $worktreePath,
                'branch' => $worktreeName,
                'starting_commit' => $checkout->starting_commit,
                'status' => AppInstanceState::SourceResolved,
            ])
            ->load(['app', 'node']);
    }

    return [$checkout, $worktrees[0], $worktrees[1]];
}

/**
 * @param  list<AppInstance>  $instances
 * @return list<AppInstanceRemovalMember>
 */
function orb182_record_sources(
    RemoteDevelopmentAppInstanceSourceRemoval $removal,
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
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'generation_basis_node_id' => $instance->node_id,
            'hostname' => "cascade-source-{$instance->id}.test",
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $instance->update(['status' => AppInstanceState::Active]);
        $routes[$instance->id] = $route;
    }

    $operation = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instances[array_key_last($instances)]->id,
        'requested_name' => $instances[array_key_last($instances)]->name,
        'force' => $force,
        'inventory_digest' => hash('sha256', implode('', array_map(
            static fn (AppInstanceSourceInventory $inventory): string => $inventory->digest,
            $inventories,
        ))),
        'total' => count($instances),
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $members = [];

    foreach ($instances as $position => $instance) {
        $inventory = $inventories[$instance->id];
        $route = $routes[$instance->id];
        $members[] = $operation
            ->members()
            ->create([
                'position' => $position,
                'app_instance_id' => $instance->id,
                'app_id' => $instance->app_id,
                'node_id' => $instance->node_id,
                'route_id' => $route->id,
                'name' => $instance->name,
                'environment' => $instance->environment,
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

    AppInstance::query()
        ->whereKey(array_map(static fn (AppInstance $instance): int => $instance->id, $instances))
        ->update(['status' => AppInstanceState::Removing->value]);

    return array_map(
        static fn (AppInstanceRemovalMember $member): AppInstanceRemovalMember => $member->refresh(),
        $members,
    );
}

function orb182_clear_test_route(AppInstanceRemovalMember $member): void
{
    $route = Route::query()->findOrFail($member->route_id);
    $route->targets()->delete();
    $route->delete();
}

/**
 * @return array{0: AppInstance, 1: AppInstance, 2: string}
 */
function orb180_worktree_source(
    RemoteDevelopmentAppInstanceSourceLifecycle $source,
    OrbitApp $app,
    Node $node,
    string $appsRoot,
    string $name,
): array {
    $checkout = orb180_resolved_source($source, $app, $node, $appsRoot, "{$name}-main");
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
    $worktree = AppInstance::query()
        ->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'worktree',
            'checkout_path' => $worktreePath,
            'branch' => $name,
            'starting_commit' => $checkout->starting_commit,
            'status' => AppInstanceState::SourceResolved,
        ])
        ->load(['app', 'node']);

    return [$checkout, $worktree, $siblingPath];
}

/** @return array{0: string, 1: string, 2: string} */
function orb180_stage_worktree_receipt(AppInstanceRemovalMember $member): array
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
    $recovery = dirname(orb180_receipt_path($member))."/{$member->app_instance_removal_id}.{$member->id}.recovery";
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

function orb180_write_receipt(AppInstanceRemovalMember $member): string
{
    $receipt = hash(
        'sha256',
        "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
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

function orb180_quarantine_path(AppInstanceRemovalMember $member): string
{
    return dirname(orb180_receipt_path($member))."/{$member->app_instance_removal_id}.{$member->id}.quarantine";
}

function orb180_receipt_path(AppInstanceRemovalMember $member): string
{
    $sourceRoot = dirname(dirname((string) $member->checkout_path));

    return "{$sourceRoot}/.orbit-removals/{$member->app_instance_removal_id}.{$member->id}.receipt";
}

function orb180_replace_ancestry(AppInstance $instance): void
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

function orb180_share_git_directory(AppInstance $instance, string $sandbox): void
{
    $shared = $sandbox.'/shared.git';
    expect(new Filesystem()->copyDirectory($instance->checkout_path.'/.git', $shared))->toBeTrue();
    file_put_contents($instance->checkout_path.'/.git/commondir', "{$shared}\n");
}

function orb178_remove_source(
    RemoteDevelopmentAppInstanceSourceRemoval $removal,
    AppInstance $instance,
    bool $force,
): AppInstanceSourceInventory {
    $inventory = $removal->inspect($instance, $force);
    $removal->remove($instance, $inventory, $force);

    return $inventory;
}

function orb76_source_instance(
    OrbitApp $app,
    Node $node,
    string $appsRoot,
    string $name,
    ?string $branchOverride = null,
): AppInstance {
    return AppInstance::query()
        ->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'checkout',
            'checkout_path' => "{$appsRoot}/{$app->slug}/{$name}",
            'branch_override' => $branchOverride,
            'status' => AppInstanceState::Reserved,
        ])
        ->load(['app', 'node']);
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

        if (is_string($input) && str_contains($input, 'expected_origin=$6')) {
            $callback = $this->beforeFinalization;
            $this->beforeFinalization = null;
            $callback?->__invoke();
        }

        if (is_string($input) && str_contains($input, 'expected_repository_identity=$1')) {
            $checkout = $command->arguments[3] ?? null;

            if (is_string($checkout) && is_dir($checkout)) {
                $configuredOrigin = trim(orb76_run([
                    'git',
                    '-C',
                    $checkout,
                    'remote',
                    'get-url',
                    'origin',
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
