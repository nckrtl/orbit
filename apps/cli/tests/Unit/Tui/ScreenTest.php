<?php

declare(strict_types=1);

use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\Screen;
use App\Support\Tui\UiState;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;
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

/** Renders one Screen frame into a DummyBackend and returns its flushed text grid. */
function render_top_screen(UiState $ui, string $header = 'gateway.test · live  ', string $footer = ''): string
{
    $state = tui_test_state();
    $backend = DummyBackend::fromDimensions(120, 40);
    $display = DisplayBuilder::default($backend)->fullscreen()->build();
    $display->draw((new Screen)->screen($state, $ui, $header, $footer, $display->viewportArea()));

    return (string) $backend->flushed();
}

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
            ->and($screen)->toContain('Metrics not available on this Gateway yet.');

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
});
