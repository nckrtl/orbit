<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Apps\ListProcessDefinitionsRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

final class ListProcessesCommand extends TargetedProcessCommand
{
    use RendersAppRuntimeDefinitions;

    #[\Override]
    protected $signature = 'process:list
        {--instance= : Positive Instance ID}
        {--node= : Node ID or registered name}
        {--project= : Numeric Project ID}
        {--app= : Numeric Project ID (compatibility)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List processes for one Instance or Node, or process definitions for one Project.';

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

            $response = $this->sendWithProgress(
                $connector,
                new ListProcessDefinitionsRequest($appId),
                AppRuntimeDefinitionsResponse::class,
                ['List Process definitions', 'Loading Process definitions', 'Loaded Process definitions'],
            );

            return $response instanceof AppRuntimeDefinitionsResponse
                ? $this->renderDefinitions($response)
                : self::FAILURE;
        }

        $target = $this->processTarget($connector);

        if ($target === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListProcessesRequest($target),
            ProcessesResponse::class,
            ['List Processes', 'Loading Processes', 'Loaded Processes'],
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
                $process->status,
                $process->failedStep,
                $process->restartPolicy,
                $process->keepAlive ? 'yes' : 'no',
            ];
        }

        if ($rows === []) {
            $this->writeHumanMessage('No matching records found.');
            $this->writeHumanMessage("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Lifecycle', 'Failed step', 'Restart', 'Keep-alive'],
            $rows,
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
