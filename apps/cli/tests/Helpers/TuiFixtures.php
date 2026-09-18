<?php

declare(strict_types=1);

use App\Support\Tui\Sources\NullDatabaseUsersSource;
use App\Support\Tui\Sources\NullDeploymentsSource;
use App\Support\Tui\Sources\NullNodeMetricsSource;
use App\Support\Tui\State;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Orbit\Sdk\Responses\Apps\AppIdentityResponse;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Orbit\Sdk\Responses\Nodes\NodeIdentityResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;

/**
 * Builds a `App\Support\Tui\State` loaded from constructed SDK responses, without any HTTP
 * transport: one node ("beast"), one app ("charlie-shop"), one instance on that node and app,
 * one process on that instance, one schedule on that instance, one firewall rule on the node,
 * and one database connection on the node. Shared by the `orbit top` Tui unit tests
 * (`tests/Unit/Tui`) so State, Screen, and Interaction tests start from the same fixture.
 */
function tui_test_state(): State
{
    $state = new State(new NullDeploymentsSource, new NullDatabaseUsersSource, new NullNodeMetricsSource);

    $node = new NodeResponse(
        id: 1,
        name: 'beast',
        status: 'active',
        publicSshHost: '10.0.0.1',
        publicSshPort: 22,
        user: 'root',
        wireguardIp: '10.44.0.2',
        roles: ['app-dev'],
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    $app = new AppResponse(
        id: 1,
        name: 'Charlie Shop',
        slug: 'charlie-shop',
        repositoryUrl: 'https://example.test/charlie-shop.git',
        defaultBranch: 'main',
        root: null,
        defaults: null,
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    $instance = new AppInstanceResponse(
        id: 1,
        appId: 1,
        nodeId: 1,
        app: new AppIdentityResponse(1, 'Charlie Shop', 'charlie-shop'),
        node: new NodeIdentityResponse(1, 'beast'),
        name: 'dev',
        environment: 'production',
        sourceLayout: 'flat',
        checkoutPath: '/srv/charlie-shop',
        productionUser: null,
        productionHome: null,
        root: null,
        effectiveRoot: null,
        selectedBranch: 'main',
        branchOverride: null,
        migrationRequired: false,
        startingCommit: null,
        detached: false,
        status: 'active',
        route: null,
        domain: 'charlie-shop.test',
        url: null,
        removal: null,
        transfer: null,
        deploySteps: [],
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    $process = ProcessResponse::fromGatewayData([
        'id' => 1,
        'target_type' => 'instance',
        'target_id' => 1,
        'name' => 'horizon',
        'runtime' => 'systemd',
        'working_directory' => '/srv/charlie-shop',
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => 'running',
        'status' => 'active',
        'runtime_status' => 'running',
        'failed_step' => null,
        'error_code' => null,
    ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

    // SchedulesResponse::fromGatewayData() builds each ScheduleResponse itself (with
    // includeCommand: false, matching what ListSchedulesRequest actually sends), so this
    // feeds it the raw Gateway data rather than a pre-built ScheduleResponse.
    $scheduleData = [
        'id' => '0198e15d-16c4-7855-8eb2-182b53ad28bb',
        'target_type' => 'instance',
        'target_id' => 1,
        'name' => 'backup',
        'calendar' => '0 3 * * *',
        'timeout_seconds' => 3600,
        'desired_timer_state' => 'enabled',
        'status' => 'active',
        'failed_step' => null,
        'last_run_at' => null,
        'last_run_status' => null,
    ];

    $firewall = new FirewallRuleResponse(
        id: 1,
        nodeId: 1,
        node: 'beast',
        name: 'ssh',
        action: 'allow',
        source: '10.44.0.0/16',
        protocol: 'tcp',
        port: '22',
        status: 'applied',
        backendStatus: null,
        failedStep: null,
        errorCode: null,
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    $database = new DatabaseConnectionResponse(
        id: 1,
        slug: 'charlie-shop',
        driver: 'pgsql',
        nodeId: 1,
        host: '127.0.0.1',
        port: 5432,
        database: 'charlie_shop',
        path: null,
        username: 'charlie_shop',
        hasPassword: true,
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    // State::load() sends one ProcessesRequest per node, then one per instance (one of each
    // here); the fake process belongs to the instance, so only the second call returns it.
    // Every other family sends exactly one request.
    $processCalls = 0;

    $state->load(function (object $request, string $responseClass) use (
        &$processCalls, $node, $app, $instance, $process, $scheduleData, $firewall, $database,
    ): object {
        if ($responseClass === ProcessesResponse::class) {
            $processCalls++;

            return new ProcessesResponse($processCalls === 2 ? [$process] : [], $process->requestId);
        }

        return match ($responseClass) {
            NodesResponse::class => new NodesResponse([$node], $node->requestId),
            AppsResponse::class => new AppsResponse([$app], $app->requestId),
            AppInstancesResponse::class => new AppInstancesResponse([$instance], $instance->requestId),
            SchedulesResponse::class => SchedulesResponse::fromGatewayData([$scheduleData], '0198e15d-16c4-7855-8eb2-182b53ad28ba'),
            FirewallRulesResponse::class => new FirewallRulesResponse([$firewall], $firewall->requestId),
            DatabaseConnectionsResponse::class => new DatabaseConnectionsResponse([$database], $database->requestId),
            default => throw new RuntimeException("Unexpected request for {$responseClass}."),
        };
    });

    return $state;
}
