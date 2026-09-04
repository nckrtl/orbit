<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivitiesController;
use App\Http\Controllers\Api\AppInstancesController;
use App\Http\Controllers\Api\AppsController;
use App\Http\Controllers\Api\ClustersController;
use App\Http\Controllers\Api\DoctorRunsController;
use App\Http\Controllers\Api\FirewallRulesController;
use App\Http\Controllers\Api\GatewayStatusesController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NodeAccessController;
use App\Http\Controllers\Api\NodeRolesController;
use App\Http\Controllers\Api\NodesController;
use App\Http\Controllers\Api\ProcessesController;
use App\Http\Controllers\Api\RootCaCertificatesController;
use App\Http\Controllers\Api\RoutesController;
use App\Http\Controllers\Api\ToolManagersController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Controllers\Api\WorkspacesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('gateway/status', [GatewayStatusesController::class, 'show'])
        ->name('gateway:status');
    Route::get('ca/root', [RootCaCertificatesController::class, 'show'])
        ->name('gateway:trust');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->group(function (): void {
        Route::get('nodes', [NodesController::class, 'index'])
            ->name('node:list');
        Route::get('clusters', [ClustersController::class, 'index'])->name('cluster:list');
        Route::post('clusters', [ClustersController::class, 'store'])->name('cluster:new');
        Route::get('clusters/{cluster}', [ClustersController::class, 'show'])
            ->whereNumber('cluster')
            ->name('cluster:show');
        Route::patch('clusters/{cluster}', [ClustersController::class, 'update'])
            ->whereNumber('cluster')
            ->name('cluster:update');
        Route::delete('clusters/{cluster}', [ClustersController::class, 'destroy'])
            ->whereNumber('cluster')
            ->name('cluster:remove');
        Route::put('clusters/{cluster}/nodes/{node}', [ClustersController::class, 'attach'])
            ->whereNumber('cluster')
            ->whereNumber('node')
            ->name('cluster:node:attach');
        Route::delete('clusters/{cluster}/nodes/{node}', [ClustersController::class, 'detach'])
            ->whereNumber('cluster')
            ->whereNumber('node')
            ->name('cluster:node:detach');
        Route::put('clusters/{cluster}/router/{node}', [ClustersController::class, 'setRouter'])
            ->whereNumber('cluster')
            ->whereNumber('node')
            ->name('cluster:router:set');
        Route::delete('clusters/{cluster}/router', [ClustersController::class, 'clearRouter'])
            ->whereNumber('cluster')
            ->name('cluster:router:clear');
        Route::post('doctor', [DoctorRunsController::class, 'store'])
            ->name('doctor:run');
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
            ->name('node:provision');
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
        Route::post('apps', [AppsController::class, 'store'])->name('app:new');
        Route::delete('apps/{app}', [AppsController::class, 'destroy'])->name('app:remove');
        Route::get('instances', [AppInstancesController::class, 'index'])->name('instance:list');
        Route::get('instances/{instance}', [AppInstancesController::class, 'show'])->name('instance:show');
        Route::post('instances', [AppInstancesController::class, 'store'])->name('instance:new');
        Route::delete('instances/{instance}', [AppInstancesController::class, 'destroy'])
            ->name('instance:remove');
        Route::get('routes', [RoutesController::class, 'index'])->name('route:list');
        Route::post('routes', [RoutesController::class, 'store'])->name('route:new');
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
            ->name('route:target:clear');
        Route::delete('routes/{route}', [RoutesController::class, 'destroy'])
            ->whereNumber('route')
            ->name('route:remove');
        Route::get('workspaces', [WorkspacesController::class, 'index'])->name('workspace:list');
        Route::get('workspaces/{workspace}', [WorkspacesController::class, 'show'])
            ->name('workspace:show');
        Route::post('workspaces', [WorkspacesController::class, 'store'])->name('workspace:new');
        Route::delete('workspaces/{workspace}', [WorkspacesController::class, 'destroy'])
            ->name('workspace:remove');
        Route::patch('workspaces/{workspace}/php', [WorkspacesController::class, 'php'])
            ->name('workspace:php');
        Route::get('processes', [ProcessesController::class, 'index'])
            ->name('process:list');
        Route::get('processes/{process}/logs', [ProcessesController::class, 'logs'])
            ->name('process:logs');
        Route::post('processes', [ProcessesController::class, 'store'])
            ->name('process:add');
        Route::post('processes/{process}/start', [ProcessesController::class, 'start'])
            ->name('process:start');
        Route::post('processes/{process}/stop', [ProcessesController::class, 'stop'])
            ->name('process:stop');
        Route::post('processes/{process}/restart', [ProcessesController::class, 'restart'])
            ->name('process:restart');
        Route::delete('processes/{process}', [ProcessesController::class, 'destroy'])
            ->name('process:remove');
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
        Route::delete('metrics', [MetricsController::class, 'destroy'])->name('metrics:remove');
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
