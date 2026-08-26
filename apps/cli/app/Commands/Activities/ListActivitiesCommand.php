<?php

declare(strict_types=1);

namespace App\Commands\Activities;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Activities\ListActivitiesRequest;
use Orbit\Sdk\Responses\Activities\ActivitiesResponse;

final class ListActivitiesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'activity:list
        {--limit=25 : Maximum activity rows, from 1 through 200}
        {--request-id= : Exact API request UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List recent gateway command activity.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 200]],
        );

        if (! is_int($limit)) {
            return $this->renderGatewayFailure(
                'activity.limit_invalid',
                'Limit must be between 1 and 200.',
            );
        }

        $requestId = $this->option('request-id');

        if ($requestId !== null && (! is_string($requestId) || ! Str::isUuid($requestId))) {
            return $this->renderGatewayFailure(
                'activity.request_id_invalid',
                'Request ID must be a UUID.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new ListActivitiesRequest($limit, is_string($requestId) ? $requestId : null),
            ActivitiesResponse::class,
        );

        if (! $response instanceof ActivitiesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->activities as $activity) {
            $rows[] = [
                $activity->id,
                $activity->occurredAt,
                $activity->command,
                $activity->status,
                $activity->callerNodeId === null ? '—' : (string) $activity->callerNodeId,
                $activity->targetNodeId === null ? '—' : (string) $activity->targetNodeId,
                $activity->errorCode ?? '—',
            ];
        }

        $this->table(['ID', 'Time', 'Command', 'Status', 'Caller', 'Target', 'Error'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
