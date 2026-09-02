<?php

declare(strict_types=1);

namespace App\Providers;

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\HostCapacity;
use App\E2E\HostRelativeDeleter;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\LegacyIncusRevalidator;
use App\E2E\LegacyRetirement;
use App\E2E\LegacyRetirementHost;
use App\E2E\OrphanNetworkSweep;
use App\E2E\PreparedStateFingerprint;
use App\E2E\ProofFixtureStager;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologyProofRunner;
use App\E2E\TopologyReleaser;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotBuilder;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotPromoter;
use App\E2E\TopologySnapshotRebuilder;
use App\E2E\TopologySnapshotRefresher;
use App\E2E\TopologyVerifier;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\WorktreeLocator;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Infrastructure bindings validate their complete configuration at startup. */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationId::class, function (Application $app): OperationId {
            $value = $app->make(Repository::class)->get('e2e.incus.operation_id');
            $value = is_string($value) && $value !== '' ? $value : bin2hex(random_bytes(16));

            return new OperationId($value);
        });
        $this->app->singleton(
            TopologySnapshotIdentity::class,
            static fn (): TopologySnapshotIdentity => TopologySnapshotIdentity::primary(),
        );
        $repositoryRoot = dirname(__DIR__, 4);
        $this->app->singleton(GitRepository::class, fn (): GitRepository => new GitRepository($repositoryRoot));
        // Host-wide state (topology snapshot generation, locks) lives in the primary checkout's `.e2e/`.
        $this->app->singleton(
            StatePaths::class,
            fn (): StatePaths => StatePaths::forPrimary(self::primaryCheckout($repositoryRoot)),
        );
        $this->app->singleton(AtomicJsonStore::class);
        $this->app->singleton(
            WorktreeLocator::class,
            fn (): WorktreeLocator => new WorktreeLocator(self::primaryCheckout($repositoryRoot)),
        );
        $this->app->singleton(SecretRedactor::class);
        $this->app->bind(OperationLock::class);
        $this->app->singleton(LegacyRetirementHost::class, fn (): LegacyRetirementHost => new LegacyRetirementHost(
            new LegacyIncusRevalidator,
            new HostRelativeDeleter(dirname(__DIR__, 2).'/resources/host/delete-relative.py'),
        ));
        $this->app->singleton(LegacyRetirement::class, function (Application $app): LegacyRetirement {
            $host = $app->make(LegacyRetirementHost::class);

            return new LegacyRetirement(
                $host->observe(...),
                $host->mutate(...),
                fn (): \DateTimeImmutable => new \DateTimeImmutable('now'),
                $app->make(OperationLock::class),
                $app->make(OperationId::class),
                $host->observeCurrent(...),
            );
        });
        $this->app->singleton(
            PreparedStateFingerprint::class,
            fn (Application $app): PreparedStateFingerprint => new PreparedStateFingerprint($app->make(GitRepository::class)),
        );
        $this->app->singleton(
            WorktreeSynchronizer::class,
            fn (Application $app): WorktreeSynchronizer => new WorktreeSynchronizer(
                $app->make(IncusHost::class),
                $repositoryRoot,
                $app->make(OperationId::class),
            ),
        );

        $this->app->singleton(IncusHost::class, function (Application $app): IncusHost {
            $configuration = $app->make(Repository::class);
            $ownership = $configuration->get('e2e.incus.ownership');
            $remote = $configuration->get('e2e.incus.remote');
            $project = $configuration->get('e2e.incus.project');
            $pool = $configuration->get('e2e.incus.storage_pool');

            if (! is_string($remote) || ! is_string($project) || ! is_string($pool)) {
                throw new \RuntimeException('Incus host configuration is invalid.');
            }

            if (! is_array($ownership) || array_is_list($ownership)) {
                throw new \RuntimeException('Incus ownership configuration is invalid.');
            }

            foreach ($ownership as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    throw new \RuntimeException('Incus ownership configuration is invalid.');
                }
            }

            /** @var array<string, string> $ownership */

            return new IncusHost(
                remote: $remote,
                project: $project,
                pool: $pool,
                ownershipMetadata: $ownership,
                redactor: $app->make(SecretRedactor::class),
            );
        });
        $this->app->bind(GuestTransport::class, fn (Application $app): IncusHost => $app->make(IncusHost::class));
        $this->app->singleton(IncusNetworkLifecycle::class);
        $this->app->singleton(HostCapacity::class, fn (Application $app): HostCapacity => new HostCapacity(
            $app->make(IncusHost::class),
            (int) $app->make(Repository::class)->get('e2e.incus.max_vms', 24),
        ));
        $this->app->singleton(TopologySnapshotManifestStore::class, fn (Application $app): TopologySnapshotManifestStore => new TopologySnapshotManifestStore(
            $app->make(AtomicJsonStore::class),
            $app->make(StatePaths::class),
            $app->make(IncusHost::class),
        ));
        $this->app->singleton(DiscoveryGuestPreparer::class);
        $this->app->singleton(TopologyAcquirer::class, fn (Application $app): TopologyAcquirer => new TopologyAcquirer(
            host: $app->make(IncusHost::class),
            networks: $app->make(IncusNetworkLifecycle::class),
            fingerprints: $app->make(PreparedStateFingerprint::class),
            topologySnapshot: $app->make(TopologySnapshotManifestStore::class),
            synchronizer: $app->make(WorktreeSynchronizer::class),
            verifier: $app->make(TopologyVerifier::class),
            guests: $app->make(DiscoveryGuestPreparer::class),
            capacity: $app->make(HostCapacity::class),
            hostPaths: $app->make(StatePaths::class),
            operation: $app->make(OperationId::class),
            topologySnapshotIdentity: $app->make(TopologySnapshotIdentity::class),
            repositoryRoot: $repositoryRoot,
        ));
        $this->app->singleton(
            TopologyProofRunner::class,
            fn (Application $app): TopologyProofRunner => new TopologyProofRunner(
                $app->make(IncusHost::class),
                $app->make(IncusNetworkLifecycle::class),
                $app->make(TopologySnapshotManifestStore::class),
                $app->make(WorktreeSynchronizer::class),
                $app->make(TopologyConverger::class),
                $app->make(TopologyVerifier::class),
                new ProofFixtureStager($app->make(IncusHost::class), $app->make(OperationId::class)),
                $app->make(HostCapacity::class),
                $app->make(StatePaths::class),
                $app->make(OperationId::class),
                $app->make(TopologySnapshotIdentity::class),
                $repositoryRoot,
            ),
        );
        $this->app->singleton(OrphanNetworkSweep::class, fn (Application $app): OrphanNetworkSweep => new OrphanNetworkSweep(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(StatePaths::class),
            $app->make(OperationId::class),
        ));
        $this->app->singleton(TopologyReleaser::class, fn (Application $app): TopologyReleaser => new TopologyReleaser(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(StatePaths::class),
            $app->make(OperationId::class),
            $app->make(OrphanNetworkSweep::class),
        ));

        $this->app->singleton(TopologySnapshotBuilder::class, fn (Application $app): TopologySnapshotBuilder => new TopologySnapshotBuilder(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(WorktreeSynchronizer::class),
            $app->make(TopologyConverger::class),
            $app->make(TopologyVerifier::class),
            $app->make(TopologySnapshotManifestStore::class),
            $app->make(AtomicJsonStore::class),
            $repositoryRoot,
            $app->make(TopologySnapshotIdentity::class),
        ));
        $this->app->singleton(TopologySnapshotPromoter::class, fn (Application $app): TopologySnapshotPromoter => new TopologySnapshotPromoter(
            $app->make(IncusHost::class),
            $app->make(PreparedStateFingerprint::class),
            $app->make(TopologyVerifier::class),
            $app->make(TopologySnapshotManifestStore::class),
            $app->make(TopologyReleaser::class),
            $app->make(OperationLock::class),
            new OperationLock($app->make(StatePaths::class)),
            $app->make(StatePaths::class),
            new GitRepository(self::primaryCheckout($repositoryRoot)),
            $app->make(OperationId::class),
            $app->make(TopologySnapshotIdentity::class),
        ));
        $this->app->singleton(TopologySnapshotRefresher::class, fn (Application $app): TopologySnapshotRefresher => new TopologySnapshotRefresher(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(PreparedStateFingerprint::class),
            $app->make(TopologySnapshotManifestStore::class),
            $app->make(TopologySnapshotBuilder::class),
            $app->make(WorktreeSynchronizer::class),
            $app->make(TopologyConverger::class),
            $app->make(TopologyVerifier::class),
            $app->make(\App\E2E\LaravelReleaseResolver::class),
            $app->make(OperationLock::class),
            new OperationLock($app->make(StatePaths::class)),
            $app->make(AtomicJsonStore::class),
            $app->make(GitRepository::class),
            $repositoryRoot,
            $app->make(OperationId::class),
            $app->make(TopologySnapshotIdentity::class),
            $app->make(TopologySnapshotAvailability::class),
        ));
        $this->app->singleton(TopologySnapshotAvailability::class, fn (Application $app): TopologySnapshotAvailability => new TopologySnapshotAvailability(
            $app->make(IncusHost::class),
            $app->make(TopologySnapshotIdentity::class),
        ));
        $this->app->singleton(TopologySnapshotRebuilder::class, fn (Application $app): TopologySnapshotRebuilder => new TopologySnapshotRebuilder(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(TopologySnapshotManifestStore::class),
            $app->make(StatePaths::class),
            $app->make(OperationLock::class),
            $app->make(OperationId::class),
            $app->make(TopologySnapshotIdentity::class),
        ));
    }

    /**
     * The primary checkout is the first entry of `git worktree list`; it is read
     * directly, not through the Process facade, so a faked process never moves
     * host state.
     */
    private static function primaryCheckout(string $repositoryRoot): string
    {
        $process = new \Symfony\Component\Process\Process(['git', 'worktree', 'list', '--porcelain'], $repositoryRoot);
        $process->run();
        if (! $process->isSuccessful() || preg_match('/^worktree (.+)$/m', $process->getOutput(), $match) !== 1) {
            throw new \RuntimeException('The primary Git worktree cannot be determined.');
        }
        $primary = realpath(trim($match[1]));
        if ($primary === false) {
            throw new \RuntimeException('The primary Git worktree does not exist.');
        }

        return $primary;
    }

    public function boot(): void
    {
        /** @mago-expect lint:no-ini-set Exception arguments must stay out of infrastructure failure traces. */
        ini_set('zend.exception_ignore_args', '1');
    }
}
