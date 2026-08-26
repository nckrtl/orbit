<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

final class ListProcessesCommand extends ProcessCommand
{
    #[\Override]
    protected $signature = 'process:list
        {--instance= : Numeric instance ID}
        {--workspace= : Numeric workspace ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List processes for one instance or workspace.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $target = $this->target();

        if ($target === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new ListProcessesRequest($target['type'], $target['id']),
            ProcessesResponse::class,
        );

        if (! $response instanceof ProcessesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'processes' => $this->sanitizedProcessCollection($response->toArray()['processes']),
                'request_id' => $response->requestId,
            ]);

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->processes as $process) {
            $rows[] = [
                $process->id,
                $process->name,
                $process->runtime,
                $process->desiredState,
                $process->runtimeStatus,
                $process->restartPolicy,
            ];
        }

        $this->table(['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Restart'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
