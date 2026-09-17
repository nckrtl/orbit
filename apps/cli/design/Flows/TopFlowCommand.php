<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use RuntimeException;

/**
 * Design sketch: an htop-like screen on php-tui that refreshes on a tick.
 *
 * Nodes on the left, Processes on the right, a status bar below. Every two seconds the
 * sketch "fetches" again: it reads the recorded fixtures and flips one Process status so
 * the refresh is visible. Registered only when ORBIT_DESIGN=1; see design/README.md.
 */
final class TopFlowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'design:top
        {--tick=2 : Seconds between refreshes}';

    #[\Override]
    protected $description = 'Design sketch of a live top-like screen; runs no Gateway request.';

    public function handle(): int
    {
        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('input.invalid', 'The top screen needs an interactive terminal.');
        }

        $nodes = $this->fixture('nodes/node-list/default');
        $processes = $this->fixture('processes/process-list/instance');
        $tick = max(0.5, (float) ($this->stringOption('tick') ?? '2'));

        $terminal = Terminal::new();
        $display = DisplayBuilder::default()->fullscreen()->build();
        $terminal->enableRawMode();
        $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());

        $pane = 'processes';
        $selected = ['nodes' => 0, 'processes' => 0];
        $refreshes = 0;
        $lastRefresh = microtime(true);
        $lastKey = '';

        try {
            while (true) {
                if (microtime(true) - $lastRefresh >= $tick) {
                    // The real screen would send node:list and process:list here.
                    $refreshes++;
                    $lastRefresh = microtime(true);
                    $flip = $refreshes % 2 === 1;
                    $processes[1]['runtime_status'] = $flip ? 'stopped' : 'running';
                    $processes[1]['status'] = $flip ? 'degraded' : 'active';
                }

                while (($event = $terminal->events()->next()) !== null) {
                    if ($event instanceof CharKeyEvent) {
                        $lastKey = $event->char;
                        if ($event->char === 'q' || ($event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL)) {
                            break 2;
                        }
                        if ($event->char === 'r') {
                            $lastRefresh = 0;
                        }
                    }
                    if ($event instanceof CodedKeyEvent) {
                        $lastKey = $event->code->name;
                        $rows = $pane === 'nodes' ? count($nodes) : count($processes);
                        match ($event->code) {
                            KeyCode::Tab, KeyCode::BackTab, KeyCode::Left, KeyCode::Right => $pane = $pane === 'nodes' ? 'processes' : 'nodes',
                            KeyCode::Down => $selected[$pane] = min($rows - 1, $selected[$pane] + 1),
                            KeyCode::Up => $selected[$pane] = max(0, $selected[$pane] - 1),
                            KeyCode::Esc => throw new RuntimeException('leave'),
                            default => null,
                        };
                    }
                }

                $display->draw($this->screen($nodes, $processes, $pane, $selected, $refreshes, $lastRefresh, $tick, $lastKey));
                usleep(100_000);
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'leave') {
                throw $exception;
            }
        } finally {
            $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }

        $this->writeHumanMessage("Left the top screen after {$refreshes} refreshes.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $processes
     * @param  array{nodes: int, processes: int}  $selected
     */
    private function screen(array $nodes, array $processes, string $pane, array $selected, int $refreshes, float $lastRefresh, float $tick, string $lastKey): GridWidget
    {
        $focused = Style::default()->fg(AnsiColor::Cyan);
        $highlight = Style::default()->addModifier(Modifier::REVERSED);
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        $nodeRows = [];
        foreach ($nodes as $node) {
            $nodeRows[] = TableRow::fromStrings((string) $node['name'], (string) $node['status'], implode(', ', $node['roles']), (string) ($node['wireguard_ip'] ?? '—'));
        }
        $processRows = [];
        foreach ($processes as $process) {
            $processRows[] = TableRow::fromStrings((string) $process['id'], (string) $process['name'], (string) $process['runtime'], (string) $process['desired_state'], (string) $process['runtime_status'], (string) $process['status']);
        }

        $nodesPane = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Nodes '))
            ->borderStyle($pane === 'nodes' ? $focused : $dim)
            ->widget(
                TableWidget::default()
                    ->header(TableRow::fromStrings('Name', 'Status', 'Roles', 'WireGuard'))
                    ->widths(Constraint::percentage(30), Constraint::percentage(20), Constraint::percentage(30), Constraint::percentage(20))
                    ->rows(...$nodeRows)
                    ->select($selected['nodes'])
                    ->highlightSymbol('› ')
                    ->highlightStyle($pane === 'nodes' ? $highlight : Style::default()),
            );

        $processesPane = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Processes on dev.charlie-shop.test '))
            ->borderStyle($pane === 'processes' ? $focused : $dim)
            ->widget(
                TableWidget::default()
                    ->header(TableRow::fromStrings('ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Status'))
                    ->widths(Constraint::length(4), Constraint::percentage(25), Constraint::percentage(15), Constraint::percentage(15), Constraint::percentage(20), Constraint::percentage(15))
                    ->rows(...$processRows)
                    ->select($selected['processes'])
                    ->highlightSymbol('› ')
                    ->highlightStyle($pane === 'processes' ? $highlight : Style::default()),
            );

        $age = max(0, (int) round(microtime(true) - $lastRefresh));
        $status = ParagraphWidget::fromString(
            "  Gateway 10.44.0.1 · refreshed {$age}s ago · every {$tick}s · {$refreshes} refreshes"
            .'  │  Tab switch pane · ↑↓ move · r refresh · q leave'.($lastKey !== '' ? "  │  key: {$lastKey}" : ''),
        )->style($dim);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5), Constraint::length(1))
            ->widgets(
                ParagraphWidget::fromString('  orbit top')->style(Style::default()->addModifier(Modifier::BOLD)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                    ->widgets($nodesPane, $processesPane),
                $status,
            );
    }

    /** @return list<array<string, mixed>> */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 4).'/packages/php-sdk/fixtures/'.$name.'.json';
        if (! is_file($path)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_values($fixture['body']['data']);
    }
}
