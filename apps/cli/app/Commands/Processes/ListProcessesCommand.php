<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\ListProcessDefinitionsRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

final class ListProcessesCommand extends TargetedProcessCommand
{
    use RendersAppRuntimeDefinitions;

    #[\Override]
    protected $signature = 'process:list
        {--instance= : Positive AppInstance ID}
        {--node= : Node ID or registered name}
        {--app= : Numeric App ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List processes for one AppInstance or Node, or process definitions for one App.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $selector = $this->exclusiveProcessTarget();

        if ($selector === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($selector === 'app') {
            $appId = $this->appIdOption();

            if ($appId === false || $appId === null) {
                return self::FAILURE;
            }

            $response = $this->send(
                $connector,
                new ListProcessDefinitionsRequest($appId),
                AppRuntimeDefinitionsResponse::class,
            );

            return $response instanceof AppRuntimeDefinitionsResponse
                ? $this->renderDefinitions($response)
                : self::FAILURE;
        }

        $target = $this->processTarget($connector);

        if ($target === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new ListProcessesRequest($target),
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
                $process->keepAlive ? 'yes' : 'no',
            ];
        }

        $this->table(['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Restart', 'Keep-alive'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
