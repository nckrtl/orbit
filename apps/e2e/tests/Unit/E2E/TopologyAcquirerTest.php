<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;

uses(Tests\TestCase::class);

function taskNineAcquirer(
    string $repositoryRoot,
    StatePaths $paths,
    ?AcquisitionRollback $rollback = null,
): TopologyAcquirer {
    $store = new AtomicJsonStore($paths);
    $host = new IncusHost;

    return new TopologyAcquirer(
        $host,
        new PreparedStateFingerprint(new GitRepository($repositoryRoot)),
        new StandbyManifestStore($store, $paths),
        new TopologyManifestStore($store),
        new WorktreeSynchronizer($host, $repositoryRoot, $repositoryRoot.'/apps/e2e/resources/guest'),
        new TopologyConverger($host),
        new TopologyVerifier($host),
        new LaravelReleaseResolver,
        $store,
        $paths,
        $repositoryRoot,
        $rollback,
    );
}

function preparedTopologyRepository(): string
{
    $root = sys_get_temp_dir().'/orbit-prepared-topology-'.bin2hex(random_bytes(8));
    $e2e = dirname(__DIR__, 3);
    $manifestPath = 'apps/e2e/resources/prepared-state.json';
    mkdir($root.'/'.dirname($manifestPath), 0700, true);
    copy($e2e.'/resources/prepared-state.json', $root.'/'.$manifestPath);
    $manifest = json_decode((string) file_get_contents($root.'/'.$manifestPath), true, 512, JSON_THROW_ON_ERROR);

    foreach ($manifest['paths'] as $pattern) {
        if ($pattern === $manifestPath) {
            continue;
        }
        $path = str_replace(['**/', '*'], ['nested/', 'placeholder'], $pattern);
        $directory = $root.'/'.dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($root.'/'.$path, 'prepared');
    }
    foreach ([
        ['git', 'init', '-q', '-b', 'feature/NCK-123', $root],
        ['git', '-C', $root, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $root, 'config', 'user.name', 'Orbit Developer'],
        ['git', '-C', $root, 'add', '.'],
        ['git', '-C', $root, 'commit', '-q', '-m', 'Prepared state'],
    ] as $command) {
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare the topology fixture repository.');
        }
    }

    return $root;
}

describe('topology acquisition values', function () {
    it('requires an exact issue and a real absolute worktree', function () {
        expect(fn () => new TopologyRequest('feature-12', __DIR__))
            ->toThrow(InvalidArgumentException::class, 'Linear issue ID');
        expect(fn () => new TopologyRequest('NCK-12', 'relative/path'))
            ->toThrow(InvalidArgumentException::class, 'absolute');

        $request = new TopologyRequest('NCK-12', dirname(__DIR__, 4));

        expect($request->target->issue)
            ->toBe('NCK-12')
            ->and($request->worktree)
            ->toBe(realpath(dirname(__DIR__, 4)));
    });

    it('binds proof to exact candidate and tree identities', function () {
        $proof = new ProofResult(
            str_repeat('a', 32),
            str_repeat('b', 32),
            str_repeat('c', 40),
            str_repeat('e', 40),
            str_repeat('d', 64),
            new VerificationReport(true, ['candidate.probes' => true]),
        );

        expect($proof->toArray())->toMatchArray([
            'state' => 'proved',
            'candidate_sha' => str_repeat('c', 40),
            'candidate_tree' => str_repeat('e', 40),
            'tree_hash' => str_repeat('d', 64),
        ]);
    });

    it('checks copied ownership before applying issue metadata', function () {
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            if (str_contains(implode(' ', $process->command ?? []), 'list')) {
                return Process::result(json_encode([[
                    'name' => 'orbit-e2e-nck-123-gateway',
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'status_code' => 102,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });

        $host = new IncusHost(remote: 'lab', project: 'orbit', pool: 'orbit-e2e');
        $host->setMetadata('orbit-e2e-nck-123-gateway', ['user.orbit.e2e.issue' => 'NCK-123']);

        Process::assertRanInOrder([
            ['incus', '--project', 'orbit', 'list', 'lab:orbit-e2e-nck-123-gateway', '--format=json'],
            [
                'incus',
                '--project',
                'orbit',
                'config',
                'set',
                'lab:orbit-e2e-nck-123-gateway',
                'user.orbit.e2e.issue=NCK-123',
            ],
        ]);
    });

    it('limits proof checkout identity checks to the configured checkout roles', function () {
        expect(\App\E2E\Value\TopologyProfile::CHECKOUT_ROLES)->toBe(['gateway', 'app-dev']);
    });

    it('preflights every rollback target before any deletion', function () {
        $read = [];
        $mutations = [];
        $rollback = new AcquisitionRollback(
            function (string $resource) use (&$read): IncusInstance|IncusNetwork|null {
                $read[] = $resource;

                return new IncusInstance('lab', 'orbit', $resource, 'orbit-e2e', [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'NCK-123',
                    'user.orbit.e2e.operation' => 'operation-1',
                ]);
            },
            function (string $resource) use (&$mutations): void {
                $mutations[] = 'stop:'.$resource;
            },
            function (string $resource) use (&$mutations): void {
                $mutations[] = 'delete:'.$resource;
            },
            function (string $resource) use (&$mutations): void {
                $mutations[] = 'network:'.$resource;
            },
        );
        $target = new TopologyTarget('NCK-123');
        $identity = static fn (string $name): array => [
            'remote' => 'lab',
            'project' => 'orbit',
            'name' => $name,
            'pool' => 'orbit-e2e',
            'metadata' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.operation' => 'operation-1',
            ],
        ];

        $result = $rollback->cleanup(
            $target,
            ['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev'],
            [
                'orbit-e2e-nck-123-gateway' => $identity('orbit-e2e-nck-123-gateway'),
                'orbit-e2e-nck-123-app-dev' => ['remote' => 'lab'],
            ],
            new OperationId('operation-1'),
        );

        expect($result['orbit-e2e-nck-123-gateway'])
            ->toBe('retained_due_to_preflight_failure')
            ->and($mutations)
            ->toBeEmpty()
            ->and($read)
            ->toBe(['orbit-e2e-nck-123-gateway', 'orbit-e2e-nck-123-app-dev']);
    });

    it('refuses replacement ownership and re-reads before rollback mutation', function () {
        $reads = 0;
        $mutations = [];
        $rollback = new AcquisitionRollback(
            function (string $resource) use (&$reads): IncusInstance {
                $reads++;
                $metadata = $reads === 2
                    ? ['user.orbit.e2e.owner' => 'replacement']
                    : [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                        'user.orbit.e2e.operation' => 'operation-1',
                    ];

                return new IncusInstance('lab', 'orbit', $resource, 'orbit-e2e', $metadata);
            },
            function (string $resource) use (&$mutations): void {
                $mutations[] = 'stop:'.$resource;
            },
            function (string $resource) use (&$mutations): void {
                $mutations[] = 'delete:'.$resource;
            },
            function (string $resource) use (&$mutations): void {
                $mutations[] = 'network:'.$resource;
            },
        );
        $target = new TopologyTarget('NCK-123');
        $identity = [
            'remote' => 'lab',
            'project' => 'orbit',
            'name' => 'orbit-e2e-nck-123-gateway',
            'pool' => 'orbit-e2e',
            'metadata' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => 'NCK-123',
                'user.orbit.e2e.operation' => 'operation-1',
            ],
        ];

        $result = $rollback->cleanup(
            $target,
            ['orbit-e2e-nck-123-gateway'],
            ['orbit-e2e-nck-123-gateway' => $identity],
            new OperationId('operation-1'),
        );

        expect($result['orbit-e2e-nck-123-gateway'])
            ->toStartWith('failed:')
            ->and($reads)
            ->toBe(2)
            ->and($mutations)
            ->toBeEmpty();
    });

    it('uses the acquisition rollback after a topology creation failure', function () {
        $repositoryRoot = preparedTopologyRepository();
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
        $store = new AtomicJsonStore($paths);
        $fingerprints = new PreparedStateFingerprint(new GitRepository($repositoryRoot));
        $prepared = $fingerprints->forCommit();
        $generation = new StandbyGeneration(
            'g-'.substr($prepared->value, 0, 12),
            new GitRepository($repositoryRoot)->commit(),
            [
                'gateway' => 'main-gateway',
                'app-dev' => 'main-app-dev',
                'app-prod' => 'main-app-prod',
            ],
            $prepared->value,
            str_repeat('a', 64),
        );
        new StandbyManifestStore($store, $paths)->promote($generation);
        $reads = [];
        $rollback = new AcquisitionRollback(
            function (string $resource) use (&$reads): never {
                $reads[] = $resource;

                throw new RuntimeException('rollback boundary used');
            },
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );
        $target = new TopologyTarget('NCK-123');
        $realProcess = new ProcessFactory;
        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
            $target,
            $repositoryRoot,
            $realProcess,
        ) {
            if (($process->command[0] ?? null) === 'git') {
                return $realProcess->path($repositoryRoot)->run($process->command);
            }
            if (
                $process->command === [
                    'incus',
                    '--project',
                    'default',
                    'copy',
                    'local:orbit-e2e-standby-gateway/main-gateway',
                    'local:'.$target->instance('gateway'),
                    '--storage',
                    'default',
                ]
            ) {
                return Process::result('', 'copy failed', 1);
            }
            if (
                $process->command === [
                    'incus',
                    '--project',
                    'default',
                    'list',
                    'local:'.$target->network(),
                    '--format=json',
                ]
            ) {
                return Process::result('[]');
            }
            if (
                $process->command === [
                    'incus',
                    '--project',
                    'default',
                    'network',
                    'list',
                    'local:',
                    '--format=json',
                ]
            ) {
                return Process::result(json_encode([[
                    'name' => $target->network(),
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.issue' => 'NCK-123',
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result();
        });
        $acquirer = taskNineAcquirer($repositoryRoot, $paths, $rollback);
        $method = new ReflectionMethod($acquirer, 'acquirePinned');
        $request = new TopologyRequest('NCK-123', $repositoryRoot);

        expect(fn () => $method->invoke($acquirer, $request, new OperationId(str_repeat('a', 32))))
            ->toThrow(RuntimeException::class, 'copy failed')
            ->and($reads)
            ->toBe([$target->network()]);
    });

    it('rejects unrelated clean repositories before lock state or Incus access', function () {
        $repositoryRoot = dirname(__DIR__, 5);
        $unrelated = sys_get_temp_dir().'/orbit-unrelated-'.bin2hex(random_bytes(8));
        mkdir($unrelated, 0o700);
        foreach ([
            ['git', 'init', '-q', '-b', 'feature/NCK-12', $unrelated],
            ['git', '-C', $unrelated, 'config', 'user.email', 'developer@example.com'],
            ['git', '-C', $unrelated, 'config', 'user.name', 'Orbit Developer'],
            ['git', '-C', $unrelated, 'commit', '--allow-empty', '-q', '-m', 'Initial'],
        ] as $command) {
            expect(Process::run($command)->successful())->toBeTrue();
        }
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
        $acquirer = taskNineAcquirer($repositoryRoot, $paths);

        expect(fn () => $acquirer->sync(new TopologyRequest('NCK-12', $unrelated)))
            ->toThrow(InvalidArgumentException::class, 'repository identity')
            ->and(is_dir($paths->root().'/locks'))
            ->toBeFalse();
        expect(fn () => $acquirer->prove(new TopologyRequest('NCK-12', $unrelated), str_repeat('a', 40)))
            ->toThrow(InvalidArgumentException::class, 'repository identity')
            ->and(is_dir($paths->root().'/locks'))
            ->toBeFalse();
    });

    it('rejects a wrong issue branch before creating lifecycle state', function () {
        $repositoryRoot = dirname(__DIR__, 5);
        $inventory = Process::run(['git', '-C', $repositoryRoot, 'worktree', 'list', '--porcelain']);
        preg_match('/\Aworktree ([^\r\n]+)/', $inventory->output(), $matches);
        $branchWorktree = $matches[1] ?? '';
        expect($inventory->successful())
            ->toBeTrue()
            ->and($branchWorktree)
            ->not->toBe('');
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-acquirer-state-'.bin2hex(random_bytes(8)));
        $acquirer = taskNineAcquirer($branchWorktree, $paths);

        expect(fn () => $acquirer->sync(new TopologyRequest('NCK-999999', $branchWorktree)))
            ->toThrow(InvalidArgumentException::class, 'branch does not match')
            ->and(is_dir($paths->root().'/locks'))
            ->toBeFalse();
    });
});
