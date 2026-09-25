<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\FollowsLogs;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Logs\ProcessLogStreamTarget;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Responses\Processes\ProcessLogsResponse;

final class ProcessLogsCommand extends ProcessCommand
{
    use FollowsLogs;

    #[\Override]
    protected $signature = 'process:logs
        {process : Numeric process ID}
        {--lines=100 : Number of lines, from 1 to 1000}
        {--follow : Keep printing new lines until Ctrl-C}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Return a bounded process log tail, or follow new lines.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $processId = $this->positiveId('process', 'Process', 'process.id_invalid');
        $lines = filter_var(
            $this->option('lines'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );

        if ($processId === null) {
            return self::FAILURE;
        }

        if (! is_int($lines)) {
            return $this->renderGatewayFailure(
                'process.log_lines_invalid',
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
                new ProcessLogStreamTarget($processId),
                $lines,
                fn (int $read): string => $this->sendOrThrow($connector, new ProcessLogsRequest($processId, $read), ProcessLogsResponse::class)->logs,
            );
        }

        $response = $this->sendWithProgress(
            $connector,
            new ProcessLogsRequest($processId, $lines),
            ProcessLogsResponse::class,
            ['Read Process logs', 'Reading Process logs', 'Read Process logs'],
        );

        if (! $response instanceof ProcessLogsResponse) {
            return self::FAILURE;
        }

        $logs = $this->sanitizedLogs($response->logs);

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
