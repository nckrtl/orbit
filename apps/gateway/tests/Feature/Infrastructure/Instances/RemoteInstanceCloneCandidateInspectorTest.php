<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceCloneCandidateInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TaskWorkerSshExecutor;

describe('TaskGitHardening', function (): void {
    it('suppresses fsmonitor through sudo and inside candidate submodules', function (): void {
        $root = sys_get_temp_dir().'/orbit-clone-monitor-'.bin2hex(random_bytes(6));
        $checkout = $root.'/checkout';
        $child = $root.'/child';
        $git = static fn (string $path, array $args): string => (new Process(['git', '-C', $path, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]))->mustRun()->getOutput();
        (new Process(['git', 'init', '-q', '-b', 'main', $checkout]))->mustRun();
        (new Process(['git', 'init', '-q', '-b', 'main', $child]))->mustRun();
        file_put_contents($child.'/readme', 'child');
        $git($child, ['add', '.']);
        $git($child, ['commit', '-qm', 'child']);
        $git($checkout, ['-c', 'protocol.file.allow=always', 'submodule', 'add', '-q', $child, 'nested']);
        $git($checkout, ['commit', '-qm', 'parent']);
        (new Process(['git', 'clone', '-q', '--bare', $checkout, $root.'/origin.git']))->mustRun();
        $candidate = orb198_clone_candidate();
        $candidate->node->update(['user' => posix_getpwuid(posix_geteuid())['name']]);
        $candidate->update(['checkout_path' => $checkout]);
        $candidate->project->update(['repository_url' => $root.'/origin.git']);

        try {
            foreach ([$checkout, $checkout.'/nested'] as $index => $path) {
                $monitor = $checkout.'/.git/monitor-'.$index;
                $marker = $checkout.'/.git/monitor-ran-'.$index;
                file_put_contents($monitor, "#!/bin/sh\nprintf ran >> '$marker'\n");
                chmod($monitor, 0755);
                $git($path, ['config', 'core.fsmonitor', $monitor]);
                $git($path, ['status', '--porcelain']);
                expect(file_exists($marker))->toBeTrue();
                unlink($marker);
            }
            // The parent control can also trigger the child. Clear every control marker before inspection.
            foreach (glob($checkout.'/.git/monitor-ran-*') ?: [] as $marker) {
                unlink($marker);
            }
            $source = orb198_clone_candidate_inspector(new LocalShellSshExecutor)->inspect($candidate, 'main');

            expect($source->basePath)->toBe($checkout)
                ->and(glob($checkout.'/.git/monitor-ran-*'))->toBe([]);
        } finally {
            File::deleteDirectory($root);
        }
    });
});

describe('TaskCheckWorkerUser', function (): void {
    it('inspects candidate content and its clean filter as the worker', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $root = sys_get_temp_dir().'/orbit-clone-worker-'.bin2hex(random_bytes(6));
        $checkout = $root.'/checkout';
        (new Process(['git', 'init', '-q', '-b', 'main', $checkout]))->mustRun();
        file_put_contents($checkout.'/.gitattributes', "readme filter=uid\n");
        file_put_contents($checkout.'/readme', 'clean');
        (new Process(['git', '-C', $checkout, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $checkout, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'parent']))->mustRun();
        (new Process(['git', '-C', $checkout, 'config', 'filter.uid.clean', 'id -u > .git/filter-user; cat']))->mustRun();
        file_put_contents($checkout.'/readme', 'dirty');
        $candidate = orb198_clone_candidate();
        $candidate->node->update(['user' => posix_getpwuid(posix_geteuid())['name']]);
        $candidate->update(['checkout_path' => $checkout]);
        $transport = TaskWorkerSshExecutor::forCheckout($checkout);
        (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $root]))->mustRun();

        try {
            expect(fn () => orb198_clone_candidate_inspector($transport)->inspect($candidate, 'main'))->toThrow(ResourceOperationException::class)
                ->and(trim((string) file_get_contents($checkout.'/.git/filter-user')))->toBe('65534');
        } finally {
            File::deleteDirectory($root);
        }
    });
});

it('returns bound development source evidence from a valid inspection receipt', function (): void {
    $candidate = orb198_clone_candidate();
    $ssh = new Orb198CloneCandidateSshExecutor([
        orb198_clone_candidate_result("OK\t/srv/orbit/apps/acme\t".str_repeat('a', 40)."\n"),
    ]);

    $source = orb198_clone_candidate_inspector($ssh)->inspect($candidate, 'release');

    expect($source->instanceId)->toBe($candidate->id)
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
            'elif [ "$environment" = development ]',
            'submodule status --recursive',
            'submodule foreach --recursive --quiet',
            "'+refs/heads/*:refs/remotes/origin/*'",
            'cat-file -e "$commit^{commit}" 2>/dev/null',
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
    'candidate inactive' => function (Instance $candidate): void {
        $candidate->status = InstanceState::SourceResolved;
    },
    'candidate lifecycle incomplete' => function (Instance $candidate): void {
        $candidate->provisioning_step = 'source-resolved';
    },
    'Node inactive' => function (Instance $candidate): void {
        $candidate->node->update(['status' => LifecycleStatus::Failed]);
    },
    'Node platform unsupported' => function (Instance $candidate): void {
        $candidate->node->update(['platform' => 'darwin']);
    },
    'Node address missing' => function (Instance $candidate): void {
        $candidate->node->update(['wireguard_ip' => null]);
    },
    'runtime user invalid' => function (Instance $candidate): void {
        $candidate->node->update(['user' => 'Invalid User']);
    },
    'configured branch missing' => function (Instance $candidate): void {
        $candidate->branch = null;
    },
    'source path relative' => function (Instance $candidate): void {
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
    'release layout missing' => function (Instance $candidate): void {
        $candidate->checkout_path = '/home/orbit-app-1';
    },
    'production home missing' => function (Instance $candidate): void {
        $candidate->production_home = null;
    },
    'production runtime user missing' => function (Instance $candidate): void {
        $candidate->production_user = null;
    },
    'production runtime user invalid' => function (Instance $candidate): void {
        $candidate->production_user = 'Invalid User';
    },
    'configured branch missing' => function (Instance $candidate): void {
        $candidate->branch = null;
        $candidate->deployment_branch = null;
    },
]);

function orb198_clone_candidate(string $environment = 'development'): Instance
{
    $node = Node::query()->create([
        'name' => "clone-candidate-{$environment}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'role' => $environment === 'production' ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $project = Project::query()->create([
        'name' => 'Acme clone candidate',
        'slug' => 'acme-clone-candidate',
        'repository_url' => 'ssh://git@example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $production = $environment === 'production';

    return Instance::query()->create([
        'project_id' => $project->id,
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
        'status' => InstanceState::Active,
    ]);
}

function orb198_clone_candidate_inspector(
    SshExecutor $ssh,
): RemoteInstanceCloneCandidateInspector {
    return new RemoteInstanceCloneCandidateInspector(
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
        app(RepositoryReadAccess::class),
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
