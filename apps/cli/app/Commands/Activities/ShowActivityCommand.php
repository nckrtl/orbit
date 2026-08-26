<?php

declare(strict_types=1);

namespace App\Commands\Activities;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Activities\ShowActivityRequest;
use Orbit\Sdk\Responses\Activities\ActivityResponse;

final class ShowActivityCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'activity:show
        {activity : Numeric activity ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one gateway command activity attempt.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $activityId = $this->positiveId('activity', 'Activity', 'activity.id_invalid');

        if ($activityId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $activity = $this->send($connector, new ShowActivityRequest($activityId), ActivityResponse::class);

        if (! $activity instanceof ActivityResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($activity->toArray());

            return self::SUCCESS;
        }

        $this->info("Activity #{$activity->id}: {$activity->command} [{$activity->status}]");
        $this->line("Activity request ID: {$activity->activityRequestId}");
        $this->line("Time: {$activity->occurredAt}");
        $this->line('Duration: '.($activity->durationMs === null ? '—' : "{$activity->durationMs} ms"));
        $this->line('Exit code: '.($activity->exitCode === null ? '—' : (string) $activity->exitCode));
        $this->line('Error: '.($activity->errorCode ?? '—'));
        $this->line("Request ID: {$activity->requestId}");

        return self::SUCCESS;
    }
}
