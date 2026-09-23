<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\EnableTasksRequest;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;

final class EnableTasksCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:enable
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Turn the Gateway tasks extension on.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $status = $this->sendWithProgress($connector, new EnableTasksRequest, TasksStatusResponse::class, ['Enable tasks', 'Enabling tasks', 'Enabled tasks']);

        if (! $status instanceof TasksStatusResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($status->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage('The tasks extension is '.($status->enabled ? 'enabled' : 'disabled').'.');
        $this->writeHumanMessage("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }
}
