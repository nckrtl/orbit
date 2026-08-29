<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\E2E\IncusHost;
use App\E2E\StandbyManifestStore;
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

    public function handle(StandbyManifestStore $manifests, IncusHost $host): int
    {
        try {
            $generation = $manifests->promoted();
            $target = TopologyTarget::standby();
            $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
            if ($generation !== null) {
                $host->assertOwnedSnapshots(array_combine($instanceNames, array_values($generation->snapshots)));
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
                'stopped' => $stopped,
                'generation' => $generation?->toArray(),
            ];
            $this->line(
                $this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $generation->id ?? 'missing',
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
