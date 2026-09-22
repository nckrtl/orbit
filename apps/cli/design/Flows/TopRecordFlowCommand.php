<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use App\Support\Console\TerminalText;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\EventProvider\AggregateEventProvider;
use PhpTui\Term\EventProvider\SignalEventProvider;
use PhpTui\Term\EventProvider\SyncTtyEventProvider;
use PhpTui\Term\InformationProvider\SizeFromSttyProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;

/** No-request sketch of a live record page and an obsolete menu choice. */
final class TopRecordFlowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'design:top-record {--outcome=missing : missing or changed}';

    #[\Override]
    protected $description = 'Sketch current top record identity without Gateway requests.';

    public function handle(): int
    {
        $outcome = $this->option('outcome');
        if (! $this->consoleMode()->mayPrompt || ! in_array($outcome, ['missing', 'changed'], true)) {
            return $this->renderGatewayFailure('input.invalid', 'This sketch needs a terminal and outcome missing or changed.');
        }
        $terminal = Terminal::new(
            infoProvider: SizeFromSttyProvider::new(),
            eventProvider: new AggregateEventProvider([SignalEventProvider::registered(), SyncTtyEventProvider::new()]),
        );
        $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
        $status = 'active';
        $page = true;
        $menu = null;
        $message = '';
        $terminal->enableRawMode();
        try {
            $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());
            while (true) {
                $body = ! $page ? 'Processes: '.($status === null ? 'None.' : "horizon · {$status}")
                    : ($status === null ? 'Process: unavailable'."\nThis record is no longer available. Press Esc or ‹ back."
                        : "Process: horizon\nRuntime status: {$status}");
                $body .= $menu === null ? '' : "\n\nActions: {$menu}\nEnter chooses the original action.";
                $body .= "\n\n{$message}\n\na actions · u simulated event · Esc back · q leave";
                $body = implode("\n", TerminalText::wrap($body, max(1, $display->viewportArea()->width - 2)));
                $display->draw(BlockWidget::default()->borders(Borders::ALL)
                    ->titles(Title::fromString(' Live record · no-request sketch '))
                    ->widget(ParagraphWidget::fromString($body)));
                $event = $terminal->events()->next();
                if ($event instanceof CharKeyEvent) {
                    if (in_array($event->char, ['q', 'c'], true)) {
                        break;
                    }
                    if ($event->char === 'u') {
                        $status = $outcome === 'missing' ? null : 'inactive';
                    }
                    if ($event->char === 'a' && $menu === null && $page && $status !== null) {
                        $menu = $status === 'active' ? 'stop' : 'start';
                    }
                }
                if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
                    if ($menu !== null) {
                        $menu = null;
                    } else {
                        $page = false;
                    }
                }
                if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Enter && $menu !== null) {
                    $current = $status === null ? null : ($status === 'active' ? 'stop' : 'start');
                    $message = $current !== $menu
                        ? 'The record changed or disappeared. Reopen its actions.'
                        : "Selected {$menu} for [horizon]. Sketch only; no request sent.";
                    $menu = null;
                }
                usleep(50_000);
            }
        } finally {
            $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }
        $this->line('No request sent.');

        return self::SUCCESS;
    }
}
