<?php

declare(strict_types=1);

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyVerifier;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} */
function legacyAcquisitionWorktree(): array
{
    $sourceRoot = dirname(__DIR__, 5);
    $worktree = temporaryPath('orbit-legacy-acquisition-', 4);
    $branch = 'orb-4-legacy-acquisition-'.bin2hex(random_bytes(6));
    $processes = new ProcessFactory;

    expect(
        $processes->run(['git', '-C', $sourceRoot, 'worktree', 'add', '--detach', $worktree, 'HEAD'])->successful(),
    )->toBeTrue();
    expect($processes->run(['git', '-C', $worktree, 'switch', '-c', $branch])->successful())->toBeTrue();

    return compact('sourceRoot', 'worktree', 'branch', 'processes');
}

/** @param array{sourceRoot: string, worktree: string, branch: string, processes: ProcessFactory} $fixture */
function removeLegacyAcquisitionWorktree(array $fixture): void
{
    $fixture['processes']->run([
        'git',
        '-C',
        $fixture['sourceRoot'],
        'worktree',
        'remove',
        '--force',
        $fixture['worktree'],
    ]);
    $fixture['processes']->run([
        'git',
        '-C',
        $fixture['sourceRoot'],
        'branch',
        '-D',
        $fixture['branch'],
    ]);
}

function legacyAcquisitionGeneration(): StandbyGeneration
{
    return new StandbyGeneration(
        'legacy-generation',
        str_repeat('a', 40),
        [
            'gateway' => 'main-legacy-gateway',
            'app-dev' => 'main-legacy-app-dev',
            'app-prod' => 'main-legacy-app-prod',
        ],
        str_repeat('b', 64),
        str_repeat('c', 64),
        new LaravelRelease('v13.10.1', str_repeat('d', 40)),
        str_repeat('e', 64),
        1,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        topologyAssignments: null,
        manifestSchema: StandbyGeneration::LEGACY_SCHEMA,
    );
}

function topologyAcquirerWithLegacyGeneration(
    string $repositoryRoot,
    StatePaths $paths,
    StandbyManifestStore $manifests,
): TopologyAcquirer {
    $host = new IncusHost(pool: 'orbit-e2e');
    $operation = new OperationId(str_repeat('f', 32));

    return new TopologyAcquirer(
        $host,
        new IncusNetworkLifecycle($host),
        new PreparedStateFingerprint(new GitRepository($repositoryRoot)),
        $manifests,
        new WorktreeSynchronizer($host, $repositoryRoot, $operation),
        new TopologyVerifier($host, 1, 0),
        new DiscoveryGuestPreparer($host),
        new HostCapacity($host, 24),
        $paths,
        $operation,
        StandbyIdentity::primary(),
        $repositoryRoot,
        fn () => attemptId(),
    );
}

it('refuses acquisition from a schema 4 generation before creating an attempt', function () {
    $fixture = legacyAcquisitionWorktree();

    try {
        $paths = new StatePaths(temporaryPath('orbit-legacy-acquisition-state-', 4));
        $state = new AtomicJsonStore($paths);
        $state->write('standby/promoted.json', legacyAcquisitionGeneration()->toArray());
        $manifests = new StandbyManifestStore(
            $state,
            $paths,
            new IncusHost(pool: 'orbit-e2e'),
            StandbyIdentity::primary(),
        );
        $request = new TopologyRequest('ORB-4', $fixture['worktree']);

        expect(
            fn () => topologyAcquirerWithLegacyGeneration(
                $fixture['sourceRoot'],
                $paths,
                $manifests,
            )->acquire($request),
        )
            ->toThrow(RuntimeException::class, 'legacy; refresh it before acquisition')
            ->and(IssueState::forWorktree('ORB-4', $fixture['worktree'])->hasAttempt())
            ->toBeFalse();
    } finally {
        removeLegacyAcquisitionWorktree($fixture);
    }
});
