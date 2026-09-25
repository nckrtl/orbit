<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsLogs;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Logs\LogRedaction;
use Orbit\Sdk\Requests\AppInstances\InstanceLogsRequest;
use Orbit\Sdk\Requests\Logs\InstanceLogStreamTarget;
use Orbit\Sdk\Responses\AppInstances\InstanceLogsResponse;

final class InstanceLogsCommand extends GatewayCommand
{
    use FollowsLogs;

    #[\Override]
    protected $signature = 'instance:logs
        {instance : Numeric instance ID}
        {--lines=100 : Number of lines, from 1 to 1000}
        {--follow : Keep printing new lines until Ctrl-C}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Return the application log tail of an instance, or follow new lines.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        $lines = filter_var(
            $this->option('lines'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );

        if ($instanceId === null) {
            return self::FAILURE;
        }

        if (! is_int($lines)) {
            return $this->renderGatewayFailure(
                'instance.log_lines_invalid',
                'Log lines must be between 1 and 1000.',
            );
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);

        if ($this->option('follow') === true) {
            return $this->followLogs(
                $connector,
                $profile,
                new InstanceLogStreamTarget($instanceId),
                $lines,
                fn (): string => $this->sendOrThrow($connector, new InstanceLogsRequest($instanceId, $lines), InstanceLogsResponse::class)->logs,
            );
        }

        $response = $this->sendWithProgress(
            $connector,
            new InstanceLogsRequest($instanceId, $lines),
            InstanceLogsResponse::class,
            ['Read Instance logs', 'Reading Instance logs', 'Read Instance logs'],
        );

        if (! $response instanceof InstanceLogsResponse) {
            return self::FAILURE;
        }

        $logs = LogRedaction::redact($response->logs);

        if ($this->option('json') === true) {
            $this->writeJson([
                'id' => $response->id,
                'name' => $response->name,
                'lines' => $response->lines,
                'logs' => $logs,
                'request_id' => $response->requestId,
            ]);

            return self::SUCCESS;
        }

        $logs = rtrim($logs, characters: "\r\n");

        if ($logs !== '') {
            $this->line($logs);
        }

        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
