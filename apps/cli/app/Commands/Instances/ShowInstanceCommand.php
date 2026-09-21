<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Requests\Processes\AppInstanceProcessTarget;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

final class ShowInstanceCommand extends GatewayCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:show
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show an instance.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->sendWithProgress($connector, new ShowAppInstanceRequest($instanceId), AppInstanceResponse::class, ['Show Instance', 'Fetching Instance', 'Fetched Instance'], dismiss: true);

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $processes = $this->sendWithProgress($connector, new ListProcessesRequest(new AppInstanceProcessTarget($instance->id)), ProcessesResponse::class, ['Processes', 'Fetching Processes', 'Fetched Processes'], dismiss: true);
        if (! $processes instanceof ProcessesResponse) {
            return self::FAILURE;
        }

        $this->writeInstanceDetails($instance);
        // Deploy steps belong to production; a development instance shows none rather than an empty table.
        if ($instance->productionUser !== null || $instance->deploySteps !== []) {
            $this->writeDeploySteps($instance->deploySteps);
        }

        $headers = ['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Status'];
        $rows = [];
        foreach ($processes->processes as $process) {
            $rows[$process->id] = [(string) $process->id, $process->name, $process->runtime, $process->desiredState, $process->runtimeStatus, $process->status];
        }

        if ($this->consoleMode()->mayPrompt && $rows !== []) {
            // The Processes are the selector: Enter follows the highlighted Process's logs.
            try {
                $selected = $this->commandPrompts()->selectEntity('Processes', $headers, $rows);
            } catch (PromptAborted) {
                return self::SUCCESS;
            }

            return $this->call('process:logs', ['process' => (string) $selected]);
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table($headers, array_values($rows), 'No Processes.'));

        return self::SUCCESS;
    }
}
