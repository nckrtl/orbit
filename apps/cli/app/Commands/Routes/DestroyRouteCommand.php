<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Responses\Routes\RemovedRouteResponse;
use Orbit\Sdk\Responses\Routes\RouteRemovalResidueResponse;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class DestroyRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:destroy {route : Numeric Route ID} {--yes : Confirm removal without prompting}
        {--offline : Remove the Route without changing a Node the Gateway cannot reach}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->routeId();
        if ($id === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress(
                $connector,
                new ShowRouteRequest($id),
                RouteResponse::class,
                ['Inspect Route', 'Inspecting Route', 'Inspected Route'],
            );

            if (! $existing instanceof RouteResponse || ! $this->confirmAction(
                "Confirm Route removal [{$existing->domain}]?",
                'Route removal cancelled.',
            )) {
                return self::FAILURE;
            }
        }

        $removed = $this->sendWithProgress(
            $connector,
            new DestroyRouteRequest($id, offline: $this->option('offline') === true ? true : null),
            RemovedRouteResponse::class,
            ['Remove Route', 'Removing Route', 'Removed Route'],
        );

        if (! $removed instanceof RemovedRouteResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($removed->toArray());

            return self::SUCCESS;
        }

        $this->renderRoute($removed->route);
        $this->renderRetained($removed->retainedOnNodes);

        return self::SUCCESS;
    }

    /** @param list<RouteRemovalResidueResponse> $retained */
    private function renderRetained(array $retained): void
    {
        if ($retained === []) {
            return;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->properties([[
            'title' => 'Left on Nodes the Gateway could not reach:',
            'items' => array_map(
                static fn (RouteRemovalResidueResponse $residue): array => [
                    'label' => "{$residue->node}: ".implode(', ', $residue->steps),
                    'fields' => [],
                ],
                $retained,
            ),
        ]]));
        $this->writeHumanMessage('Run orbit node:converge NODE once the Node answers to remove them.');
    }
}
