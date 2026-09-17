<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Orbit\Sdk\Responses\Apps\AppResponse;

final class ShowAppCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'app:show
        {app : Numeric app ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show an app.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $app = $this->sendWithProgress($connector, new ShowAppRequest($appId), AppResponse::class, ['Show App', 'Fetching App', 'Fetched App'], dismiss: true);

        if (! $app instanceof AppResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }

        $instances = $this->sendWithProgress($connector, new ListAppInstancesRequest, AppInstancesResponse::class, ['App instances', 'Fetching App instances', 'Fetched App instances'], dismiss: true);
        if (! $instances instanceof AppInstancesResponse) {
            return self::FAILURE;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("App: {$app->slug}", [
            'ID' => $app->id,
            'Name' => $app->name,
            'Repository' => $app->repositoryUrl,
            'Default branch' => $app->defaultBranch,
            'Web root' => $app->root,
        ]));

        $headers = ['ID', 'Name', 'Environment', 'Node', 'Domain', 'Status'];
        $rows = [];
        foreach ($instances->appInstances as $instance) {
            if ($instance->appId !== $app->id) {
                continue;
            }
            $rows[$instance->id] = [(string) $instance->id, $instance->name, $instance->environment, $instance->node->name ?? (string) $instance->nodeId, $instance->domain ?? '—', $instance->status];
        }

        if ($this->consoleMode()->mayPrompt && $rows !== []) {
            // The App's instances are the selector: Enter shows the highlighted App instance.
            try {
                $selected = $this->commandPrompts()->selectEntity('App instances', $headers, $rows);
            } catch (PromptAborted) {
                return self::SUCCESS;
            }

            return $this->call('instance:show', ['instance' => (string) $selected]);
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table($headers, array_values($rows), 'No App instances.'));

        return self::SUCCESS;
    }
}
