<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivitiesController;
use App\Http\Controllers\Api\AppInstanceClonesController;
use App\Http\Controllers\Api\AppInstanceDeploymentsController;
use App\Http\Controllers\Api\AppInstanceDeployStepsController;
use App\Http\Controllers\Api\AppInstanceEnvironmentImportsController;
use App\Http\Controllers\Api\AppInstanceEnvironmentSynchronizationsController;
use App\Http\Controllers\Api\AppInstanceEnvironmentValuesController;
use App\Http\Controllers\Api\AppInstanceReleasesController;
use App\Http\Controllers\Api\AppInstanceRollbacksController;
use App\Http\Controllers\Api\AppInstancesController;
use App\Http\Controllers\Api\AppInstanceTransfersController;
use App\Http\Controllers\Api\AppRuntimeDefinitionsController;
use App\Http\Controllers\Api\AppsController;
use App\Http\Controllers\Api\ClustersController;
use App\Http\Controllers\Api\DatabaseConnectionAttachmentsController;
use App\Http\Controllers\Api\DatabaseConnectionsController;
use App\Http\Controllers\Api\DatabaseUsersController;
use App\Http\Controllers\Api\DoctorRunsController;
use App\Http\Controllers\Api\FirewallRulesController;
use App\Http\Controllers\Api\GatewayStatusesController;
use App\Http\Controllers\Api\GrafanaAccessAuthorizationController;
use App\Http\Controllers\Api\HerdrSessionsController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NodeAccessController;
use App\Http\Controllers\Api\NodeRolesController;
use App\Http\Controllers\Api\NodesController;
use App\Http\Controllers\Api\ProcessesController;
use App\Http\Controllers\Api\RealtimeConfigController;
use App\Http\Controllers\Api\RootCaCertificatesController;
use App\Http\Controllers\Api\RoutesController;
use App\Http\Controllers\Api\RuntimeActivationsController;
use App\Http\Controllers\Api\ScheduleCompletionsController;
use App\Http\Controllers\Api\SchedulesController;
use App\Http\Controllers\Api\ToolManagersController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('gateway/status', [GatewayStatusesController::class, 'show'])
        ->name('gateway:status');
    Route::get('ca/root', [RootCaCertificatesController::class, 'show'])
        ->name('gateway:trust');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
    ])->match(['get', 'post'], 'broadcasting/auth', [BroadcastController::class, 'authenticate'])
        ->name('realtime:auth');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
    ])->get('realtime', [RealtimeConfigController::class, 'show'])
        ->name('realtime:show');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->get('metrics/grafana/authorize', [GrafanaAccessAuthorizationController::class, 'show'])
        ->withoutMiddleware(RecordCommandActivity::class)
        ->name('metrics:grafana:authorize');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->post('schedules/{schedule}/complete', [ScheduleCompletionsController::class, 'store'])
        ->whereUuid('schedule')
        ->withoutMiddleware(RecordCommandActivity::class)
        ->name('schedule:complete');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->get('runtime-activations/app-instance/{instance}', [RuntimeActivationsController::class, 'show'])
        ->whereNumber('instance')
        ->withoutMiddleware(RecordCommandActivity::class)
        ->name('runtime-activation:app-instance');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->group(function (): void {
        Route::get('nodes', [NodesController::class, 'index'])
            ->name('node:list');
        Route::get('clusters', [ClustersController::class, 'index'])->name('cluster:list');
        Route::post('clusters', [ClustersController::class, 'store'])->name('cluster:create');
        Route::get('clusters/{cluster}', [ClustersController::class, 'show'])
            ->whereNumber('cluster')
            ->name('cluster:show');
        Route::patch('clusters/{cluster}', [ClustersController::class, 'update'])
            ->whereNumber('cluster')
            ->name('cluster:update');
        Route::delete('clusters/{cluster}', [ClustersController::class, 'destroy'])
            ->whereNumber('cluster')
            ->name('cluster:destroy');
        Route::put('clusters/{cluster}/nodes/{node}', [ClustersController::class, 'attach'])
            ->whereNumber('cluster')
            ->whereNumber('node')
            ->name('cluster:node:add');
        Route::delete('clusters/{cluster}/nodes/{node}', [ClustersController::class, 'detach'])
            ->whereNumber('cluster')
            ->whereNumber('node')
            ->name('cluster:node:remove');
        Route::put('clusters/{cluster}/router/{node}', [ClustersController::class, 'setRouter'])
            ->whereNumber('cluster')
            ->whereNumber('node')
            ->name('cluster:router:set');
        Route::delete('clusters/{cluster}/router', [ClustersController::class, 'clearRouter'])
            ->whereNumber('cluster')
            ->name('cluster:router:unset');
        Route::post('doctor', [DoctorRunsController::class, 'store'])
            ->name('doctor');
        Route::get('nodes/{node}', [NodesController::class, 'show'])
            ->name('node:show');
        Route::get('nodes/{node}/roles', [NodeRolesController::class, 'index'])
            ->whereNumber('node')
            ->name('node:role:list');
        Route::post('nodes/{node}/roles', [NodeRolesController::class, 'store'])
            ->whereNumber('node')
            ->name('node:role:add');
        Route::delete('nodes/{node}/roles/{role}', [NodeRolesController::class, 'destroy'])
            ->whereNumber('node')
            ->name('node:role:remove');
        Route::get('nodes/{node}/firewall-rules', [FirewallRulesController::class, 'index'])
            ->name('firewall:list');
        Route::get('activities', [ActivitiesController::class, 'index'])
            ->name('activity:list');
        Route::get('activities/{activity}', [ActivitiesController::class, 'show'])
            ->name('activity:show');
        Route::post('nodes', [NodesController::class, 'store'])
            ->name('node:add');
        Route::patch('nodes/{node}/settings', [NodesController::class, 'settings'])
            ->whereNumber('node')
            ->name('node:settings');
        Route::delete('nodes/{node}', [NodesController::class, 'destroy'])
            ->name('node:remove');
        Route::put(
            'nodes/{servingNode}/access/{consumerNode}',
            [NodeAccessController::class, 'store'],
        )->name('node:access:add');
        Route::delete(
            'nodes/{servingNode}/access/{consumerNode}',
            [NodeAccessController::class, 'destroy'],
        )->name('node:access:remove');
        Route::post('nodes/{node}/firewall-rules/allow', [FirewallRulesController::class, 'store'])
            ->defaults('firewall_action', 'allow')
            ->name('firewall:allow');
        Route::post('nodes/{node}/firewall-rules/deny', [FirewallRulesController::class, 'store'])
            ->defaults('firewall_action', 'deny')
            ->name('firewall:deny');
        Route::delete(
            'nodes/{node}/firewall-rules/{firewallRule:name}',
            [FirewallRulesController::class, 'destroy'],
        )
            ->scopeBindings()
            ->name('firewall:remove');
        Route::get('apps', [AppsController::class, 'index'])->name('app:list');
        Route::get('apps/{app}', [AppsController::class, 'show'])->name('app:show');
        Route::post('apps', [AppsController::class, 'store'])->name('app:create');
        Route::patch('apps/{app}', [AppsController::class, 'update'])->name('app:update');
        Route::delete('apps/{app}', [AppsController::class, 'destroy'])->name('app:destroy');
        Route::prefix('apps/{app}/process-definitions')->scopeBindings()->group(function (): void {
            Route::get('/', [AppRuntimeDefinitionsController::class, 'processIndex'])
                ->name('process:list');
            Route::post('/', [AppRuntimeDefinitionsController::class, 'processStore'])
                ->name('process:create');
            Route::get('{processDefinition}', [AppRuntimeDefinitionsController::class, 'processShow'])
                ->name('process:show');
            Route::put('{processDefinition}', [AppRuntimeDefinitionsController::class, 'processUpdate'])
                ->name('process:update');
            Route::delete('{processDefinition}', [AppRuntimeDefinitionsController::class, 'processDestroy'])
                ->name('process:destroy');
        });
        Route::prefix('apps/{app}/schedule-definitions')->scopeBindings()->group(function (): void {
            Route::get('/', [AppRuntimeDefinitionsController::class, 'scheduleIndex'])
                ->name('schedule:list');
            Route::post('/', [AppRuntimeDefinitionsController::class, 'scheduleStore'])
                ->name('schedule:create');
            Route::get('{scheduleDefinition}', [AppRuntimeDefinitionsController::class, 'scheduleShow'])
                ->name('schedule:show');
            Route::put('{scheduleDefinition}', [AppRuntimeDefinitionsController::class, 'scheduleUpdate'])
                ->name('schedule:update');
            Route::delete('{scheduleDefinition}', [AppRuntimeDefinitionsController::class, 'scheduleDestroy'])
                ->name('schedule:destroy');
        });
        Route::get('instances', [AppInstancesController::class, 'index'])->name('instance:list');
        Route::get('instances/{instance}', [AppInstancesController::class, 'show'])->name('instance:show');
        Route::patch('instances/{instance}', [AppInstancesController::class, 'update'])->name('instance:update');
        Route::post('instances', [AppInstancesController::class, 'store'])->name('instance:create');
        Route::post('instances/register', [AppInstancesController::class, 'register'])->name('instance:register');
        Route::post('instances/{candidate}/clone', [AppInstanceClonesController::class, 'store'])
            ->whereNumber('candidate')
            ->name('instance:clone');
        Route::post('instances/{instance}/transfer', [AppInstanceTransfersController::class, 'store'])
            ->whereNumber('instance')
            ->name('instance:transfer');
        Route::delete('instances/{instance}', [AppInstancesController::class, 'destroy'])
            ->name('instance:destroy');
        Route::get(
            'instances/{instance}/deploy-steps',
            [AppInstanceDeployStepsController::class, 'index'],
        )->name('instance:deploy-step:list');
        Route::post(
            'instances/{instance}/deploy-steps',
            [AppInstanceDeployStepsController::class, 'store'],
        )->name('instance:deploy-step:create');
        Route::patch(
            'instances/{instance}/deploy-steps/{step}',
            [AppInstanceDeployStepsController::class, 'update'],
        )->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')->name('instance:deploy-step:update');
        Route::delete(
            'instances/{instance}/deploy-steps/{step}',
            [AppInstanceDeployStepsController::class, 'destroy'],
        )->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')->name('instance:deploy-step:destroy');
        Route::post(
            'instances/{instance}/deploy',
            [AppInstanceDeploymentsController::class, 'store'],
        )->name('instance:deploy');
        Route::post(
            'instances/{instance}/rollback',
            [AppInstanceRollbacksController::class, 'store'],
        )->name('instance:rollback');
        Route::get(
            'instances/{instance}/releases',
            [AppInstanceReleasesController::class, 'index'],
        )->name('instance:release:list');
        Route::post(
            'instances/{instance}/environment/import',
            [AppInstanceEnvironmentImportsController::class, 'store'],
        )->name('env:import');
        Route::post(
            'instances/{instance}/environment/sync',
            [AppInstanceEnvironmentSynchronizationsController::class, 'store'],
        )->name('env:sync');
        Route::put(
            'instances/{instance}/environment/{key}',
            [AppInstanceEnvironmentValuesController::class, 'update'],
        )->name('env:update');
        Route::put(
            'instances/{instance}/database-connections/{database_connection}',
            [DatabaseConnectionAttachmentsController::class, 'store'],
        )
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('instance:database:add');
        Route::delete(
            'instances/{instance}/database-connections/{database_connection}',
            [DatabaseConnectionAttachmentsController::class, 'destroy'],
        )
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('instance:database:remove');
        Route::get('routes', [RoutesController::class, 'index'])->name('route:list');
        Route::post('routes', [RoutesController::class, 'store'])->name('route:create');
        Route::get('routes/{route}', [RoutesController::class, 'show'])
            ->whereNumber('route')
            ->name('route:show');
        Route::patch('routes/{route}', [RoutesController::class, 'update'])
            ->whereNumber('route')
            ->name('route:update');
        Route::put('routes/{route}/target', [RoutesController::class, 'setTarget'])
            ->whereNumber('route')
            ->name('route:target:set');
        Route::delete('routes/{route}/target', [RoutesController::class, 'clearTarget'])
            ->whereNumber('route')
            ->name('route:target:unset');
        Route::delete('routes/{route}', [RoutesController::class, 'destroy'])
            ->whereNumber('route')
            ->name('route:destroy');
        Route::get('schedules', [SchedulesController::class, 'index'])
            ->name('schedule:list');
        Route::post('schedules', [SchedulesController::class, 'store'])
            ->name('schedule:create');
        Route::get('schedules/{schedule}/logs', [SchedulesController::class, 'logs'])
            ->whereUuid('schedule')
            ->name('schedule:logs');
        Route::post('schedules/{schedule}/run', [SchedulesController::class, 'run'])
            ->whereUuid('schedule')
            ->name('schedule:run');
        Route::post('schedules/{schedule}/activate', [SchedulesController::class, 'activate'])
            ->whereUuid('schedule')
            ->name('schedule:enable');
        Route::get('schedules/{schedule}', [SchedulesController::class, 'show'])
            ->whereUuid('schedule')
            ->name('schedule:show');
        Route::delete('schedules/{schedule}', [SchedulesController::class, 'destroy'])
            ->whereUuid('schedule')
            ->name('schedule:destroy');
        Route::get('processes', [ProcessesController::class, 'index'])
            ->name('process:list');
        Route::get('processes/{process}/logs', [ProcessesController::class, 'logs'])
            ->name('process:logs');
        Route::post('processes', [ProcessesController::class, 'store'])
            ->name('process:create');
        Route::post('processes/{process}/start', [ProcessesController::class, 'start'])
            ->name('process:start');
        Route::post('processes/{process}/stop', [ProcessesController::class, 'stop'])
            ->name('process:stop');
        Route::post('processes/{process}/restart', [ProcessesController::class, 'restart'])
            ->name('process:restart');
        Route::post('processes/{process}/database-users', [DatabaseUsersController::class, 'store'])
            ->name('database:user:create');
        Route::delete('processes/{process}', [ProcessesController::class, 'destroy'])
            ->name('process:destroy');
        Route::get('database-connections', [DatabaseConnectionsController::class, 'index'])
            ->name('database:list');
        Route::post('database-connections', [DatabaseConnectionsController::class, 'store'])
            ->name('database:create');
        Route::get('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'show'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:show');
        Route::patch('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'update'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:update');
        Route::delete('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'destroy'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:destroy');
        Route::post('database-connections/{database_connection}/query', [DatabaseConnectionsController::class, 'query'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:query');
        Route::get('database-connections/{database_connection}/tables', [DatabaseConnectionsController::class, 'tables'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:tables');
        Route::get('database-connections/{database_connection}/schema', [DatabaseConnectionsController::class, 'schema'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:schema');
        Route::get('database-connections/{database_connection}/describe/{table}', [DatabaseConnectionsController::class, 'describe'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->where('table', '[A-Za-z_][A-Za-z0-9_]*')
            ->name('database:describe');
        Route::get('herdr/sessions', [HerdrSessionsController::class, 'index'])
            ->name('herdr:session:list');
        Route::post('herdr/sessions', [HerdrSessionsController::class, 'store'])
            ->name('herdr:session:create');
        Route::post('herdr/sessions/adopt', [HerdrSessionsController::class, 'adopt'])
            ->name('herdr:session:adopt');
        Route::get('herdr/sessions/{session}', [HerdrSessionsController::class, 'show'])
            ->whereNumber('session')
            ->name('herdr:session:show');
        Route::post('herdr/sessions/{session}/restart', [HerdrSessionsController::class, 'restart'])
            ->whereNumber('session')
            ->name('herdr:session:restart');
        Route::delete('herdr/sessions/{session}', [HerdrSessionsController::class, 'destroy'])
            ->whereNumber('session')
            ->name('herdr:session:destroy');
        Route::post('herdr/sessions/{session}/observation-grants', [HerdrSessionsController::class, 'storeGrant'])
            ->whereNumber('session')
            ->name('herdr:observe');
        Route::get('tool-managers', [ToolManagersController::class, 'index'])
            ->name('tool:manager:list');
        Route::get('tools', [ToolsController::class, 'index'])->name('tool:list');
        Route::get('tools/{tool}', [ToolsController::class, 'show'])
            ->whereNumber('tool')
            ->name('tool:show');
        Route::post('tools', [ToolsController::class, 'store'])->name('tool:install');
        Route::post('tools/{tool}/update', [ToolsController::class, 'update'])
            ->whereNumber('tool')
            ->name('tool:update');
        Route::delete('tools/{tool}', [ToolsController::class, 'destroy'])
            ->whereNumber('tool')
            ->name('tool:remove');
        Route::post('metrics', [MetricsController::class, 'store'])->name('metrics:enable');
        Route::delete('metrics', [MetricsController::class, 'destroy'])->name('metrics:disable');
        Route::get('metrics/status', [MetricsController::class, 'status'])->name('metrics:status');
        Route::get('metrics/credentials', [MetricsController::class, 'credentials'])->name('metrics:credentials');
        Route::post('metrics/credentials/reset', [MetricsController::class, 'reset'])->name(
            'metrics:credentials:reset',
        );
        Route::put('metrics/exporters/{node}', [MetricsController::class, 'enableExporter'])
            ->whereNumber('node')->name('metrics:exporter:enable');
        Route::delete('metrics/exporters/{node}', [MetricsController::class, 'disableExporter'])
            ->whereNumber('node')
            ->name('metrics:exporter:disable');
    });
});
