<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologySnapshot;

use App\E2E\IncusHost;
use App\E2E\StaleTopologySnapshotManifest;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use Illuminate\Console\Command;
use Throwable;

final class StatusCommand extends Command
{
    #[\Override]
    protected $signature = 'topology-snapshot:status {--json}';
    #[\Override]
    protected $description = 'Show the promoted topology snapshot generation';

    public function handle(
        TopologySnapshotManifestStore $manifests,
        IncusHost $host,
        TopologySnapshotAvailability $availability,
        TopologySnapshotIdentity $identity,
    ): int {
        try {
            $generation = $manifests->promoted();
            $target = TopologyTarget::topologySnapshot($identity);
            $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
            if ($generation !== null) {
                $availability->assertAvailable($generation);
            }
            $instances = $host->instances($instanceNames);
            $stopped =
                count($instances) === count($instanceNames)
                && array_all($instances, static fn ($instance): bool => $instance->isStopped());
            if ($generation !== null && ! $stopped) {
                throw new \RuntimeException('The promoted topology snapshot is not stopped.');
            }
            $payload = [
                'state' => $generation === null ? 'missing' : 'promoted',
                'stopped' => $stopped,
                'generation' => $generation?->toArray(),
            ];
            $this->line(
                $this->option('json')
                    ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : $generation->id ?? 'missing',
            );

            return self::SUCCESS;
        } catch (StaleTopologySnapshotManifest $exception) {
            // The manifest is behind the host, not corrupt: report the state a
            // named command recovers from instead of a bare failure.
            $this->line(
                $this->option('json')
                    ? json_encode([
                        'state' => 'stale',
                        'stopped' => false,
                        'generation' => null,
                        'error' => $exception->getMessage(),
                        'recovery' => StaleTopologySnapshotManifest::RECOVERY_COMMAND,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $exception->getMessage(),
            );

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
