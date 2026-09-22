<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use App\Support\Tui\TableColumns;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\EventProvider\AggregateEventProvider;
use PhpTui\Term\EventProvider\SignalEventProvider;
use PhpTui\Term\EventProvider\SyncTtyEventProvider;
use PhpTui\Term\InformationProvider\SizeFromSttyProvider;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;

/** No-request sketch of a Dashboard-sized table and its width refusal. */
final class TopColumnsFlowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'design:top-columns {--outcome=normal : normal, long, or empty}';

    #[\Override]
    protected $description = 'Sketch finite top table columns without Gateway requests.';

    public function handle(): int
    {
        $outcome = $this->option('outcome');
        if (! $this->consoleMode()->mayPrompt || ! in_array($outcome, ['normal', 'long', 'empty'], true)) {
            return $this->renderGatewayFailure('input.invalid', 'This sketch needs a terminal and outcome normal, long, or empty.');
        }
        $name = $outcome === 'long' ? 'worker-production-東京-primary' : 'horizon';
        $headers = ['Name', 'Where', 'Status', 'CPU/MEM'];
        $rows = $outcome === 'empty' ? [] : [TableRow::fromStrings($name, 'charlie-shop/dev', 'active', '20%/1.23GB')];
        $preferences = [Constraint::percentage(20), Constraint::percentage(24), Constraint::percentage(20), Constraint::min(12)];
        $terminal = Terminal::new(
            infoProvider: SizeFromSttyProvider::new(),
            eventProvider: new AggregateEventProvider([SignalEventProvider::registered(), SyncTtyEventProvider::new()]),
        );
        $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
        $selected = false;
        $terminal->enableRawMode();
        try {
            $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());
            while (true) {
                $area = $display->viewportArea();
                $width = max(1, intdiv($area->width - 20, 2));
                $columns = TableColumns::resolve($width, $headers, $rows, $preferences);
                $fits = $columns->widths !== [] && $rows !== [];
                $table = TableWidget::default();
                $table->columnSpacing = 1;
                $content = $rows === []
                    ? ParagraphWidget::fromString('No processes.')
                    : ($fits
                        ? $table->header(TableRow::fromStrings(...$headers))->widths(...$columns->constraints())->rows(...$rows)->select(0)->highlightSymbol('› ')
                        : ParagraphWidget::fromString("Needs {$columns->requiredWidth} columns.\nResize to select.\nEnter/a does nothing."));
                $display->draw(BlockWidget::default()
                    ->padding(Padding::fromScalars(20, max(0, $area->width - 20 - $width), 2, max(0, $area->height - 10)))
                    ->widget(BlockWidget::default()->borders(Borders::ALL)->titles(Title::fromString(' Processes · q quits '))->widget($content)));
                $event = $terminal->events()->next();
                if ($event instanceof CharKeyEvent && in_array($event->char, ['q', 'c'], true)) {
                    break;
                }
                if ($fits && (($event instanceof CodedKeyEvent && $event->code === KeyCode::Enter) || ($event instanceof CharKeyEvent && $event->char === 'a'))) {
                    $selected = true;
                    break;
                }
                if ($event instanceof TerminalResizedEvent) {
                    $display = DisplayBuilder::default(PhpTermBackend::new($terminal))->fullscreen()->build();
                    $display->clear();
                }
                usleep(50_000);
            }
        } finally {
            $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }
        $this->line($selected ? "Selected [{$name}]. Sketch only; no request sent." : 'No selection. No request sent.');

        return self::SUCCESS;
    }
}
