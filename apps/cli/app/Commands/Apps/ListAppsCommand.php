<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Responses\Apps\AppsResponse;

final class ListAppsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List projects.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new ListAppsRequest, AppsResponse::class, ['List Projects', 'Fetching Projects', 'Fetched Projects'], dismiss: true);

        if (! $response instanceof AppsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Slug', 'Type', 'Repository', 'Default branch', 'Web root'];
        $rows = [];
        foreach ($response->apps as $app) {
            $rows[$app->id] = [
                (string) $app->id,
                $app->name,
                $app->slug,
                $app->type,
                $app->repositoryUrl,
                $app->defaultBranch ?? '—',
                $app->root ?? '—',
            ];
        }

        if ($this->consoleMode()->mayPrompt && $rows !== []) {
            // In a terminal the list is the selector: Enter shows the highlighted App.
            try {
                $selected = $this->commandPrompts()->selectEntity('Projects', $headers, $rows);
            } catch (PromptAborted) {
                return self::SUCCESS;
            }

            return $this->call('project:show', ['project' => (string) $selected]);
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table($headers, array_values($rows), 'No Projects found.'));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
