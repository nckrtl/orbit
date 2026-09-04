<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Commands\GatewayCommand;
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

    protected function renderRoute(RouteResponse $route, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($route->toArray());

            return self::SUCCESS;
        }

        $this->info($message);
        $this->line('Scope: '.($route->clusterId === null ? "node {$route->nodeId}" : "cluster {$route->clusterId}"));
        $this->line('Target: '.($route->target->appInstanceId ?? '—'));
        $this->line("Status: {$route->status}");
        $this->line("Request ID: {$route->requestId}");

        return self::SUCCESS;
    }
}
