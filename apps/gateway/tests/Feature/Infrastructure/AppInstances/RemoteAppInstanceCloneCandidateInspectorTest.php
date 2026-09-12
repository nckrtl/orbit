<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteAppInstanceCloneCandidateInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;

it('returns bound development source evidence from a valid inspection receipt', function (): void {
    $candidate = orb198_clone_candidate();
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result("OK\t/srv/orbit/apps/acme\t".str_repeat('a', 40)."\n"),
    ]);

    $source = orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'release');

    expect($source->appInstanceId)->toBe($candidate->id)
        ->and($source->environment)->toBe('development')
        ->and($source->basePath)->toBe('/srv/orbit/apps/acme')
        ->and($source->executionUser)->toBe('orbit')
        ->and($source->branch)->toBe('main')
        ->and($source->commit)->toBe(str_repeat('a', 40))
        ->and($source->node->is($candidate->node))->toBeTrue();

    expect($ssh->connections)->toHaveCount(1)
        ->and($ssh->connections[0]->host)->toBe('10.44.0.20')
        ->and($ssh->connections[0]->user)->toBe('orbit')
        ->and($ssh->connections[0]->identityFile)->toBe('/tmp/orbit-clone-test-key')
        ->and($ssh->connections[0]->knownHostsFile)->toBe('/tmp/orbit-clone-known-hosts')
        ->and($ssh->connections[0]->commandTimeout)->toBe(120.0);

    expect($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            'development',
            '/srv/orbit/apps/acme',
            'orbit',
            'ssh://git@example.test/acme.git',
            'main',
            'release',
            '/srv/orbit/apps/acme',
        ])
        ->and($ssh->commands[0]->input)->toContain(
            'status --porcelain=v1 --untracked-files=all --ignore-submodules=none',
            'submodule status --recursive',
            'submodule foreach --recursive --quiet',
            "'+refs/heads/*:refs/remotes/origin/*'",
            'cat-file -e "$commit^{commit}"',
            'show-ref --verify --quiet',
        );
});

it('uses the production runtime identity and configured deployment branch for the selected release', function (): void {
    $candidate = orb198_clone_candidate('production');
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result(
            "OK\t/home/orbit-app-1/releases/20260912010101-one\t".str_repeat('b', 64)."\n",
        ),
    ]);

    $source = orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'target-release');

    expect($source->environment)->toBe('production')
        ->and($source->basePath)->toBe('/home/orbit-app-1/releases/20260912010101-one')
        ->and($source->executionUser)->toBe('orbit-app-1')
        ->and($source->branch)->toBe('production-release')
        ->and($source->commit)->toBe(str_repeat('b', 64));

    expect($ssh->commands[0]->arguments)->toBe([
        'bash',
        '-seu',
        '--',
        'production',
        '/home/orbit-app-1',
        'orbit-app-1',
        'ssh://git@example.test/acme.git',
        'production-release',
        'target-release',
        '/home/orbit-app-1/releases/20260912010101-one',
    ]);
});

it('refuses every dirty candidate classification', function (string $dirtyState): void {
    $candidate = orb198_clone_candidate();
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result("REFUSED\tinstance.clone_candidate_dirty\n"),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception) use ($dirtyState): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_dirty')
                ->and($exception->getMessage())->toBe('The candidate is not eligible for cloning.')
                ->and($dirtyState)->toBeString();
        });
})->with([
    'staged changes' => 'staged',
    'unstaged changes' => 'unstaged',
    'nonignored untracked files' => 'untracked',
    'changed submodule worktree' => 'submodule',
]);

it('reports repository and selected branch failures without changing their error codes', function (string $errorCode): void {
    $candidate = orb198_clone_candidate();
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result("REFUSED\t{$errorCode}\n"),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'release'))
        ->toThrow(function (ResourceOperationException $exception) use ($errorCode): void {
            expect($exception->errorCode)->toBe($errorCode)
                ->and($exception->getMessage())->toBe('The candidate is not eligible for cloning.');
        });
})->with([
    'repository unavailable' => 'instance.clone_candidate_repository_unavailable',
    'candidate commit unavailable' => 'instance.clone_candidate_commit_unavailable',
    'target branch missing' => 'instance.clone_target_branch_missing',
    'configured branch changed' => 'instance.clone_candidate_branch_invalid',
]);

it('refuses unsuccessful truncated stderr and thrown SSH inspections as unavailable', function (
    Orb198CloneCandidateSshExecutor $ssh,
): void {
    $candidate = orb198_clone_candidate();

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_unavailable');
        });
})->with([
    'nonzero exit' => fn (): Orb198CloneCandidateSshExecutor => new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result('', exitCode: 1),
    ]),
    'stderr' => fn (): Orb198CloneCandidateSshExecutor => new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result('', stderr: "unexpected\n"),
    ]),
    'truncated' => fn (): Orb198CloneCandidateSshExecutor => new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result('', truncated: true),
    ]),
    'transport exception' => fn (): Orb198CloneCandidateSshExecutor => new Orb198CloneCandidateSshExecutor(
        exception: new RuntimeException('SSH unavailable.'),
    ),
]);

it('refuses malformed successful inspection receipts', function (string $receipt): void {
    $candidate = orb198_clone_candidate();
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result($receipt),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_unavailable')
                ->and($exception->getMessage())->toBe('The candidate inspection was invalid.');
        });
})->with([
    'empty' => '',
    'unknown receipt' => "UNKNOWN\t/srv/orbit/apps/acme\t".str_repeat('a', 40)."\n",
    'missing source' => "OK\t\t".str_repeat('a', 40)."\n",
    'short commit' => "OK\t/srv/orbit/apps/acme\tabcd\n",
    'uppercase commit' => "OK\t/srv/orbit/apps/acme\t".str_repeat('A', 40)."\n",
    'extra member' => "OK\t/srv/orbit/apps/acme\t".str_repeat('a', 40)."\textra\n",
    'refusal without code' => "REFUSED\t\n",
]);

it('refuses a production receipt that is not the recorded selected release', function (): void {
    $candidate = orb198_clone_candidate('production');
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result(
            "OK\t/home/orbit-app-1/releases/different\t".str_repeat('c', 40)."\n",
        ),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_source_invalid');
        });
});

it('refuses invalid local candidate and Node identities before SSH', function (Closure $mutate): void {
    $candidate = orb198_clone_candidate();
    $mutate($candidate);
    $candidate->save();
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result("OK\t/srv/orbit/apps/acme\t".str_repeat('d', 40)."\n"),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(ResourceOperationException::class)
        ->and($ssh->commands)->toBeEmpty();
})->with([
    'candidate inactive' => function (AppInstance $candidate): void {
        $candidate->status = AppInstanceState::SourceResolved;
    },
    'candidate lifecycle incomplete' => function (AppInstance $candidate): void {
        $candidate->provisioning_step = 'source-resolved';
    },
    'candidate migration pending' => function (AppInstance $candidate): void {
        $candidate->migration_required = true;
    },
    'Node inactive' => function (AppInstance $candidate): void {
        $candidate->node->update(['status' => LifecycleStatus::Failed]);
    },
    'Node platform unsupported' => function (AppInstance $candidate): void {
        $candidate->node->update(['platform' => 'darwin']);
    },
    'Node address missing' => function (AppInstance $candidate): void {
        $candidate->node->update(['wireguard_ip' => null]);
    },
    'runtime user invalid' => function (AppInstance $candidate): void {
        $candidate->node->update(['user' => 'Invalid User']);
    },
    'configured branch missing' => function (AppInstance $candidate): void {
        $candidate->branch = null;
    },
    'source path relative' => function (AppInstance $candidate): void {
        $candidate->checkout_path = 'relative/source';
    },
]);

it('refuses an invalid production transport user before SSH', function (): void {
    $candidate = orb198_clone_candidate('production');
    $candidate->node->update(['user' => 'Invalid User']);
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result(
            "OK\t/home/orbit-app-1/releases/20260912010101-one\t".str_repeat('e', 40)."\n",
        ),
    ]);

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_candidate_source_invalid');
        })
        ->and($ssh->commands)->toBeEmpty();
});

it('refuses production candidates without complete selected release identity', function (Closure $mutate): void {
    $candidate = orb198_clone_candidate('production');
    $mutate($candidate);
    $candidate->save();
    $ssh = new Orb198CloneCandidateSshExecutor;

    expect(fn () => orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'main'))
        ->toThrow(ResourceOperationException::class)
        ->and($ssh->commands)->toBeEmpty();
})->with([
    'release layout missing' => function (AppInstance $candidate): void {
        $candidate->checkout_path = '/home/orbit-app-1';
    },
    'production home missing' => function (AppInstance $candidate): void {
        $candidate->production_home = null;
    },
    'production runtime user missing' => function (AppInstance $candidate): void {
        $candidate->production_user = null;
    },
    'production runtime user invalid' => function (AppInstance $candidate): void {
        $candidate->production_user = 'Invalid User';
    },
    'configured branch missing' => function (AppInstance $candidate): void {
        $candidate->branch = null;
        $candidate->deployment_branch = null;
    },
]);

function orb198_clone_candidate(string $environment = 'development'): AppInstance
{
    $node = Node::query()->create([
        'name' => "clone-candidate-{$environment}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme clone candidate',
        'slug' => 'acme-clone-candidate',
        'repository_url' => 'ssh://git@example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $production = $environment === 'production';

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'candidate',
        'environment' => $environment,
        'source_layout' => 'checkout',
        'checkout_path' => $production
            ? '/home/orbit-app-1/releases/20260912010101-one'
            : '/srv/orbit/apps/acme',
        'production_user' => $production ? 'orbit-app-1' : null,
        'production_home' => $production ? '/home/orbit-app-1' : null,
        'branch' => 'main',
        'deployment_branch' => $production ? 'production-release' : null,
        'starting_commit' => str_repeat('1', 40),
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
}

function orb198_clone_candidate_inspector(
    Orb198CloneCandidateSshExecutor $ssh,
): RemoteAppInstanceCloneCandidateInspector {
    return new RemoteAppInstanceCloneCandidateInspector(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-clone-test-key';
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
                return '/tmp/orbit-clone-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

function orb198_clone_candidate_result(
    string $stdout,
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 1, $truncated);
}

final class Orb198CloneCandidateSshExecutor implements SshExecutor
{
    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results = [],
        private ?Throwable $exception = null,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        if ($this->exception instanceof Throwable) {
            throw $this->exception;
        }

        $result = array_shift($this->results);

        if (! $result instanceof CommandResult) {
            throw new RuntimeException('No clone candidate inspection result was queued.');
        }

        return $result;
    }
}
