<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\E2E\IncusHost;
use App\E2E\StaleStandbyManifest;
use App\E2E\StandbyAvailability;
use App\E2E\StandbyManifestStore;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use Illuminate\Console\Command;
use Throwable;

final class StatusCommand extends Command
{
    #[\Override]
    protected $signature = 'standby:status {--json}';
    #[\Override]
    protected $description = 'Show the promoted standby generation';

    public function handle(
        StandbyManifestStore $manifests,
        IncusHost $host,
        StandbyAvailability $availability,
        StandbyIdentity $identity,
    ): int {
        try {
            $generation = $manifests->promoted();
            $target = TopologyTarget::standby($identity);
            $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
            if ($generation !== null) {
                $availability->assertAvailable($generation);
            }
            $instances = $host->instances($instanceNames);
            $stopped =
                count($instances) === count($instanceNames)
                && array_all($instances, static fn ($instance): bool => $instance->isStopped());
            if ($generation !== null && ! $stopped) {
                throw new \RuntimeException('The promoted standby topology is not stopped.');
            }
            $payload = [
                'state' => $generation === null ? 'missing' : 'promoted',
                'standby_namespace' => $identity->namespace,
                'stopped' => $stopped,
                'generation' => $generation?->toArray(),
            ];
            $this->line(
                $this->option('json')
                    ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : $generation->id ?? 'missing',
            );

            return self::SUCCESS;
        } catch (StaleStandbyManifest $exception) {
            // The manifest is behind the host, not corrupt: report the state a
            // named command recovers from instead of a bare failure.
            $this->line(
                $this->option('json')
                    ? json_encode([
                        'state' => 'stale',
                        'standby_namespace' => $identity->namespace,
                        'stopped' => false,
                        'generation' => null,
                        'error' => $exception->getMessage(),
                        'recovery' => StaleStandbyManifest::RECOVERY_COMMAND,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $exception->getMessage(),
            );

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
