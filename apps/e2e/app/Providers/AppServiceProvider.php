<?php

declare(strict_types=1);

namespace App\Providers;

use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\HostRelativeDeleter;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\LegacyIncusRevalidator;
use App\E2E\LegacyRetirement;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyBuilder;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyRefresher;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologyVerifier;
use App\E2E\Value\OperationId;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Infrastructure bindings validate their complete configuration at startup. */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StatePaths::class, fn (): StatePaths => StatePaths::fromEnvironment());
        $this->app->singleton(AtomicJsonStore::class);
        $this->app->singleton(OperationJournal::class);
        $this->app->bind(OperationLock::class);
        $this->app->singleton(LegacyRetirement::class, function (): LegacyRetirement {
            $revalidator = new LegacyIncusRevalidator;
            $deleter = new HostRelativeDeleter(dirname(__DIR__, 2).'/resources/host/delete-relative.py');
            /** @mago-expect lint:cyclomatic-complexity Observation manifests fail closed on each unsafe state. */
            $observe = function (): array {
                $path = getenv('ORBIT_E2E_LEGACY_OBSERVATION');
                if (! is_string($path)) {
                    throw new \RuntimeException('ORBIT_E2E_LEGACY_OBSERVATION must name a protected JSON manifest.');
                }
                $value = LegacyRetirement::readProtectedJson($path);

                /** @var array<string, list<array<string, mixed>>> $value */
                return $value;
            };
            $mutate = function (string $operation, array $resource) use ($deleter, $revalidator): void {
                $identity = $resource['identity'] ?? $resource['name'] ?? $resource['path'] ?? null;
                if (
                    ! is_string($identity)
                    || $identity === ''
                    || str_contains($identity, '/')
                    && ($operation !== 'delete_snapshots'
                    || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $identity)
                    !== 1)
                    || str_contains($identity, ':')
                    || str_starts_with($identity, '-')
                    || str_contains($identity, '*')
                    || str_contains($identity, '?')
                ) {
                    throw new \RuntimeException('The legacy mutation target is unsafe.');
                }
                $remote = $resource['remote'] ?? null;
                $project = $resource['project'] ?? null;
                $incusOperation = in_array(
                    $operation,
                    ['stop', 'delete_snapshots', 'delete_instances', 'delete_networks'],
                    true,
                );
                if (
                    $incusOperation
                    && (! is_string($remote)
                    || ! is_string($project)
                    || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $remote) !== 1
                    || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $project) !== 1)
                ) {
                    throw new \RuntimeException('The legacy Incus target has no exact remote and project identity.');
                }
                $snapshot = null;
                if ($operation === 'delete_snapshots') {
                    [$instance, $snapshot] = explode('/', $identity, 2);
                    $target = "{$remote}:{$instance}";
                } else {
                    $target = is_string($remote) ? "{$remote}:{$identity}" : $identity;
                }
                $projectArgument = is_string($project) ? $project : '';
                $arguments = match ($operation) {
                    'stop' => ['incus', '--project', $projectArgument, 'stop', $target],
                    'delete_snapshots' => [
                        'incus',
                        '--project',
                        $projectArgument,
                        'snapshot',
                        'delete',
                        $target,
                        (string) $snapshot,
                    ],
                    'delete_instances' => ['incus', '--project', $projectArgument, 'delete', $target],
                    'delete_networks' => ['incus', '--project', $projectArgument, 'network', 'delete', $target],
                    default => null,
                };
                if ($arguments !== null) {
                    $kind = match ($operation) {
                        'stop', 'delete_instances' => 'instances',
                        'delete_snapshots' => 'snapshots',
                        'delete_networks' => 'networks',
                        default => throw new \RuntimeException('The legacy Incus mutation operation is invalid.'),
                    };
                    /** @var array<string, mixed> $resource LegacyRetirement validates each target before mutation. */
                    $revalidator->assertCurrent($kind, $resource, $operation);
                    $result = Process::timeout(300)->run($arguments);
                    if ($result->failed()) {
                        throw new \RuntimeException('An exact legacy Incus mutation failed.');
                    }

                    return;
                }
                if (! in_array($operation, ['delete_source_paths', 'delete_manifests', 'delete_locks'], true)) {
                    throw new \RuntimeException('The legacy mutation operation is invalid.');
                }
                $root = $resource['safe_root'] ?? null;
                if (! is_string($root)) {
                    throw new \RuntimeException('The legacy file target has no safe root.');
                }
                $kind = match ($operation) {
                    'delete_source_paths' => 'source_paths',
                    'delete_manifests' => 'manifests',
                    'delete_locks' => 'locks',
                    default => throw new \RuntimeException('The legacy file mutation operation is invalid.'),
                };
                $deleter->delete($kind, $root, $identity);
            };

            return new LegacyRetirement(
                $observe,
                $mutate,
                fn (): \DateTimeImmutable => new \DateTimeImmutable('now'),
                $this->app->make(OperationLock::class),
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
            ),
        );

        $this->app->singleton(IncusHost::class, function (Application $app): IncusHost {
            $configuration = $app->make(Repository::class);
            $ownership = $configuration->get('e2e.incus.ownership');
            $operationId = $configuration->get('e2e.incus.operation_id');
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
                journal: is_string($operationId) ? $app->make(OperationJournal::class) : null,
                operationId: is_string($operationId) ? new OperationId($operationId) : null,
            );
        });
        $this->app->bind(GuestTransport::class, fn (Application $app): IncusHost => $app->make(IncusHost::class));
        $this->app->singleton(IncusNetworkLifecycle::class);

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
            $app->make(OperationJournal::class),
            $app->make(AtomicJsonStore::class),
            $app->make(GitRepository::class),
            $repositoryRoot,
        ));
    }

    public function boot(): void
    {
        /** @mago-expect lint:no-ini-set Exception arguments must stay out of infrastructure failure traces. */
        ini_set('zend.exception_ignore_args', '1');
    }
}
