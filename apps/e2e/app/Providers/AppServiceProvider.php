<?php

declare(strict_types=1);

namespace App\Providers;

use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\HostCapacity;
use App\E2E\HostRelativeDeleter;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\LegacyIncusRevalidator;
use App\E2E\LegacyRetirement;
use App\E2E\LegacyRetirementHost;
use App\E2E\PreparedStateFingerprint;
use App\E2E\ProofRecordReader;
use App\E2E\ReleaseReceiptStore;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyRefresher;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyReaper;
use App\E2E\TopologyReleaser;
use App\E2E\TopologyVerifier;
use App\E2E\Value\OperationId;
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
        $this->app->singleton(StatePaths::class, fn (): StatePaths => StatePaths::fromEnvironment());
        $this->app->singleton(AtomicJsonStore::class);
        $this->app->singleton(HostCapacity::class, fn (Application $app): HostCapacity => new HostCapacity(
            $app->make(AtomicJsonStore::class),
            $app->make(StatePaths::class),
            $app->make(OperationId::class),
            (int) $app->make(Repository::class)->get('e2e.incus.max_vms', 12),
            $app->make(IncusHost::class),
        ));
        $this->app->singleton(SecretRedactor::class);
        $this->app->singleton(OperationJournal::class);
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
        $repositoryRoot = dirname(__DIR__, 4);
        $this->app->singleton(GitRepository::class, fn (): GitRepository => new GitRepository($repositoryRoot));
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
                journal: $app->make(OperationJournal::class),
                operationId: $app->make(OperationId::class),
            );
        });
        $this->app->bind(GuestTransport::class, fn (Application $app): IncusHost => $app->make(IncusHost::class));
        $this->app->singleton(IncusNetworkLifecycle::class);
        $this->app->singleton(ProofRecordReader::class);
        $this->app->singleton(ReleaseReceiptStore::class);
        $this->app->singleton(TopologyAcquirer::class, fn (Application $app): TopologyAcquirer => new TopologyAcquirer(
            host: $app->make(IncusHost::class),
            networks: $app->make(IncusNetworkLifecycle::class),
            fingerprints: $app->make(PreparedStateFingerprint::class),
            standby: $app->make(StandbyManifestStore::class),
            manifests: $app->make(TopologyManifestStore::class),
            synchronizer: $app->make(WorktreeSynchronizer::class),
            converger: $app->make(TopologyConverger::class),
            verifier: $app->make(TopologyVerifier::class),
            state: $app->make(AtomicJsonStore::class),
            paths: $app->make(StatePaths::class),
            commandOperation: $app->make(OperationId::class),
            journal: $app->make(OperationJournal::class),
            redactor: $app->make(SecretRedactor::class),
            repositoryRoot: $repositoryRoot,
            capacity: $app->make(HostCapacity::class),
            proofs: $app->make(ProofRecordReader::class),
        ));
        $this->app->singleton(TopologyReleaser::class, fn (Application $app): TopologyReleaser => new TopologyReleaser(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(TopologyManifestStore::class),
            $app->make(AtomicJsonStore::class),
            $app->make(StatePaths::class),
            $app->make(OperationId::class),
            $app->make(ReleaseReceiptStore::class),
            $app->make(HostCapacity::class),
        ));
        $this->app->singleton(TopologyReaper::class, fn (Application $app): TopologyReaper => new TopologyReaper(
            $app->make(AtomicJsonStore::class),
            $app->make(StatePaths::class),
            $app->make(TopologyReleaser::class),
            $app->make(ProofRecordReader::class),
            $app->make(OperationJournal::class),
            $app->make(OperationId::class),
        ));

        $this->app->singleton(StandbyBuilder::class, fn (Application $app): StandbyBuilder => new StandbyBuilder(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(WorktreeSynchronizer::class),
            $app->make(TopologyConverger::class),
            $app->make(TopologyVerifier::class),
            $app->make(StandbyManifestStore::class),
            $app->make(AtomicJsonStore::class),
            $app->make(StatePaths::class),
            $repositoryRoot,
        ));
        $this->app->singleton(StandbyRefresher::class, fn (Application $app): StandbyRefresher => new StandbyRefresher(
            $app->make(IncusHost::class),
            $app->make(IncusNetworkLifecycle::class),
            $app->make(PreparedStateFingerprint::class),
            $app->make(StandbyManifestStore::class),
            $app->make(StandbyBuilder::class),
            $app->make(WorktreeSynchronizer::class),
            $app->make(TopologyConverger::class),
            $app->make(TopologyVerifier::class),
            $app->make(\App\E2E\LaravelReleaseResolver::class),
            $app->make(OperationLock::class),
            new OperationLock($app->make(StatePaths::class)),
            $app->make(OperationJournal::class),
            $app->make(AtomicJsonStore::class),
            $app->make(GitRepository::class),
            $repositoryRoot,
            $app->make(OperationId::class),
        ));
    }

    public function boot(): void
    {
        /** @mago-expect lint:no-ini-set Exception arguments must stay out of infrastructure failure traces. */
        ini_set('zend.exception_ignore_args', '1');
    }
}
