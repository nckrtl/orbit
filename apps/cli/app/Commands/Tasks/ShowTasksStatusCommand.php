<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;

final class ShowTasksStatusCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show whether the Gateway tasks extension is on.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $status = $this->sendWithProgress($connector, new ShowTasksStatusRequest, TasksStatusResponse::class, ['Show tasks status', 'Loading tasks status', 'Loaded tasks status']);

        if (! $status instanceof TasksStatusResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($status->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Extension: tasks', [
            'Status' => $status->enabled ? 'enabled' : 'disabled',
        ]));
        $this->writeHumanMessage("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }
}
