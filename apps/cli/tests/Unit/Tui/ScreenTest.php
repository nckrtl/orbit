<?php

declare(strict_types=1);

use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\Screen;
use App\Support\Tui\UiState;
use PhpTui\Term\KeyCode;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // The node-add form's panel prompts render through Laravel Prompts' own terminal-width
    // detection, which is process-global and would otherwise vary with whatever an earlier
    // test in the same run left behind; pin it so the snapshot is deterministic regardless
    // of run order, the same way tests/Feature/Nodes/AddNodeCommandTest.php does.
    $originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->beforeApplicationDestroyed(static function () use ($originalColumns): void {
        putenv($originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$originalColumns);
    });
});

describe(Screen::class, function (): void {
    it('renders the dashboard with fleet counts and the needs-attention pane', function (): void {
        $ui = new UiState;

        $screen = render_top_screen($ui);

        expect($screen)->toContain('orbit')
            ->and($screen)->toContain('Nodes 1')
            ->and($screen)->toContain('Needs attention')
            ->and($screen)->toContain('Nothing needs attention.');

        expect_output($screen, 'top/dashboard/default.txt');
    });

    it('renders the nodes list page with the create link', function (): void {
        $ui = new UiState;
        $ui->goTo('nodes');

        $screen = render_top_screen($ui);

        expect($screen)->toContain('Nodes')
            ->and($screen)->toContain('+ create')
            ->and($screen)->toContain('beast')
            ->and($screen)->toContain('active');

        expect_output($screen, 'top/list/nodes.txt');
    });

    it('renders a node record page with properties and its sub-panes', function (): void {
        $ui = new UiState;
        $ui->goTo('nodes');
        $ui->open('nodes', tui_test_state()->nodes[0]);

        $screen = render_top_screen($ui);

        expect($screen)->toContain('Node: beast')
            ->and($screen)->toContain('Properties')
            ->and($screen)->toContain('Instances on this node')
            ->and($screen)->toContain('Node processes')
            ->and($screen)->toContain('Firewall')
            ->and($screen)->toContain('No metrics.');

        expect_output($screen, 'top/record/node.txt');
    });

    it('renders the actions menu popup over the current screen', function (): void {
        $ui = new UiState;
        $ui->goTo('nodes');
        $ui->open('nodes', tui_test_state()->nodes[0]);
        $ui->menu = [
            'kind' => 'nodes',
            'title' => 'beast',
            'row' => tui_test_state()->nodes[0],
            'actions' => (new ActionRunner(fn (): never => throw new RuntimeException('not used')))
                ->actionsFor('nodes', tui_test_state()->nodes[0]),
            'selected' => 0,
            'confirm' => false,
            'at' => null,
        ];

        $screen = render_top_screen($ui);

        expect($screen)->toContain('doctor')
            ->and($screen)->toContain('ssh');

        expect_output($screen, 'top/menu/node-actions.txt');
    });

    it('renders the node create form with an inline validation error', function (): void {
        $ui = new UiState;
        $ui->goTo('nodes');
        $interaction = new Interaction(tui_test_state(), $ui, new ActionRunner(fn (): never => throw new RuntimeException('not used')), fn (): never => throw new RuntimeException('not used'));
        $interaction->handleChar('c');
        // An empty required name is rejected without leaving the field.
        $interaction->handleKey(KeyCode::Enter);

        $screen = render_top_screen($ui);

        expect($screen)->toContain('Create node')
            ->and($screen)->toContain('node:add')
            ->and($screen)->toContain('Node name');

        expect_output($screen, 'top/form/node-add-validation.txt');
    });

    it('renders an instance record page with deployment history', function (): void {
        $deployment = [
            'id' => 1,
            'release' => '20260101000000',
            'branch' => 'main',
            'commit' => 'aaaaaaa',
            'started' => '2026-01-01T00:00:00+00:00',
            'finished' => '2026-01-01T00:00:42+00:00',
            'duration' => '42s',
            'status' => 'succeeded',
            'failed_step' => null,
            'error_code' => null,
            'selected_release' => '20260101000000',
            'by' => 'gateway',
        ];
        $state = tui_test_state(deployments: new FakeDeploymentsSource([$deployment]));
        $ui = new UiState;
        $ui->goTo('instances');
        $ui->open('instances', $state->instances[0]);

        $screen = render_top_screen($ui, $state);

        expect($screen)->toContain('Deployments')
            ->and($screen)->toContain('20260101000000')
            ->and($screen)->toContain('main')
            ->and($screen)->toContain('succeeded');

        expect_output($screen, 'top/record/instance-deployments.txt');
    });

    it('renders a deployment record page with its properties and event log', function (): void {
        $deployment = [
            'id' => 1,
            'release' => '20260101000000',
            'branch' => 'main',
            'commit' => 'aaaaaaa',
            'started' => '2026-01-01T00:00:00+00:00',
            'finished' => '2026-01-01T00:00:42+00:00',
            'duration' => '42s',
            'status' => 'failed',
            'failed_step' => 'prepare',
            'error_code' => 'deploy.step_failed',
            'selected_release' => '20260101000000',
            'by' => 'gateway',
        ];
        $state = tui_test_state(deployments: new FakeDeploymentsSource([$deployment]));
        $state->deploymentLogs[1] = [
            '== source preparation ==',
            '== before activation: prepare ==',
            'stdout: Running composer install',
            '[output truncated]',
        ];
        $ui = new UiState;
        $ui->goTo('instances');
        $ui->open('instances', $state->instances[0]);
        $ui->open('deployments', $deployment);

        $screen = render_top_screen($ui, $state);

        expect($screen)->toContain('Deployment:')
            ->and($screen)->toContain('Failed step')
            ->and($screen)->toContain('prepare')
            ->and($screen)->toContain('Running composer install');

        expect_output($screen, 'top/record/deployment.txt');
    });

    it('renders a database record page with its users', function (): void {
        $state = tui_test_state(databaseUsers: new FakeDatabaseUsersSource([
            ['username' => 'app', 'privileges' => 'ALL PRIVILEGES', 'created_by' => 'gateway'],
        ]));
        $ui = new UiState;
        $ui->goTo('databases');
        $ui->open('databases', $state->databases[0]);

        $screen = render_top_screen($ui, $state);

        expect($screen)->toContain('Users')
            ->and($screen)->toContain('app')
            ->and($screen)->toContain('ALL PRIVILEGES');

        expect_output($screen, 'top/record/database-users.txt');
    });

    it('renders a node record page with live metrics', function (): void {
        $state = tui_test_state(nodeMetrics: new FakeNodeMetricsSource([
            'cores' => [0.1, 0.2],
            'mem' => [1.0, 8.0],
            'swap' => [0.0, 2.0],
            'uptime' => '1d 2h 3m',
            'disks' => [['/', 10.0, 80.0]],
        ]));
        $ui = new UiState;
        $ui->goTo('nodes');
        $ui->open('nodes', $state->nodes[0]);

        $screen = render_top_screen($ui, $state);

        expect($screen)->toContain('Up 1d 2h 3m')
            ->and($screen)->not->toContain('No metrics.');

        expect_output($screen, 'top/record/node-metrics.txt');
    });

    it('renders a 9-node dashboard as a compact table with room left for needs attention', function (): void {
        $state = tui_test_state();
        $state->nodes = array_map(
            static fn (int $i): array => [
                'id' => $i,
                'name' => "node-{$i}",
                // node-9 is unhealthy, so its row turns yellow and it also shows in "Needs attention".
                'status' => $i === 9 ? 'failed' : 'active',
                'roles' => ['app-dev'],
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => null,
                'wireguard_ip' => "10.44.0.{$i}",
                'public_ssh_host' => "10.0.0.{$i}",
                'public_ssh_port' => 22,
                'user' => 'root',
            ],
            range(1, 9),
        );
        // node-3 has live metrics from a node.sample event; the rest have none yet, and show
        // "No metrics." in dim text instead of bars.
        $state->nodeSamples[3] = [
            'cores' => [0.2, 0.4],
            'mem' => [2.0, 16.0],
            'swap' => [0.0, 4.0],
            'uptime' => '5d 1h',
            'disks' => [['/', 40.0, 200.0]],
        ];
        $ui = new UiState;

        $screen = render_top_screen($ui, $state);

        expect($screen)->toContain('node-1')
            ->and($screen)->toContain('node-9')
            ->and($screen)->toContain('No metrics.')
            ->and($screen)->toContain('Needs attention')
            ->and($screen)->toContain('Node')
            ->and($screen)->not->toContain('Metrics not available on this Gateway yet.');

        expect_output($screen, 'top/dashboard/nine-nodes.txt');
    });
});
