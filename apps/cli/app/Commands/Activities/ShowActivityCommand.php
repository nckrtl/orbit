<?php

declare(strict_types=1);

namespace App\Commands\Activities;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
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

        $activity = $this->sendWithProgress($connector, new ShowActivityRequest($activityId), ActivityResponse::class, ['Show activity', 'Loading activity', 'Loaded activity']);

        if (! $activity instanceof ActivityResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($activity->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Activity: {$activity->id}", [
            'Command' => $activity->command,
            'Status' => $activity->status,
            'Activity request ID' => $activity->activityRequestId,
            'Time' => $activity->occurredAt,
            'Duration' => $activity->durationMs === null ? null : "{$activity->durationMs} ms",
            'Exit code' => $activity->exitCode,
            'Error' => $activity->errorCode,
            'Request ID' => $activity->requestId,
        ]));

        return self::SUCCESS;
    }
}
