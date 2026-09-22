<?php

declare(strict_types=1);

use App\Support\Console\TerminalText;
use App\Support\Tui\ActionRunner;
use App\Support\Tui\Confirmation;
use App\Support\Tui\Interaction;
use App\Support\Tui\State;
use App\Support\Tui\UiState;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use Tests\TestCase;

uses(TestCase::class);

function open_tui_consent(State $state, UiState $ui, Interaction $interaction, string $kind): void
{
    $ui->goTo($kind);
    render_top_screen($ui, $state, columns: 240);
    $ui->focus = 'list';
    $interaction->handleChar('a');
    $interaction->handleKey(KeyCode::Enter);
    render_top_screen($ui, $state);
}

beforeEach(function (): void {
    $this->state = tui_test_state();
    $this->state->databaseTables['charlie-shop'] = [];
    $this->ui = new UiState;
    $this->sent = [];
    $send = function (object $request, string $class): object {
        $this->sent[] = $request->resolveEndpoint();

        return match ($class) {
            DatabaseConnectionResponse::class => DatabaseConnectionResponse::fromGatewayData($this->state->databases[0], '0198e15d-16c4-7855-8eb2-182b53ad28ba'),
            FirewallRuleResponse::class => FirewallRuleResponse::fromGatewayData($this->state->firewall[0], '0198e15d-16c4-7855-8eb2-182b53ad28ba'),
            default => throw new RuntimeException('Unexpected request.'),
        };
    };
    $this->interaction = new Interaction($this->state, $this->ui, new ActionRunner($send), $send);
});

describe('TUI destructive consent', function (): void {
    it('invalidates approval and old hitboxes immediately on a resize event', function (): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'databases');
        $confirmation = $this->ui->menu['confirm'];
        $yes = $confirmation->buttons['yes'];
        $this->interaction->handleChar('y');
        $confirmation->invalidate();
        $this->interaction->handleKey(KeyCode::Enter);
        $this->interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $yes->left(), $yes->top(), 0));

        expect($this->sent)->toBe([])->and($confirmation->fits)->toBeFalse()->and($confirmation->buttons)->toBe([]);

        render_top_screen($this->ui, $this->state, columns: 80, rows: 24);
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe(['/api/v1/database-connections/charlie-shop']);
    });

    it('wraps the complete target and effect at a narrow width', function (string $kind, int $columns): void {
        $this->state->databases[0]['slug'] = 'production-orders-primary-with-a-long-connection-name';
        $this->state->firewall[0]['name'] = 'production-ssh-access-for-maintenance-from-office-network';
        $this->state->nodes[0]['name'] = 'production-app-server-in-amsterdam';
        open_tui_consent($this->state, $this->ui, $this->interaction, $kind);
        $screen = render_top_screen($this->ui, $this->state, columns: $columns, rows: 24);
        $confirmation = $this->ui->menu['confirm'];

        expect($confirmation->fits)->toBeTrue()->and($confirmation->prompt->confirmed)->toBeFalse();

        foreach (TerminalText::wrap($confirmation->prompt->label, min(70, $columns - 2) - 4) as $line) {
            expect($screen)->toContain($line);
        }

        expect_output($screen, "top/consent/{$kind}-{$columns}.txt");
    })->with([['databases', 80], ['firewall', 40]]);

    it('blocks approval until the full question can be shown after resize', function (): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'databases');
        $oldYes = $this->ui->menu['confirm']->buttons['yes'];
        $screen = render_top_screen($this->ui, $this->state, columns: 30, rows: 8);

        expect($screen)->toContain('Resize to read')
            ->and($this->ui->menu['confirm']->fits)->toBeFalse()
            ->and($this->ui->menu['confirm']->buttons)->toBe([]);
        $this->interaction->handleChar('y');
        $this->interaction->handleKey(KeyCode::Enter);
        $this->interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $oldYes->left(), $oldYes->top(), 0));
        expect($this->sent)->toBe([]);

        render_top_screen($this->ui, $this->state, columns: 80, rows: 24);
        $this->interaction->handleChar('y');
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe(['/api/v1/database-connections/charlie-shop']);
    });

    it('declines the default Enter without a request', function (string $kind): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, $kind);
        $this->interaction->handleKey(KeyCode::Enter);

        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull();
    })->with(['databases', 'firewall']);

    it('can decline or cancel an affirmative selection', function (string $key): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'databases');
        $this->interaction->handleChar('y');

        if ($key === 'escape') {
            $this->interaction->handleKey(KeyCode::Esc);
        } else {
            $this->interaction->handleChar($key);
            $this->interaction->handleKey(KeyCode::Enter);
        }

        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull();
    })->with(['n', 'N', 'escape']);

    it('requires an explicit affirmative keyboard choice and sends once', function (string $key): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'databases');

        if ($key === 'right') {
            $this->interaction->handleKey(KeyCode::Right);
        } else {
            $this->interaction->handleChar($key);
        }

        expect($this->sent)->toBe([]);
        $this->interaction->handleKey(KeyCode::Enter);
        $this->interaction->handleKey(KeyCode::Enter);

        expect($this->sent)->toBe(['/api/v1/database-connections/charlie-shop'])
            ->and($this->ui->menu)->toBeNull();
    })->with(['y', 'Y', 'right']);

    it('ignores outside, non-button, and non-left clicks', function (string $where): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'firewall');
        $confirmation = $this->ui->menu['confirm'];
        expect($confirmation)->toBeInstanceOf(Confirmation::class);
        $yes = $confirmation->buttons['yes'];
        [$x, $y, $button] = match ($where) {
            'outside' => [0, 0, MouseButton::Left],
            'inside' => [$yes->left(), $yes->top() - 1, MouseButton::Left],
            'right' => [$yes->left(), $yes->top(), MouseButton::Right],
            'middle' => [$yes->left(), $yes->top(), MouseButton::Middle],
        };
        $this->interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, $button, $x, $y, 0));

        expect($this->sent)->toBe([])->and($this->ui->menu)->not->toBeNull();
    })->with(['outside', 'inside', 'right', 'middle']);

    it('uses only the drawn No and Yes buttons', function (string $choice): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'firewall');
        $button = $this->ui->menu['confirm']->buttons[$choice];
        $event = MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $button->left(), $button->top(), 0);
        $this->interaction->handleMouse($event);
        $this->interaction->handleMouse($event);

        expect($this->sent)->toBe($choice === 'yes' ? ['/api/v1/nodes/1/firewall-rules/ssh'] : [])
            ->and($this->ui->menu)->toBeNull();
    })->with(['no', 'yes']);

    it('keeps the confirmed target when the list order and selection change', function (): void {
        open_tui_consent($this->state, $this->ui, $this->interaction, 'databases');
        array_unshift($this->state->databases, [...$this->state->databases[0], 'id' => 2, 'slug' => 'other']);
        $this->ui->selected['list'] = 0;
        render_top_screen($this->ui, $this->state);
        $this->interaction->handleChar('y');
        $this->interaction->handleKey(KeyCode::Enter);

        expect($this->sent)->toBe(['/api/v1/database-connections/charlie-shop']);
    });

    it('refuses a deleted or changed selector instead of using its replacement', function (string $change): void {
        $kind = str_starts_with($change, 'firewall') ? 'firewall' : 'databases';
        open_tui_consent($this->state, $this->ui, $this->interaction, $kind);

        match ($change) {
            'deleted' => $this->state->databases = [],
            'replaced' => $this->state->databases[0]['id'] = 2,
            'renamed' => $this->state->databases[0]['slug'] = 'new-slug',
            'firewall-renamed' => $this->state->firewall[0]['name'] = 'new-name',
            'firewall-moved' => $this->state->firewall[0]['node_id'] = 2,
            'firewall-replaced' => $this->state->firewall[0]['id'] = 2,
        };
        $this->interaction->handleChar('y');
        $this->interaction->handleKey(KeyCode::Enter);

        expect($this->sent)->toBe([])
            ->and($this->ui->menu)->toBeNull()
            ->and($this->ui->message)->toContain('changed or disappeared');
    })->with(['deleted', 'replaced', 'renamed', 'firewall-renamed', 'firewall-moved', 'firewall-replaced']);
});
