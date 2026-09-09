<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\Routes\RouteHostnameChangeDirection;
use App\Domain\Routes\RouteHostnameChangeStep;
use App\Domain\Routes\RouteHostnameProjector;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$arguments = array_slice($argv, 2);
$statePath = '/home/orbit/.orbit/orb188-route.json';
$failureTrigger = 'orb188_route_cutover_failure';

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-188 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @param array<string, mixed> $value */
function writeJson(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

/** @return array<string, mixed> */
function readState(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);

    if (
        ! is_array($value)
        || ! is_int($value['route_id'] ?? null)
        || ! is_int($value['occupied_route_id'] ?? null)
    ) {
        throw new RuntimeException('The ORB-188 fixture state is invalid.');
    }

    return $value;
}

/** @return array<string, mixed> */
function routeEvidence(Route $route): array
{
    return [
        'id' => $route->id,
        'hostname' => $route->hostname,
        'status' => $route->status->value,
        'failed_step' => $route->failed_step,
        'error_code' => $route->error_code,
        'hostname_change_previous' => $route->hostname_change_previous,
        'hostname_change_target' => $route->hostname_change_target,
        'hostname_change_direction' => $route->hostname_change_direction?->value,
        'hostname_change_step' => $route->hostname_change_step?->value,
    ];
}

/** @return array<string, mixed> */
function routeState(array $fixture): array
{
    $route = Route::query()->with('targets.appInstance.node')->findOrFail($fixture['route_id']);
    $occupiedRoute = Route::query()->findOrFail($fixture['occupied_route_id']);
    $target = $route->targets->sole()->appInstance;

    return [
        'route' => routeEvidence($route),
        'occupied_route' => routeEvidence($occupiedRoute),
        'instance' => [
            'id' => $target->id,
            'checkout_path' => $target->checkout_path,
            'source_is_laravel' => $target->source_is_laravel,
            'node_ip' => $target->node->wireguard_ip,
        ],
        'original_hostname' => $fixture['original_hostname'],
    ];
}

switch ($command) {
    case 'setup':
        if ($arguments !== []) {
            exit(64);
        }

        DB::statement("DROP TRIGGER IF EXISTS {$failureTrigger}");
        $instance = AppInstance::query()
            ->with(['node', 'routes.targets'])
            ->where('name', 'e2e-dev')
            ->sole();
        $route = $instance->routes->sole();

        if (
            $instance->status !== AppInstanceState::Active
            || $route->status !== RouteStatus::Active
            || $route->targets->count() !== 1
            || $route->targets->sole()->app_instance_id !== $instance->id
        ) {
            throw new RuntimeException('The sample development Route is not active and exclusive.');
        }

        $profile = app(DevelopmentAppInstanceConfigurator::class)->inspect($instance);

        if (! $profile->laravel || $profile->phpVersion === null) {
            throw new RuntimeException('The sample development source is not a supported Laravel source.');
        }

        $instance->update([
            'selected_php_version' => $profile->phpVersion,
            'source_is_laravel' => true,
            'provisioning_step' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ]);
        app(DevelopmentAppInstanceConfigurator::class)->configureLaravelUrl(
            $instance,
            "https://{$route->hostname}",
        );
        $occupiedRoute = Route::query()->firstOrCreate(
            ['hostname' => 'orb188-occupied.orbit'],
            [
                'app_id' => $route->app_id,
                'node_id' => $route->node_id,
                'cluster_id' => $route->cluster_id,
                'provenance' => 'explicit',
                'publication' => 'private',
                'status' => 'pending',
            ],
        );
        $fixture = [
            'route_id' => $route->id,
            'occupied_route_id' => $occupiedRoute->id,
            'instance_id' => $instance->id,
            'original_hostname' => $route->hostname,
        ];
        file_put_contents(
            $statePath,
            json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL,
        );
        chmod($statePath, 0600);
        writeJson(routeState($fixture));
        break;

    case 'state':
        if ($arguments !== []) {
            exit(64);
        }

        writeJson(routeState(readState($statePath)));
        break;

    case 'cutover-failure':
        if (count($arguments) !== 1 || ! in_array($arguments[0], ['on', 'off'], true)) {
            exit(64);
        }

        DB::statement("DROP TRIGGER IF EXISTS {$failureTrigger}");

        if ($arguments[0] === 'on') {
            $fixture = readState($statePath);
            $candidate = RouteHostname::validate('orb188-rollback.orbit');
            $routeId = $fixture['route_id'];
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$failureTrigger}
                BEFORE UPDATE OF hostname ON routes
                WHEN NEW.id = {$routeId} AND NEW.hostname = '{$candidate}'
                BEGIN
                    SELECT RAISE(ABORT, 'ORB-188 injected database cutover failure.');
                END
                SQL);
        }

        writeJson(['enabled' => $arguments[0] === 'on']);
        break;

    case 'dns-interruption':
        if (count($arguments) !== 1 || ! in_array($arguments[0], ['start', 'rebuild'], true)) {
            exit(64);
        }

        $fixture = readState($statePath);
        $route = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->findOrFail($fixture['route_id']);
        $instance = $route->targets->sole()->appInstance;

        if ($arguments[0] === 'start') {
            $candidateHostname = RouteHostname::validate('orb188-interrupted.orbit');
            $route->update([
                'hostname_change_previous' => $route->hostname,
                'hostname_change_target' => $candidateHostname,
                'hostname_change_direction' => RouteHostnameChangeDirection::Forward,
                'hostname_change_step' => RouteHostnameChangeStep::Reserved,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $route->refresh();
            $candidate = clone $route;
            $candidate->hostname = $candidateHostname;
            $projection = app(RouteHostnameProjector::class);
            $projection->prepareWorkloadCertificate($instance, $route, $candidate);
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::WorkloadCertificate]);
            $projection->prepareWorkloadCaddy($instance, $route, $candidate);
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::WorkloadCaddy]);
            $projection->prepareRouterCertificate($instance, $route, $candidate);
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::RouterCertificate]);
            $projection->prepareFirewallPolicy($instance, $candidate);
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::FirewallPolicy]);
            $projection->verifyWorkload($instance, $candidate);
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::WorkloadVerified]);
            $projection->prepareRouterCaddy($instance, $route, $candidate);
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::RouterCaddy]);
            app(DevelopmentAppInstanceConfigurator::class)->configureLaravelUrl(
                $instance,
                "https://{$candidateHostname}",
            );
            $route->update(['hostname_change_step' => RouteHostnameChangeStep::LaravelUrl]);
            $projection->publishDns($route, $candidate);
        } else {
            app(RemoteAppDevCaddyManager::class)->converge($instance->node);
            app(DnsmasqPrivateDnsManager::class)->converge();
        }

        writeJson(routeState($fixture));
        break;

    case 'cutover-drift':
        if ($arguments !== []) {
            exit(64);
        }

        $fixture = readState($statePath);
        $route = Route::query()->with('targets.appInstance')->findOrFail($fixture['route_id']);
        $instance = $route->targets->sole()->appInstance;
        $route->update([
            'hostname_change_previous' => $fixture['original_hostname'],
            'hostname_change_target' => $route->hostname,
            'hostname_change_direction' => RouteHostnameChangeDirection::Forward,
            'hostname_change_step' => RouteHostnameChangeStep::DatabaseCutover,
            'failed_step' => 'cleanup',
            'error_code' => 'route.hostname_change_failed',
        ]);
        app(DevelopmentAppInstanceConfigurator::class)->configureLaravelUrl(
            $instance,
            "https://{$fixture['original_hostname']}",
        );
        writeJson(routeState($fixture));
        break;

    default:
        exit(64);
}
