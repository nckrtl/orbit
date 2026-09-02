<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologySnapshot;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologySnapshotRebuilder;
use App\E2E\TopologySnapshotRefresher;
use Throwable;

final class RebuildCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-snapshot:rebuild
        {--main-sha=}
        {--json}';
    #[\Override]
    protected $description = 'Tear this checkout\'s topology snapshot down and build it again from the base image';

    public function handle(TopologySnapshotRebuilder $rebuilder, TopologySnapshotRefresher $refresher): int
    {
        try {
            $sha = $this->option('main-sha');
            if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/D', $sha) !== 1) {
                throw new \InvalidArgumentException('The exact main SHA is required.');
            }
            // Teardown first: a cold build refuses while topology snapshot resources, a
            // promoted generation, or the corrupt marker still exist.
            $teardown = $rebuilder->teardown();
            $refresh = $refresher->request($sha, allowCold: true);
            $payload = [
                'state' => $refresh->state,
                'generation_id' => $refresh->generationId,
                'operation_id' => $refresh->operationId,
                'instances_deleted' => $teardown['instances_deleted'],
                'networks_deleted' => $teardown['networks_deleted'],
                'error' => $refresh->error,
            ];
            $this->outputJson($payload, $refresh->state.' '.($refresh->generationId ?? ''));

            return $refresh->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
