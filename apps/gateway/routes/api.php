<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDefinitionName;
use App\Http\Controllers\Api\ActivitiesController;
use App\Http\Controllers\Api\AgentRealtimeController;
use App\Http\Controllers\Api\AgentThreadsController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AnnotationsController;
use App\Http\Controllers\Api\ClustersController;
use App\Http\Controllers\Api\DatabaseConnectionAttachmentsController;
use App\Http\Controllers\Api\DatabaseConnectionsController;
use App\Http\Controllers\Api\DatabaseServersController;
use App\Http\Controllers\Api\DoctorRunsController;
use App\Http\Controllers\Api\ExtensionsController;
use App\Http\Controllers\Api\FirewallRulesController;
use App\Http\Controllers\Api\FleetFirewallRulesController;
use App\Http\Controllers\Api\GatewayStatusesController;
use App\Http\Controllers\Api\GitHubAppController;
use App\Http\Controllers\Api\GrafanaAccessAuthorizationController;
use App\Http\Controllers\Api\InstanceAnalyticsController;
use App\Http\Controllers\Api\InstanceClonesController;
use App\Http\Controllers\Api\InstanceDependenciesController;
use App\Http\Controllers\Api\InstanceDeploymentsController;
use App\Http\Controllers\Api\InstanceDeployStepsController;
use App\Http\Controllers\Api\InstanceEnvironmentImportsController;
use App\Http\Controllers\Api\InstanceEnvironmentSynchronizationsController;
use App\Http\Controllers\Api\InstanceEnvironmentValuesController;
use App\Http\Controllers\Api\InstanceLogsController;
use App\Http\Controllers\Api\InstanceLogStreamsController;
use App\Http\Controllers\Api\InstanceQueueController;
use App\Http\Controllers\Api\InstanceReleasesController;
use App\Http\Controllers\Api\InstanceRollbacksController;
use App\Http\Controllers\Api\InstancesController;
use App\Http\Controllers\Api\InstanceTransfersController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NodeAccessController;
use App\Http\Controllers\Api\NodeExcludedProjectsController;
use App\Http\Controllers\Api\NodeMetricsController;
use App\Http\Controllers\Api\NodeRolesController;
use App\Http\Controllers\Api\NodesController;
use App\Http\Controllers\Api\ProcessesController;
use App\Http\Controllers\Api\ProcessLogStreamsController;
use App\Http\Controllers\Api\ProjectDevelopmentDeployStepsController;
use App\Http\Controllers\Api\ProjectDocumentsController;
use App\Http\Controllers\Api\ProjectDocumentStorageController;
use App\Http\Controllers\Api\ProjectExcludedNodesController;
use App\Http\Controllers\Api\ProjectLifecycleStepsController;
use App\Http\Controllers\Api\ProjectRuntimeDefinitionsController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Controllers\Api\ProxyCliController;
use App\Http\Controllers\Api\RealtimeAuthController;
use App\Http\Controllers\Api\RealtimeConfigController;
use App\Http\Controllers\Api\ResolveDependencyInstanceController;
use App\Http\Controllers\Api\ResolveDirectoryInstanceController;
use App\Http\Controllers\Api\RootCaCertificatesController;
use App\Http\Controllers\Api\RoutesController;
use App\Http\Controllers\Api\RuntimeActivationsController;
use App\Http\Controllers\Api\ScheduleCompletionsController;
use App\Http\Controllers\Api\SchedulesController;
use App\Http\Controllers\Api\TaskDefinitionsController;
use App\Http\Controllers\Api\TaskGroupsController;
use App\Http\Controllers\Api\TaskQuestionsController;
use App\Http\Controllers\Api\TasksController;
use App\Http\Controllers\Api\ToolInventoryController;
use App\Http\Controllers\Api\ToolManagersController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\RequireNodeAgentSecret;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])
        ->prefix('instances/{instance}/annotations')->group(function (): void {
            Route::get('', [AnnotationsController::class, 'index'])->name('annotation:list');
            Route::post('', [AnnotationsController::class, 'store'])->name('annotation:create');
            Route::get('events', [AnnotationsController::class, 'events'])->withoutMiddleware(RecordCommandActivity::class)->name('annotation:events');
            Route::post('{annotation}/status', [AnnotationsController::class, 'update'])->name('annotation:update');
            Route::post('{annotation}/retry', [AnnotationsController::class, 'retry'])->name('annotation:retry');
        });

    Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
        Route::get('extensions', [ExtensionsController::class, 'index'])->name('extension:list');
        Route::post('extensions/{extension}/enable', [ExtensionsController::class, 'enable'])->name('extension:enable');
        Route::post('extensions/{extension}/disable', [ExtensionsController::class, 'disable'])->name('extension:disable');
    });

    Route::get('gateway/status', [GatewayStatusesController::class, 'show'])
        ->name('gateway:status');
    Route::get('ca/root', [RootCaCertificatesController::class, 'show'])
        ->name('gateway:trust');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->match(['get', 'post'], 'broadcasting/auth', [RealtimeAuthController::class, 'authenticate'])
        ->name('realtime:auth');

    // Every agent endpoint requires the agent's secret as well as the Node's address (ADR 0155).
    Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAgentSecret::class])
        ->withoutMiddleware(RecordCommandActivity::class)
        ->prefix('agent')->group(function (): void {
            Route::get('realtime', [AgentRealtimeController::class, 'show'])->name('agent:realtime');
            Route::post('broadcasting/auth', [AgentRealtimeController::class, 'authenticate'])->name('agent:realtime:auth');
            Route::get('workspaces', [AgentRealtimeController::class, 'workspaces'])->name('agent:workspaces');
            Route::get('log-streams', [AgentRealtimeController::class, 'logStreams'])->name('agent:log-streams');
        });

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
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
    ])->get('github/app/register', [GitHubAppController::class, 'register'])
        ->withoutMiddleware(RecordCommandActivity::class)
        ->name('github:app:register');

    Route::middleware([
        RequireActiveWireGuardPeer::class,
        RequireNodeAccess::class,
    ])->get('github/app/callback', [GitHubAppController::class, 'callback'])
        ->withoutMiddleware(RecordCommandActivity::class)
        ->name('github:app:callback');

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
        Route::get('project-document-storage', [ProjectDocumentStorageController::class, 'show'])
            ->name('project:document-storage:show');
        Route::put('project-document-storage', [ProjectDocumentStorageController::class, 'update'])
            ->name('project:document-storage:update');
        Route::get('nodes', [NodesController::class, 'index'])
            ->name('node:list');
        Route::post('github/app/install', [GitHubAppController::class, 'install'])->name('github:app:install');
        Route::get('github/app', [GitHubAppController::class, 'show'])->name('github:app:show');
        Route::delete('github/app', [GitHubAppController::class, 'destroy'])->name('github:app:destroy');
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
        Route::post('nodes/{node}/roles/{role}/relocate', [NodeRolesController::class, 'relocate'])
            ->whereNumber('node')
            ->name('node:role:relocate');
        Route::delete('nodes/{node}/roles/{role}', [NodeRolesController::class, 'destroy'])
            ->whereNumber('node')
            ->name('node:role:remove');
        Route::get('firewall-rules', [FleetFirewallRulesController::class, 'index'])
            ->name('firewall:fleet:list');
        Route::get('nodes/{node}/firewall-rules', [FirewallRulesController::class, 'index'])
            ->name('firewall:list');
        Route::get('nodes/{node}/managed-firewall-rules', [FirewallRulesController::class, 'managed'])
            ->whereNumber('node')
            ->name('firewall:managed:list');
        Route::get('nodes/{node}/live-firewall-rules', [FirewallRulesController::class, 'live'])
            ->whereNumber('node')
            ->name('firewall:live:list');
        Route::get('nodes/{node}/metrics', [NodeMetricsController::class, 'show'])
            ->name('node:metrics');
        Route::get('nodes/{node}/excluded-projects', [NodeExcludedProjectsController::class, 'index'])
            ->whereNumber('node')
            ->name('node:excluded-project:list');
        Route::post('nodes/{node}/excluded-projects/{project}', [NodeExcludedProjectsController::class, 'store'])
            ->whereNumber('node')
            ->whereNumber('project')
            ->name('node:excluded-project:add');
        Route::delete('nodes/{node}/excluded-projects/{project}', [NodeExcludedProjectsController::class, 'destroy'])
            ->whereNumber('node')
            ->whereNumber('project')
            ->name('node:excluded-project:remove');
        Route::get('activities', [ActivitiesController::class, 'index'])
            ->name('activity:list');
        Route::get('activities/{activity}', [ActivitiesController::class, 'show'])
            ->name('activity:show');
        Route::post('nodes', [NodesController::class, 'store'])
            ->name('node:add');
        Route::patch('nodes/{node}/name', [NodesController::class, 'rename'])
            ->whereNumber('node')
            ->name('node:rename');
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
        Route::get('projects', [ProjectsController::class, 'index'])->name('project:list');
        Route::prefix('projects/{project}/documents')->whereNumber(['project', 'entry'])->group(function (): void {
            Route::get('', [ProjectDocumentsController::class, 'index'])->name('project:document:list');
            Route::get('search', [ProjectDocumentsController::class, 'search'])->name('project:document:search');
            Route::post('', [ProjectDocumentsController::class, 'store'])->name('project:document:create');
            Route::get('{entry}', [ProjectDocumentsController::class, 'show'])->name('project:document:show');
            Route::patch('{entry}', [ProjectDocumentsController::class, 'update'])->name('project:document:update');
            Route::put('{entry}/content', [ProjectDocumentsController::class, 'write'])->name('project:document:write');
            Route::get('{entry}/content', [ProjectDocumentsController::class, 'read'])->name('project:document:read');
            Route::get('{entry}/download', [ProjectDocumentsController::class, 'download'])->name('project:document:download');
            Route::get('{entry}/versions', [ProjectDocumentsController::class, 'versions'])->name('project:document:version:list');
            Route::post('{entry}/restore-version', [ProjectDocumentsController::class, 'restoreVersion'])->name('project:document:restore-version');
            Route::post('{entry}/archive', [ProjectDocumentsController::class, 'archive'])->name('project:document:archive');
            Route::post('{entry}/restore', [ProjectDocumentsController::class, 'restore'])->name('project:document:restore');
            Route::delete('{entry}', [ProjectDocumentsController::class, 'destroy'])->name('project:document:destroy');
        });
        Route::get('projects/{project}', [ProjectsController::class, 'show'])->name('project:show');
        Route::post('projects', [ProjectsController::class, 'store'])->name('project:create');
        Route::patch('projects/{project}', [ProjectsController::class, 'update'])->name('project:update');
        Route::delete('projects/{project}', [ProjectsController::class, 'destroy'])->name('project:destroy');
        Route::get('projects/{project}/excluded-nodes', [ProjectExcludedNodesController::class, 'index'])
            ->whereNumber('project')
            ->name('project:excluded-node:list');
        Route::post('projects/{project}/excluded-nodes/{node}', [ProjectExcludedNodesController::class, 'store'])
            ->whereNumber('project')
            ->whereNumber('node')
            ->name('project:excluded-node:add');
        Route::delete('projects/{project}/excluded-nodes/{node}', [ProjectExcludedNodesController::class, 'destroy'])
            ->whereNumber('project')
            ->whereNumber('node')
            ->name('project:excluded-node:remove');
        Route::get('projects/{project}/dev-deploy-steps', [ProjectDevelopmentDeployStepsController::class, 'index'])->name('project:dev-deploy-step:list');
        Route::post('projects/{project}/dev-deploy-steps', [ProjectDevelopmentDeployStepsController::class, 'store'])->name('project:dev-deploy-step:create');
        Route::patch('projects/{project}/dev-deploy-steps/{step}', [ProjectDevelopmentDeployStepsController::class, 'update'])
            ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
            ->name('project:dev-deploy-step:update');
        Route::delete('projects/{project}/dev-deploy-steps/{step}', [ProjectDevelopmentDeployStepsController::class, 'destroy'])
            ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
            ->name('project:dev-deploy-step:destroy');
        Route::get('projects/{project}/setup-steps', [ProjectLifecycleStepsController::class, 'setupIndex'])->name('instance:setup-step:list');
        Route::post('projects/{project}/setup-steps', [ProjectLifecycleStepsController::class, 'setupStore'])->name('instance:setup-step:create');
        Route::patch('projects/{project}/setup-steps/{step}', [ProjectLifecycleStepsController::class, 'setupUpdate'])
            ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
            ->name('instance:setup-step:update');
        Route::delete('projects/{project}/setup-steps/{step}', [ProjectLifecycleStepsController::class, 'setupDestroy'])
            ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
            ->name('instance:setup-step:destroy');
        Route::get('projects/{project}/teardown-steps', [ProjectLifecycleStepsController::class, 'teardownIndex'])->name('instance:teardown-step:list');
        Route::post('projects/{project}/teardown-steps', [ProjectLifecycleStepsController::class, 'teardownStore'])->name('instance:teardown-step:create');
        Route::patch('projects/{project}/teardown-steps/{step}', [ProjectLifecycleStepsController::class, 'teardownUpdate'])
            ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
            ->name('instance:teardown-step:update');
        Route::delete('projects/{project}/teardown-steps/{step}', [ProjectLifecycleStepsController::class, 'teardownDestroy'])
            ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
            ->name('instance:teardown-step:destroy');
        Route::prefix('projects/{project}/process-definitions')->scopeBindings()->group(function (): void {
            Route::get('/', [ProjectRuntimeDefinitionsController::class, 'processIndex'])
                ->name('project:process-definition:list');
            Route::post('/', [ProjectRuntimeDefinitionsController::class, 'processStore'])
                ->name('project:process-definition:create');
            Route::get('{processDefinition}', [ProjectRuntimeDefinitionsController::class, 'processShow'])
                ->name('project:process-definition:show');
            Route::put('{processDefinition}', [ProjectRuntimeDefinitionsController::class, 'processUpdate'])
                ->name('project:process-definition:update');
            Route::delete('{processDefinition}', [ProjectRuntimeDefinitionsController::class, 'processDestroy'])
                ->name('project:process-definition:destroy');
        });
        Route::prefix('projects/{project}/schedule-definitions')->scopeBindings()->group(function (): void {
            Route::get('/', [ProjectRuntimeDefinitionsController::class, 'scheduleIndex'])
                ->name('project:schedule-definition:list');
            Route::post('/', [ProjectRuntimeDefinitionsController::class, 'scheduleStore'])
                ->name('project:schedule-definition:create');
            Route::get('{scheduleDefinition}', [ProjectRuntimeDefinitionsController::class, 'scheduleShow'])
                ->name('project:schedule-definition:show');
            Route::put('{scheduleDefinition}', [ProjectRuntimeDefinitionsController::class, 'scheduleUpdate'])
                ->name('project:schedule-definition:update');
            Route::delete('{scheduleDefinition}', [ProjectRuntimeDefinitionsController::class, 'scheduleDestroy'])
                ->name('project:schedule-definition:destroy');
        });
        Route::get('instances/resolve-directory', [ResolveDirectoryInstanceController::class, '__invoke'])->name('instance:resolve-directory');
        Route::get('instances/resolve', [ResolveDependencyInstanceController::class, '__invoke'])->name('instance:resolve');
        Route::get('instances/{instance}/dependencies', [InstanceDependenciesController::class, 'show'])->whereNumber('instance')->name('instance:dependencies:show');
        Route::post('instances/{instance}/dependencies/scan', [InstanceDependenciesController::class, 'scan'])->whereNumber('instance')->name('instance:dependencies:scan');
        Route::post('instances/{instance}/dependencies/update', [InstanceDependenciesController::class, 'update'])->whereNumber('instance')->name('instance:dependencies:update');
        Route::get('instances/{instance}/analytics/stats', [InstanceAnalyticsController::class, 'stats'])->whereNumber('instance')->name('instance:analytics:stats');
        Route::get('instances/{instance}/analytics', [InstanceAnalyticsController::class, 'show'])->whereNumber('instance')->name('instance:analytics:show');
        Route::post('instances/{instance}/analytics', [InstanceAnalyticsController::class, 'enable'])->whereNumber('instance')->name('instance:analytics:enable');
        Route::delete('instances/{instance}/analytics', [InstanceAnalyticsController::class, 'disable'])->whereNumber('instance')->name('instance:analytics:disable');
        Route::get('instances', [InstancesController::class, 'index'])->name('instance:list');
        Route::get('instances/{instance}', [InstancesController::class, 'show'])->name('instance:show');
        Route::patch('instances/{instance}', [InstancesController::class, 'update'])->name('instance:update');
        Route::post('instances/{instance}/rename', [InstancesController::class, 'rename'])->name('instance:rename');
        Route::post('instances', [InstancesController::class, 'store'])->name('instance:create');
        Route::post('instances/register', [InstancesController::class, 'register'])->name('instance:register');
        Route::post('instances/{candidate}/clone', [InstanceClonesController::class, 'store'])
            ->whereNumber('candidate')
            ->name('instance:clone');
        Route::post('instances/{instance}/transfer', [InstanceTransfersController::class, 'store'])
            ->whereNumber('instance')
            ->name('instance:transfer');
        Route::delete('instances/{instance}', [InstancesController::class, 'destroy'])
            ->name('instance:destroy');
        Route::post('instances/{instance}/setup', [InstancesController::class, 'setup'])
            ->whereNumber('instance')
            ->name('instance:setup');
        Route::get(
            'instances/{instance}/deploy-steps',
            [InstanceDeployStepsController::class, 'index'],
        )->name('instance:deploy-step:list');
        Route::post(
            'instances/{instance}/deploy-steps',
            [InstanceDeployStepsController::class, 'store'],
        )->name('instance:deploy-step:create');
        Route::patch(
            'instances/{instance}/deploy-steps/{step}',
            [InstanceDeployStepsController::class, 'update'],
        )->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')->name('instance:deploy-step:update');
        Route::delete(
            'instances/{instance}/deploy-steps/{step}',
            [InstanceDeployStepsController::class, 'destroy'],
        )->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')->name('instance:deploy-step:destroy');
        Route::post(
            'instances/{instance}/deploy',
            [InstanceDeploymentsController::class, 'store'],
        )->name('instance:deploy');
        Route::post(
            'instances/{instance}/rollback',
            [InstanceRollbacksController::class, 'store'],
        )->name('instance:rollback');
        Route::get(
            'instances/{instance}/releases',
            [InstanceReleasesController::class, 'index'],
        )->name('instance:release:list');
        Route::get(
            'instances/{instance}/queue',
            [InstanceQueueController::class, 'show'],
        )->whereNumber('instance')->name('instance:queue');
        Route::get(
            'instances/{instance}/logs',
            [InstanceLogsController::class, 'show'],
        )->whereNumber('instance')->name('instance:logs');
        Route::post(
            'instances/{instance}/log-streams',
            [InstanceLogStreamsController::class, 'store'],
        )->whereNumber('instance')->name('instance:log-stream:create');
        Route::put(
            'instances/{instance}/log-streams/{stream}',
            [InstanceLogStreamsController::class, 'update'],
        )->whereNumber('instance')->where('stream', '[0-9a-f]{32}')
            ->withoutMiddleware(RecordCommandActivity::class)->name('instance:log-stream:renew');
        Route::delete(
            'instances/{instance}/log-streams/{stream}',
            [InstanceLogStreamsController::class, 'destroy'],
        )->whereNumber('instance')->where('stream', '[0-9a-f]{32}')->name('instance:log-stream:destroy');
        Route::get(
            'instances/{instance}/deployments',
            [InstanceDeploymentsController::class, 'index'],
        )->name('instance:deployment:list');
        Route::get(
            'deployments/{deployment}',
            [InstanceDeploymentsController::class, 'show'],
        )->name('instance:deployment:show');
        Route::post(
            'instances/{instance}/environment/import',
            [InstanceEnvironmentImportsController::class, 'store'],
        )->name('env:import');
        Route::post(
            'instances/{instance}/environment/sync',
            [InstanceEnvironmentSynchronizationsController::class, 'store'],
        )->name('env:sync');
        Route::put(
            'instances/{instance}/environment/{key}',
            [InstanceEnvironmentValuesController::class, 'update'],
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
        Route::post('processes/{process}/log-streams', [ProcessLogStreamsController::class, 'store'])
            ->name('process:log-stream:create');
        Route::put('processes/{process}/log-streams/{stream}', [ProcessLogStreamsController::class, 'update'])
            ->where('stream', '[0-9a-f]{32}')
            ->withoutMiddleware(RecordCommandActivity::class)->name('process:log-stream:renew');
        Route::delete('processes/{process}/log-streams/{stream}', [ProcessLogStreamsController::class, 'destroy'])
            ->where('stream', '[0-9a-f]{32}')->name('process:log-stream:destroy');
        Route::post('processes', [ProcessesController::class, 'store'])
            ->name('process:create');
        Route::post('processes/{process}/start', [ProcessesController::class, 'start'])
            ->name('process:start');
        Route::post('processes/{process}/stop', [ProcessesController::class, 'stop'])
            ->name('process:stop');
        Route::post('processes/{process}/restart', [ProcessesController::class, 'restart'])
            ->name('process:restart');
        Route::delete('processes/{process}', [ProcessesController::class, 'destroy'])
            ->name('process:destroy');
        Route::get('database-connections', [DatabaseConnectionsController::class, 'index'])
            ->name('database:list');
        Route::post('database-connections', [DatabaseConnectionsController::class, 'store'])
            ->name('database:create');
        Route::get('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'show'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:show');
        Route::get('database-connections/{database_connection}/users', [DatabaseConnectionsController::class, 'users'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:user:list');
        Route::post('database-connections/{database_connection}/users', [DatabaseConnectionsController::class, 'storeUser'])
            ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:user:create');
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
        Route::get('database-servers', [DatabaseServersController::class, 'index'])
            ->name('database:server:list');
        Route::post('database-servers', [DatabaseServersController::class, 'store'])
            ->name('database:server:create');
        Route::get('database-servers/{database_server}', [DatabaseServersController::class, 'show'])
            ->where('database_server', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:server:show');
        Route::delete('database-servers/{database_server}', [DatabaseServersController::class, 'destroy'])
            ->where('database_server', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('database:server:destroy');
        Route::get('tool-inventory', [ToolInventoryController::class, 'scan'])
            ->name('tool:scan');
        Route::get('tool-managers', [ToolManagersController::class, 'index'])
            ->name('tool:manager:list');
        Route::get('tools', [ToolsController::class, 'index'])->name('tool:list');
        Route::get('tools/{tool}', [ToolsController::class, 'show'])
            ->whereNumber('tool')
            ->name('tool:show');
        Route::post('tools', [ToolsController::class, 'store'])->name('tool:install');
        Route::post('tools/adopt', [ToolsController::class, 'adopt'])->name('tool:adopt');
        Route::post('tools/{tool}/update', [ToolsController::class, 'update'])
            ->whereNumber('tool')
            ->name('tool:update');
        Route::delete('tools/{tool}', [ToolsController::class, 'destroy'])
            ->whereNumber('tool')
            ->name('tool:remove');
        Route::post('proxycli', [ProxyCliController::class, 'store'])->name('proxycli:setup');
        Route::delete('proxycli', [ProxyCliController::class, 'destroy'])->name('proxycli:teardown');
        Route::get('proxycli', [ProxyCliController::class, 'status'])->name('proxycli:status');
        Route::get('proxycli/providers', [ProxyCliController::class, 'index'])->name('proxycli:list');
        Route::get('proxycli/providers/{provider}', [ProxyCliController::class, 'show'])
            ->where('provider', '[a-z][a-z0-9-]*')
            ->name('proxycli:show');
        Route::get('proxycli/models', [ProxyCliController::class, 'models'])->name('proxycli:models');
        Route::patch('proxycli/accounts/{account}', [ProxyCliController::class, 'update'])
            ->where('account', '[A-Za-z0-9._-]+')
            ->name('proxycli:update');
        Route::post('metrics', [MetricsController::class, 'store'])->name('metrics:enable');
        Route::delete('metrics', [MetricsController::class, 'destroy'])->name('metrics:disable');
        Route::post('analytics/update', [AnalyticsController::class, 'update'])->name('analytics:update');
        Route::get('analytics/credentials', [AnalyticsController::class, 'credentials'])->name('analytics:credentials');
        Route::put('analytics/credentials', [AnalyticsController::class, 'setCredentials'])->name('analytics:credentials:set');
        Route::delete('analytics/credentials', [AnalyticsController::class, 'unsetCredentials'])->name('analytics:credentials:unset');
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
        Route::get('task-groups/{group}/agents', [AgentThreadsController::class, 'index'])
            ->whereNumber('group')->withoutMiddleware(RecordCommandActivity::class)->name('tasks:agents');
        Route::get('task-groups/{group}/agents/{session}/stream', [AgentThreadsController::class, 'stream'])
            ->whereNumber('group')->whereNumber('session')->withoutMiddleware(RecordCommandActivity::class)->name('tasks:agent-stream');
        Route::get('tasks/status', [TasksController::class, 'status'])->name('tasks:status');
        Route::get('task-groups', [TaskGroupsController::class, 'index'])->name('tasks:list');
        Route::get('task-questions', [TaskQuestionsController::class, 'index'])->name('tasks:question:list');
        Route::post('task-groups', [TaskGroupsController::class, 'store'])->name('tasks:create');
        Route::get('task-groups/{group}', [TaskGroupsController::class, 'show'])
            ->whereNumber('group')
            ->name('tasks:show');
        Route::patch('task-groups/{group}', [TaskGroupsController::class, 'update'])
            ->whereNumber('group')
            ->name('tasks:update');
        Route::post('task-groups/{group}/tasks', [TaskGroupsController::class, 'createTask'])
            ->whereNumber('group')
            ->name('tasks:subtask:create');
        Route::patch('task-groups/{group}/tasks/{task}', [TaskGroupsController::class, 'updateTask'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:subtask:update');
        Route::delete('task-groups/{group}/tasks/{task}', [TaskGroupsController::class, 'destroyTask'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:subtask:destroy');
        Route::post('task-groups/{group}/tasks/{task}/cancel', [TaskGroupsController::class, 'cancelSubtask'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:subtask:cancel');
        Route::post('task-groups/{group}/tasks/{task}/check/cancel', [TaskGroupsController::class, 'cancelCheck'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:check:cancel');
        Route::post('task-groups/{group}/tasks/{task}/comments', [TaskGroupsController::class, 'storeComment'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:comment:create');
        Route::get('task-groups/{group}/tasks/{task}/comments', [TaskGroupsController::class, 'comments'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:comment:list');
        Route::post('task-groups/{group}/cancel', [TaskGroupsController::class, 'cancel'])
            ->whereNumber('group')
            ->name('tasks:cancel');
        Route::post('task-groups/{group}/complete', [TaskGroupsController::class, 'complete'])
            ->whereNumber('group')
            ->name('tasks:complete');
        Route::get('task-definitions', [TaskDefinitionsController::class, 'index'])->name('tasks:definition:list');
        Route::get('projects/{project}/task-definitions/{name}', [TaskDefinitionsController::class, 'show'])
            ->where('name', TaskDefinitionName::Pattern)
            ->name('tasks:definition:show');
        Route::post('projects/{project}/task-definitions', [TaskDefinitionsController::class, 'store'])
            ->name('tasks:definition:create');
        Route::put('projects/{project}/task-definitions/{name}', [TaskDefinitionsController::class, 'update'])
            ->where('name', TaskDefinitionName::Pattern)
            ->name('tasks:definition:update');
        Route::delete('projects/{project}/task-definitions/{name}', [TaskDefinitionsController::class, 'destroy'])
            ->where('name', TaskDefinitionName::Pattern)
            ->name('tasks:definition:destroy');
    });
});
