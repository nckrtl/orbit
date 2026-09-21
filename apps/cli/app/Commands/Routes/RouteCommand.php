<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Routes\RouteResponse;

abstract class RouteCommand extends GatewayCommand
{
    protected function routeId(): ?int
    {
        return $this->positiveId('route', 'Route', 'route.id_invalid');
    }

    protected function optionId(string $option, string $label): ?int
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure('route.id_invalid', "{$label} ID must be a positive integer.");

            return 0;
        }

        return $id;
    }

    /**
     * Returns the given `--publication` value when it is a non-empty string, or null after rendering
     * `route.publication_invalid`. The Gateway owns the accepted publication values; this only
     * refuses a missing or empty value.
     */
    protected function publication(mixed $publication): ?string
    {
        if (is_string($publication) && $publication !== '') {
            return $publication;
        }

        $this->renderGatewayFailure('route.publication_invalid', 'Publication intent must be a non-empty value.');

        return null;
    }

    protected function renderRoute(RouteResponse $route): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($route->toArray());

            return self::SUCCESS;
        }

        $details = [
            'ID' => $route->id,
            'Kind' => $route->kind,
            'Project' => $route->appId,
            'Provenance' => $route->provenance,
            'Scope' => $route->clusterId === null ? "Node {$route->nodeId}" : "Cluster {$route->clusterId}",
        ];

        if ($route->kind === 'custom_proxy') {
            $details['Upstream'] = $route->upstream;
            $details['Process'] = $route->processId;
        } else {
            $details['Targets'] = $this->targetList($route);
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Route: {$route->domain}", [
            ...$details,
            'Publication' => $route->publication,
            'Status' => $route->status,
            'Generation basis Node' => $route->generationBasisNodeId,
            'Replaces Route' => $route->replacesRouteId,
            'Replaced by Route' => $route->replacedByRouteId,
            'Replacement step' => $route->replacementStep,
            'Target set step' => $route->targetSetStep,
            'Failed step' => $route->failedStep,
            'Error' => $route->errorCode,
            'Request ID' => $route->requestId,
        ]));

        return self::SUCCESS;
    }

    protected function targetList(RouteResponse $route): string
    {
        if ($route->kind === 'custom_proxy') {
            $upstream = is_string($route->upstream) && $route->upstream !== '' ? $route->upstream : '—';

            return $route->processId === null ? $upstream : "{$upstream} (process {$route->processId})";
        }

        $targets = $route->targets !== [] ? $route->targets : array_filter([$route->target]);

        return $targets === [] ? '—' : implode(', ', array_map(
            static fn ($target): int => $target->appInstanceId,
            $targets,
        ));
    }
}
