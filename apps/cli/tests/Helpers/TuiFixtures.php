<?php

declare(strict_types=1);

use App\Support\Tui\Screen;
use App\Support\Tui\Sources\DatabaseUsersSource;
use App\Support\Tui\Sources\DeploymentsSource;
use App\Support\Tui\Sources\NodeMetricsSource;
use App\Support\Tui\State;
use App\Support\Tui\UiState;
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
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;

/**
 * Builds a `App\Support\Tui\State` loaded from constructed SDK responses, without any HTTP
 * transport: one node ("beast"), one app ("charlie-shop"), one instance on that node and app,
 * one process on that instance, one schedule on that instance, one firewall rule on the node,
 * and one database connection on the node. Shared by the `orbit top` Tui unit tests
 * (`tests/Unit/Tui`) so State, Screen, and Interaction tests start from the same fixture.
 */
function tui_test_state(
    ?DeploymentsSource $deployments = null,
    ?DatabaseUsersSource $databaseUsers = null,
    ?NodeMetricsSource $nodeMetrics = null,
): State {
    $state = new State;
    $deployments ??= new FakeDeploymentsSource(null);
    $databaseUsers ??= new FakeDatabaseUsersSource(null);
    $nodeMetrics ??= new FakeNodeMetricsSource(null);

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
        type: 'laravel-app',
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
        'runtime_status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        // A running Process reports usage, so the screens' CPU/MEM column renders a real reading
        // rather than the dash a stopped one shows.
        'cpu' => 0.2031,
        'memory_bytes' => 1320702444,
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
        status: 'active',
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

    // loadProcesses() asks for the whole fleet in one request, as does every other family here.

    $send = function (object $request, string $responseClass) use (
        $node, $app, $instance, $process, $scheduleData, $firewall, $database,
    ): object {
        return match ($responseClass) {
            NodesResponse::class => new NodesResponse([$node], $node->requestId),
            AppsResponse::class => new AppsResponse([$app], $app->requestId),
            AppInstancesResponse::class => new AppInstancesResponse([$instance], $instance->requestId),
            ProcessesResponse::class => new ProcessesResponse([$process], $process->requestId),
            SchedulesResponse::class => SchedulesResponse::fromGatewayData([$scheduleData], '0198e15d-16c4-7855-8eb2-182b53ad28ba'),
            FirewallRulesResponse::class => new FirewallRulesResponse([$firewall], $firewall->requestId),
            DatabaseConnectionsResponse::class => new DatabaseConnectionsResponse([$database], $database->requestId),
            default => throw new RuntimeException("Unexpected request for {$responseClass}."),
        };
    };

    // The real command draws its first frame before Processes arrive (see State::loadProcesses),
    // but a test wants the finished screen, so load both halves here.
    $state->load($send);
    $state->loadProcesses($send);

    // Screen only ever reads State's per-record caches (see State::nodeMetrics()/
    // deploymentsFor()/databaseUsersFor()); it never fetches on read. Fill them here the way
    // RefreshScheduler would once it had ticked, so a Screen test sees the fixture's sources
    // immediately without needing to drive the scheduler.
    foreach ($state->nodes as $stateNode) {
        $state->setNodeMetrics($stateNode['id'], $nodeMetrics->forNode($stateNode['id']));
    }

    foreach ($state->instances as $stateInstance) {
        $state->setDeployments($stateInstance['id'], $deployments->forInstance($stateInstance['id']));
    }

    foreach ($state->databases as $stateDatabase) {
        $state->setDatabaseUsers($stateDatabase['slug'], $databaseUsers->forConnection($stateDatabase['slug']));
    }

    return $state;
}

/**
 * Renders one `orbit top` Screen frame into a DummyBackend and returns its flushed text grid.
 * Defaults to `tui_test_state()`; pass $state to render against a fixture built with a
 * non-default Deployments, Database users, or Node metrics source.
 */
function render_top_screen(UiState $ui, ?State $state = null, string $header = 'gateway.test · live  ', string $footer = ''): string
{
    $state ??= tui_test_state();
    $backend = DummyBackend::fromDimensions(120, 40);
    $display = DisplayBuilder::default($backend)->fullscreen()->build();
    $display->draw((new Screen)->screen($state, $ui, $header, $footer, $display->viewportArea()));

    return (string) $backend->flushed();
}

/** Node 1 and Instance 1 are different owners; the Instance lives on Node 2. */
function tui_target_state(): State
{
    $state = tui_test_state();
    $state->nodes[] = [...$state->nodes[0], 'id' => 2, 'name' => 'shark', 'wireguard_ip' => '10.44.0.3'];
    $state->instances[0]['node'] = ['id' => 2, 'name' => 'shark'];
    $state->schedules[] = [
        ...$state->schedules[0],
        'id' => '0198e15d-16c4-7855-8eb2-182b53ad28bc',
        'name' => 'node-backup',
        'target_type' => 'node',
        'target_id' => 1,
    ];

    return $state;
}

/** A fixed DeploymentsSource for `orbit top` Tui tests: returns whatever the caller passed it. */
final readonly class FakeDeploymentsSource implements DeploymentsSource
{
    /** @param  list<array<string, mixed>>|null  $rows */
    public function __construct(private ?array $rows) {}

    #[Override]
    public function forInstance(int $instanceId): ?array
    {
        return $this->rows;
    }
}

/** A fixed DatabaseUsersSource for `orbit top` Tui tests: returns whatever the caller passed it. */
final readonly class FakeDatabaseUsersSource implements DatabaseUsersSource
{
    /** @param  list<array<string, mixed>>|null  $rows */
    public function __construct(private ?array $rows) {}

    #[Override]
    public function forConnection(string $slug): ?array
    {
        return $this->rows;
    }
}

/** A fixed NodeMetricsSource for `orbit top` Tui tests: returns whatever the caller passed it. */
final readonly class FakeNodeMetricsSource implements NodeMetricsSource
{
    /** @param  array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}|null  $metrics */
    public function __construct(private ?array $metrics) {}

    #[Override]
    public function forNode(int $nodeId): ?array
    {
        return $this->metrics;
    }
}
