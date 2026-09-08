<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
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
    $this->sourceLock = new NativeAppDevSourceOperationLock($this->sandbox.'/locks');
    $this->removal = new RemoteDevelopmentAppInstanceSourceRemoval(
        $ssh,
        $accounts,
        new CheckoutRemovalBoundary(new ProtectedPathCatalog),
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
            \App\Domain\AppDev\RuntimeConvergenceException::class,
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

    expect(fn () => orb124_remove_source($this->removal, $instance, false))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))->toBeTrue();

    orb124_remove_source($this->removal, $instance, true);
    expect(file_exists($instance->checkout_path))->toBeFalse();
})->with(['dirty', 'unpublished']);

it('refuses stale local publication evidence without mutating the requested repository', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $beforeRefs = orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout;
    $beforeStatus = orb76_run(['git', '-C', $instance->checkout_path, 'status', '--porcelain=v1'])->stdout;
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(fn () => $this->removal->inspect($instance, false))
        ->toThrow(RuntimeConvergenceException::class);
    expect(orb76_run(['git', '-C', $instance->checkout_path, 'show-ref'])->stdout)
        ->toBe($beforeRefs)
        ->and(orb76_run(['git', '-C', $instance->checkout_path, 'status', '--porcelain=v1'])->stdout)
        ->toBe($beforeStatus)
        ->and($this->removal->inspect($instance, true)->checkoutPath)
        ->toBe($instance->checkout_path);
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
    $tracked = $instance->checkout_path.'/README.md';
    expect(touch($tracked, time() + 10))->toBeTrue();
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

it('accepts a published HEAD behind an unfetched descendant on a differently named current ref', function (): void {
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
    $tip = orb124_advance_remote($this->sandbox, 'published-descendant');
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(
        orb76_run_allow_failure([
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
            orb76_run_allow_failure([
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

it('rechecks an unfetched advertised descendant before normal finalization', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, false);
    $member->update(['source_prepared_at' => now()]);
    $tip = orb124_advance_remote($this->sandbox, 'advanced-after-acceptance');
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/main']);
    orb76_run(['git', '--git-dir='.$this->repository, 'update-ref', '-d', 'refs/heads/dev']);

    expect(
        orb76_run_allow_failure([
            'git',
            '-C',
            $instance->checkout_path,
            'cat-file',
            '-e',
            "{$tip}^{commit}",
        ])->succeeded(),
    )
        ->toBeFalse()
        ->and($this->removal->finalize($member))
        ->toBe(hash(
            'sha256',
            "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
        ))
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
});

it('does not contact an unavailable valid origin during forced inspection or finalization', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    expect(rename($this->repository, $this->repository.'.unavailable'))->toBeTrue();

    orb124_remove_source($this->removal, $instance, true);

    expect(file_exists($instance->checkout_path))->toBeFalse();
});

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

    expect(fn () => orb124_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(file_exists($decoy.'/sentinel'))
        ->toBeTrue()
        ->and(file_exists($instance->checkout_path) || is_link($instance->checkout_path))
        ->toBeTrue();
})->with(['origin', 'symlink']);

it('refuses an origin identity change at the destructive boundary', function (bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, $force);
    $member->update(['source_prepared_at' => now()]);
    $sourceIdentity = orb76_run(['stat', '-c', '%d:%i', $instance->checkout_path])->stdout;
    $this->transport->beforeFinalization = static function () use ($instance): void {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'https://example.test/wrong-repository.git',
        ]);
    };

    expect(fn () => $this->removal->finalize($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(orb76_run(['stat', '-c', '%d:%i', $instance->checkout_path])->stdout)
        ->toBe($sourceIdentity);
})->with([
    'normal removal' => false,
    'forced removal' => true,
]);

it('accepts an equivalent origin spelling at the destructive boundary', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, true);
    $member->update(['source_prepared_at' => now()]);
    $this->transport->beforeFinalization = static function () use ($instance): void {
        orb76_run([
            'git',
            '-C',
            $instance->checkout_path,
            'remote',
            'set-url',
            'origin',
            'https://EXAMPLE.TEST/acme/site.git/',
        ]);
    };

    expect($this->removal->finalize($member))
        ->toBe(hash(
            'sha256',
            "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
        ))
        ->and(file_exists($instance->checkout_path))
        ->toBeFalse();
});

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
    expect(fn () => orb124_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(is_dir($sharedGitDirectory))
        ->toBeTrue();
});

it('removes one worktree published through another origin ref while retaining its local branch and siblings', function (): void {
    $checkout = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($checkout, false);
    $resolution = $this->source->resolve($checkout);
    $checkout->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $worktreePath = $this->appsRoot.'/acme/feature';
    orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'add', '-b', 'feature', $worktreePath, 'HEAD']);
    $worktree = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'feature',
        'source_layout' => 'worktree',
        'checkout_path' => $worktreePath,
        'branch' => 'feature',
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);

    orb124_remove_source($this->removal, $worktree, false);

    $inventory = orb76_run(['git', '-C', $checkout->checkout_path, 'worktree', 'list', '--porcelain'])->stdout;
    expect(file_exists($worktreePath))
        ->toBeFalse()
        ->and(is_dir($checkout->checkout_path.'/.git'))
        ->toBeTrue()
        ->and($inventory)
        ->toContain("worktree {$checkout->checkout_path}")
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
        ->and(orb76_run(['git', '--git-dir='.$this->repository, 'show-ref', '--verify', 'refs/heads/dev'])->succeeded())
        ->toBeTrue();
});

it('resumes authenticated quarantine before or after receipt creation', function (bool $withReceipt): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, false);
    $member->update(['source_prepared_at' => now()]);
    $state = "{$member->root}/.orbit-removals";
    $quarantine = "{$state}/{$member->app_instance_removal_id}.{$member->id}.quarantine";
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    $receipt = hash(
        'sha256',
        "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    if ($withReceipt) {
        file_put_contents("{$state}/{$member->app_instance_removal_id}.{$member->id}.receipt", "{$receipt}\n");
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

it('refuses changed quarantined source before deletion', function (string $mutation, bool $force): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, $force);
    $member->update(['source_prepared_at' => now()]);
    $quarantine = "{$member->root}/.orbit-removals/{$member->app_instance_removal_id}.{$member->id}.quarantine";
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();

    match ($mutation) {
        'dirty' => file_put_contents($quarantine.'/dirty.txt', 'changed after quarantine'),
        'unpublished' => orb124_commit_unpublished($quarantine),
        'origin' => orb76_run([
            'git',
            '-C',
            $quarantine,
            'remote',
            'set-url',
            'origin',
            $this->sandbox.'/distinct.git',
        ]),
        'branch' => orb76_run(['git', '-C', $quarantine, 'branch', '-m', 'changed-after-quarantine']),
    };

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class)
        ->and(is_dir($quarantine))
        ->toBeTrue();
})->with([
    'dirty normal source' => ['dirty', false],
    'unpublished normal source' => ['unpublished', false],
    'different origin under force' => ['origin', true],
    'different branch under force' => ['branch', true],
]);

it('refuses a new unregistered worktree added to an authenticated quarantined checkout', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, true);
    $member->update(['source_prepared_at' => now()]);
    $instance->update(['status' => AppInstanceState::Removing]);
    $quarantine = "{$member->root}/.orbit-removals/{$member->app_instance_removal_id}.{$member->id}.quarantine";
    $newWorktree = $this->appsRoot.'/acme/unregistered-after-quarantine';
    expect(rename($instance->checkout_path, $quarantine))->toBeTrue();
    orb76_run(['git', '-C', $quarantine, 'worktree', 'add', '-b', 'late-worktree', $newWorktree, 'HEAD']);
    $projector = new class implements AppInstanceRemovalProjector {
        public function clearRouteTarget(AppInstanceRemovalMember $member): string
        {
            throw new LogicException('Route mutation must not run after source revalidation refusal.');
        }

        public function cleanupRuntime(AppInstanceRemovalMember $member): void
        {
            throw new LogicException('Runtime cleanup must not run after source revalidation refusal.');
        }
    };
    $action = new RemoveAppInstanceAction(
        $this->removal,
        $projector,
        new ManagedCheckoutOverlap,
        $this->sourceLock,
    );

    expect(fn () => $action->execute($instance->refresh(), true))
        ->toThrow(AppInstanceRemovalException::class)
        ->and(is_dir($quarantine))
        ->toBeTrue()
        ->and(is_dir($newWorktree))
        ->toBeTrue()
        ->and($member->removal()->firstOrFail()->refresh()->error_code)
        ->toBe('instance.removal_conflict');
});

it('accepts an absent source only with its matching durable completion receipt', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, false);
    $member->update(['source_prepared_at' => now()]);
    $receipt = hash(
        'sha256',
        "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
    );

    expect($this->removal->finalize($member))
        ->toBe($receipt)
        ->and($this->removal->revalidate($member))
        ->toBe(AppInstanceSourceRevalidationState::Completed)
        ->and($this->removal->finalize($member))
        ->toBe($receipt);

    $receiptPath = "{$member->root}/.orbit-removals/{$member->app_instance_removal_id}.{$member->id}.receipt";
    file_put_contents($receiptPath, 'mismatched');

    expect(fn () => $this->removal->finalize($member))
        ->toThrow(RuntimeConvergenceException::class);
});

it('refuses an equivalent replacement clone at the recorded path', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $this->source->prepare($instance, false);
    $resolution = $this->source->resolve($instance);
    $instance->update([
        'branch' => $resolution->branch,
        'starting_commit' => $resolution->startingCommit,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $member = orb124_prepare_remove_source($this->removal, $instance, false);
    $member->update(['source_prepared_at' => now()]);
    $original = $this->sandbox.'/original-source';
    expect(rename($instance->checkout_path, $original))->toBeTrue();
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
    orb76_run(['git', '-C', $instance->checkout_path, 'checkout', '-b', 'dev', $resolution->startingCommit]);

    expect(fn () => $this->removal->revalidate($member))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))
        ->toBeTrue()
        ->and(trim(orb76_run(['git', '-C', $instance->checkout_path, 'rev-parse', 'HEAD'])->stdout))
        ->toBe($resolution->startingCommit);
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

    expect(fn () => orb124_remove_source($this->removal, $instance, $force))
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

    expect(fn () => orb124_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(is_dir($instance->checkout_path))->toBeTrue();
});

it('refuses a recorded path that is outside the exact App and instance identity', function (): void {
    $instance = orb76_source_instance($this->orbitApp, $this->node, $this->appsRoot, 'dev');
    $instance->update(['checkout_path' => $this->sandbox.'/unrelated']);

    expect(fn () => orb124_remove_source($this->removal, $instance, true))
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

    expect(fn () => orb124_remove_source($this->removal, $instance, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->transport->commands)->toBeEmpty();
});

function orb124_remove_source(
    RemoteDevelopmentAppInstanceSourceRemoval $removal,
    AppInstance $instance,
    bool $force,
): AppInstanceRemovalMember {
    $member = orb124_prepare_remove_source($removal, $instance, $force);
    $removal->finalize($member);

    return $member;
}

function orb124_prepare_remove_source(
    RemoteDevelopmentAppInstanceSourceRemoval $removal,
    AppInstance $instance,
    bool $force,
): AppInstanceRemovalMember {
    $inventory = $removal->inspect($instance, $force);
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'generation_basis_node_id' => $instance->node_id,
        'hostname' => "source-removal-{$instance->id}.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);
    $operation = \App\Models\AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => $inventory->digest,
        'total' => 1,
        'status' => \App\Domain\AppInstances\AppInstanceRemovalStatus::Removing,
        'current_step' => \App\Domain\AppInstances\AppInstanceRemovalStep::SourcePreparation,
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
            'root' => $inventory->root,
            'branch' => $inventory->branch,
            'starting_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath,
            'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => $inventory->linkedWorktreePaths,
            'source_digest' => $inventory->digest,
        ]);

    $removal->prepare($member);

    return $member;
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
function orb76_run_allow_failure(array $arguments): CommandResult
{
    return new NativeProcessRunner()->run(new ProcessInvocation($arguments));
}

function orb124_advance_remote(string $sandbox, string $ref): string
{
    $work = $sandbox.'/work';
    file_put_contents($work.'/README.md', "{$ref}\n", FILE_APPEND);
    orb76_run(['git', '-C', $work, 'add', 'README.md']);
    orb76_run(['git', '-C', $work, 'commit', '-m', "Advance {$ref}"]);
    orb76_run(['git', '-C', $work, 'push', 'origin', "HEAD:refs/heads/{$ref}"]);

    return trim(orb76_run(['git', '-C', $work, 'rev-parse', 'HEAD'])->stdout);
}

function orb124_commit_unpublished(string $checkout): int
{
    orb76_run(['git', '-C', $checkout, 'config', 'user.name', 'Orbit Test']);
    orb76_run(['git', '-C', $checkout, 'config', 'user.email', 'orbit@example.test']);
    file_put_contents($checkout.'/unpublished.txt', 'unpublished after quarantine');
    orb76_run(['git', '-C', $checkout, 'add', 'unpublished.txt']);
    orb76_run(['git', '-C', $checkout, 'commit', '-m', 'Unpublished after quarantine']);

    return 1;
}

final class Orb76LocalSourceSshExecutor implements \App\Infrastructure\Ssh\SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public ?\Closure $beforeFinalization = null;

    public function __construct(
        private readonly string $remoteOrigin,
        private readonly string $localOrigin,
    ) {}

    public function execute(
        \App\Infrastructure\Ssh\SshConnection $connection,
        \App\Infrastructure\Ssh\RemoteCommand $command,
    ): \App\Infrastructure\Processes\CommandResult {
        $this->commands[] = $command;
        $input = $command->input;

        if (is_string($input) && str_contains($input, 'receipt_candidate=')) {
            $checkout = $command->arguments[3] ?? null;

            if (is_string($checkout) && is_dir($checkout)) {
                $configuredOrigin = trim(orb76_run(['git', '-C', $checkout, 'remote', 'get-url', 'origin'])->stdout);

                if ($configuredOrigin === $this->localOrigin) {
                    orb76_run(['git', '-C', $checkout, 'remote', 'set-url', 'origin', $this->remoteOrigin]);
                }
            }

            if ($this->beforeFinalization instanceof \Closure) {
                $beforeFinalization = $this->beforeFinalization;
                $this->beforeFinalization = null;
                $beforeFinalization();
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

        $stdout = str_replace(
            [
                $this->localOrigin,
                base64_encode($this->localOrigin),
            ],
            [
                $this->remoteOrigin,
                base64_encode($this->remoteOrigin),
            ],
            $result->stdout,
        );

        return new CommandResult(
            exitCode: $result->exitCode,
            stdout: $stdout,
            stderr: $result->stderr,
            durationMs: $result->durationMs,
            truncated: $result->truncated,
        );
    }
}
