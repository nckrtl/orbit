<?php

declare(strict_types=1);

use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\Screen;
use App\Support\Tui\UiState;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;
use Tests\TestCase;

uses(TestCase::class);

/** Draws one frame so UiState::$drawn holds real pane areas for hit-testing. */
function render_for_hit_testing(UiState $ui): void
{
    $state = tui_test_state();
    $backend = DummyBackend::fromDimensions(120, 40);
    $display = DisplayBuilder::default($backend)->fullscreen()->build();
    $display->draw((new Screen)->screen($state, $ui, '', '', $display->viewportArea()));
}

describe(Interaction::class, function (): void {
    it('moves the selection down and up within the focused pane on arrow keys', function (): void {
        $state = tui_test_state();
        $ui = new UiState;
        $ui->goTo('processes');
        render_for_hit_testing($ui);
        $ui->focus = 'list';

        $interaction = new Interaction($state, $ui, new ActionRunner(fn (): never => throw new RuntimeException('not used')), fn (): never => throw new RuntimeException('not used'));

        expect($ui->selected['list'] ?? 0)->toBe(0);

        $interaction->handleKey(KeyCode::Down);
        // Only one process is loaded, so the selection cannot move past it.
        expect($ui->selected['list'])->toBe(0);

        $interaction->handleKey(KeyCode::Up);
        expect($ui->selected['list'])->toBe(0);
    });

    it('selects a not-yet-selected row on the first click and opens it on the second click', function (): void {
        $state = tui_test_state();
        // A second row so clicking it (index 1) differs from the default selection (index 0)
        // and only selects; clicking the same point again then opens it.
        $state->nodes[] = [
            'id' => 2, 'name' => 'shark', 'status' => 'active', 'roles' => ['app-dev'],
            'platform' => null, 'architecture' => null, 'tld' => null, 'wireguard_ip' => null,
            'public_ssh_host' => '10.0.0.2', 'public_ssh_port' => 22, 'user' => 'root',
        ];
        $ui = new UiState;
        $ui->goTo('nodes');
        render_for_hit_testing($ui);

        $area = $ui->drawn['list']['area'];
        $point = [$area->left() + 2, $area->top() + 3]; // The second data row (shark).

        $interaction = new Interaction($state, $ui, new ActionRunner(fn (): never => throw new RuntimeException('not used')), fn (): never => throw new RuntimeException('not used'));

        $interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $point[0], $point[1], 0));

        expect($ui->pages)->toBe([])
            ->and($ui->focus)->toBe('list')
            ->and($ui->selected['list'])->toBe(1);

        $interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $point[0], $point[1], 0));

        expect($ui->pages)->toHaveCount(1)
            ->and($ui->pages[0]['kind'])->toBe('nodes')
            ->and($ui->pages[0]['row']['name'])->toBe('shark');
    });

    it('opens the actions menu on a right click and runs the chosen action', function (): void {
        $state = tui_test_state();
        $ui = new UiState;
        $ui->goTo('processes');
        render_for_hit_testing($ui);

        $area = $ui->drawn['list']['area'];
        $point = [$area->left() + 2, $area->top() + 2];

        $sentRequest = null;
        $send = function (object $request, string $class) use (&$sentRequest): object {
            $sentRequest = $request;

            return ProcessResponse::fromGatewayData([
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
                'runtime_status' => 'stopped',
                'failed_step' => null,
                'error_code' => null,
            ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');
        };
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);

        $interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Right, $point[0], $point[1], 0));

        expect($ui->menu)->not->toBeNull()
            ->and(array_keys($ui->menu['actions']))->toBe(['restart', 'stop']);

        // Move to "stop" and run it.
        $interaction->handleKey(KeyCode::Down);
        $interaction->handleKey(KeyCode::Enter);

        expect($sentRequest)->not->toBeNull()
            ->and($ui->menu)->toBeNull()
            ->and($ui->message)->toBe('Process [horizon] stopped.')
            ->and($state->processes[0]['runtime_status'])->toBe('stopped');
    });

    it('asks to confirm before running a destructive action', function (): void {
        $state = tui_test_state();
        $ui = new UiState;
        $ui->goTo('databases');
        render_for_hit_testing($ui);

        $area = $ui->drawn['list']['area'];
        $point = [$area->left() + 2, $area->top() + 2];

        $ran = false;
        $send = function () use (&$ran): never {
            $ran = true;

            throw new RuntimeException('should not be reached without confirmation');
        };
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);

        $interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Right, $point[0], $point[1], 0));
        $interaction->handleKey(KeyCode::Enter); // "destroy" is the first (and only real) action.

        expect($ui->menu['confirm'])->toBeTrue()
            ->and($ran)->toBeFalse();

        $interaction->handleKey(KeyCode::Esc);

        expect($ui->menu)->toBeNull();
    });
});
