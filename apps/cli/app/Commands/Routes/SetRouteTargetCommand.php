<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class SetRouteTargetCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:target:set
        {route : Numeric Route ID}
        {target : Numeric AppInstance target ID}
        {--targets=* : Additional ordered AppInstance IDs in the complete target set}
        {--reassign=* : Detached AppInstance ID:destination Route ID}
        {--remove=* : Detached AppInstance ID authorized for removal}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Set the configured Route target or a complete production target set.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $routeId = $this->routeId();
        if ($routeId === null) {
            return self::FAILURE;
        }

        $targetId = $this->positiveId('target', 'AppInstance', 'route.target_id_invalid');
        if ($targetId === null) {
            return self::FAILURE;
        }

        $additional = [];
        foreach ((array) $this->option('targets') as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                $this->renderGatewayFailure('route.target_id_invalid', 'AppInstance ID must be a positive integer.');

                return self::FAILURE;
            }
            $additional[] = $id;
        }

        $dispositions = [];
        foreach ((array) $this->option('reassign') as $value) {
            if (! is_string($value) || preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/', $value, $matches) !== 1) {
                $this->renderGatewayFailure('route.target_disposition_invalid', 'Reassignment must be app-instance-id:route-id.');

                return self::FAILURE;
            }
            $dispositions[] = [
                'app_instance_id' => (int) $matches[1],
                'route_id' => (int) $matches[2],
            ];
        }
        foreach ((array) $this->option('remove') as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                $this->renderGatewayFailure('route.target_id_invalid', 'AppInstance ID must be a positive integer.');

                return self::FAILURE;
            }
            $dispositions[] = [
                'app_instance_id' => $id,
                'remove' => true,
            ];
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $request = $additional === [] && $dispositions === []
            ? new SetRouteTargetRequest($routeId, $targetId)
            : new SetRouteTargetRequest($routeId, targetIds: [$targetId, ...$additional], dispositions: $dispositions);

        $route = $this->send($connector, $request, RouteResponse::class);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "Route [{$route->domain}] target updated.")
            : self::FAILURE;
    }
}
