<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use App\Support\Tui\Confirmation;
use Laravel\Prompts\Key;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\EventProvider\AggregateEventProvider;
use PhpTui\Term\EventProvider\SignalEventProvider;
use PhpTui\Term\EventProvider\SyncTtyEventProvider;
use PhpTui\Term\InformationProvider\SizeFromSttyProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;

/** Dev-only consent sketch. It never creates a connector or sends a request. */
final class TopConsentFlowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'design:top-consent {--outcome=database : database or firewall}';

    #[\Override]
    protected $description = 'Sketch the top default-No confirmation without Gateway requests.';

    public function handle(): int
    {
        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('input.invalid', 'This sketch needs an interactive terminal.');
        }

        $question = match ($this->option('outcome')) {
            'database' => 'Destroy the Database connection record [production-orders-primary-with-a-long-connection-name]? The physical database is not dropped.',
            'firewall' => 'Remove firewall rule [production-ssh-access-for-maintenance-from-office-network] on Node [production-app-server-in-amsterdam] (ID 42)?',
            default => null,
        };

        if ($question === null) {
            return $this->renderGatewayFailure('input.invalid', 'Outcome must be database or firewall.');
        }

        $confirmation = new Confirmation($question);
        $terminal = Terminal::new(
            infoProvider: SizeFromSttyProvider::new(),
            eventProvider: new AggregateEventProvider([SignalEventProvider::registered(), SyncTtyEventProvider::new()]),
        );
        $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
        $answer = null;
        $terminal->enableRawMode();

        try {
            $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide(), Actions::enableMouseCapture());

            while ($answer === null) {
                $display->draw($confirmation->widget($display->viewportArea()));
                $event = $terminal->events()->next();

                if ($event instanceof CharKeyEvent) {
                    $answer = $event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL ? false : $confirmation->press($event->char);
                } elseif ($event instanceof CodedKeyEvent) {
                    $answer = $confirmation->press(match ($event->code) {
                        KeyCode::Enter => Key::ENTER, KeyCode::Esc => Key::ESCAPE,
                        KeyCode::Left => Key::LEFT, KeyCode::Right => Key::RIGHT,
                        KeyCode::Up => Key::UP, KeyCode::Down => Key::DOWN, KeyCode::Tab => Key::TAB,
                        default => '',
                    });
                } elseif ($event instanceof MouseEvent && $event->kind === MouseEventKind::Down && $event->button === MouseButton::Left) {
                    $answer = $confirmation->click($event->column, $event->row);
                } elseif ($event instanceof TerminalResizedEvent) {
                    $confirmation->invalidate();
                    $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
                    $display->clear();
                }

                usleep(50_000);
            }
        } finally {
            $terminal->execute(Actions::disableMouseCapture(), Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }

        $this->line($answer ? 'Explicit Yes selected. Sketch only; no request sent.' : 'Cancelled. No request sent.');

        return self::SUCCESS;
    }
}
