<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Console\TerminalText;
use App\Support\Tui\Prompts\AnsiLine;
use App\Support\Tui\Prompts\PanelMultiSelectPromptRenderer;
use App\Support\Tui\Prompts\PanelTextPromptRenderer;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use RuntimeException;

/**
 * Renders one frame of `orbit top` from State (fleet data) and UiState (everything about the
 * screen itself). Ported from the `design:top` sketch (`apps/cli/design/README.md`); the
 * visual result is unchanged except where the report accompanying this PR names a deliberate
 * simplification (the per-engine database stats/keyspace/slowlog panes and the table sample
 * browser are not modeled, because the SDK does not carry that data).
 *
 * Screen never mutates State. It publishes each pane's identities and renderer state in
 * `UiState::$drawn`, records pane order and clamps selections to the rows it draws. Interaction
 * consumes that frame for keyboard and mouse input.
 */
final class Screen
{
    private const int NAV_WIDTH = 20;

    private const int MENU_WIDTH = 40;

    private const int BYTES_PER_GIB = 1024 ** 3;

    /**
     * Width of the CPU/MEM column, which is fixed rather than a share of the pane: a Process pane
     * is often half a split, where any workable percentage rounds down to fewer columns than
     * `400%/12.34GB` needs, and the reading is then silently cut mid-number.
     */
    private const int USAGE_WIDTH = 12;

    public function screen(State $state, UiState $ui, string $header, string $footer, Area $area): Widget
    {
        $previous = $ui->drawn;
        $ui->drawn = [];
        $ui->paneOrder = [];
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        if ($ui->section === 'dashboard' && $ui->page() === null && $ui->form === null && $area->height < 18) {
            $ui->menu = null;

            return ParagraphWidget::fromString('Dashboard needs at least 18 rows. Resize the terminal.');
        }

        $rows = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(10), Constraint::length(1)])->split($area);
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::length(self::NAV_WIDTH), Constraint::min(40)])->split($rows->get(1));
        $ui->drawn['nav'] = ['area' => $columns->get(0), 'header' => false];

        $headerWidget = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::min(12), Constraint::length(60))
            ->widgets(
                ParagraphWidget::fromString('  orbit')->style(Style::default()->addModifier(Modifier::BOLD)),
                ParagraphWidget::fromString($header)->style($dim)->alignment(HorizontalAlignment::Right),
            );

        $body = $ui->form !== null
            ? $this->formPage($ui, $columns->get(1))
            : ($ui->page() === null ? $this->sectionView($state, $ui, $columns->get(1)) : $this->recordPage($state, $ui, $columns->get(1)));

        $screen = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(10), Constraint::length(1))
            ->widgets(
                $headerWidget,
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::length(self::NAV_WIDTH), Constraint::min(40))
                    ->widgets($this->nav($state, $ui), $body),
                ParagraphWidget::fromString($footer)->style($dim),
            );

        $ui->retainTableOffsets($previous);

        return $ui->menu === null ? $screen : CompositeWidget::fromWidgets($screen, $this->menuPopup($ui, $area));
    }

    /**
     * The sidebar: one row per section, its count right-aligned in yellow when that family has
     * something needing a look, using the same finite widths and last-cell alignment as pane().
     * Dashboard has no family of its own, so its count cell
     * is left blank.
     */
    private function nav(State $state, UiState $ui): Widget
    {
        $hovered = $ui->hover === 'nav' && $ui->focus === null && $ui->form === null;
        $counts = $state->counts();
        $widths = [Constraint::length(11), Constraint::length(4)];
        $rows = [];

        foreach (UiState::SECTIONS as $key => $title) {
            $countCell = $key === 'dashboard' ? TableCell::fromString('') : $this->cell((string) $counts[$title][0], $counts[$title][1] > 0);
            $rows[] = TableRow::fromCells(TableCell::fromString($title), $countCell);
        }

        $columns = TableColumns::resolve($ui->drawn['nav']['area']->width, [], $rows, $widths);
        if ($columns->widths === []) {
            return BlockWidget::default()->widget(ParagraphWidget::fromString("Needs {$columns->requiredWidth} columns."));
        }
        $rows = array_map(fn (TableRow $row): TableRow => $this->alignLast($row, $columns->widths[1]), $rows);

        $table = TableWidget::default();
        $table->columnSpacing = 1;
        $table
            ->widths(...$columns->constraints())
            ->rows(...$rows)
            ->select((int) array_search($ui->section, array_keys(UiState::SECTIONS), true))
            ->highlightSymbol('› ')
            ->highlightStyle($hovered ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()->addModifier(Modifier::BOLD));

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle($hovered ? Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD) : Style::default()->fg(AnsiColor::DarkGray))
            ->widget($table);
    }

    /** A section without an open page: the dashboard, or the family's list with its title row. */
    private function sectionView(State $state, UiState $ui, Area $area): Widget
    {
        if ($ui->section === 'dashboard') {
            return $this->dashboard($state, $ui, $area);
        }

        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(5)])->split($area);
        $ui->drawn['list'] = ['area' => $split->get(1), 'header' => true];
        $ui->paneOrder = ['list'];

        $title = UiState::SECTIONS[$ui->section];
        $spans = [Span::styled('  '.$title, Style::default()->addModifier(Modifier::BOLD))];
        $x = $split->get(0)->left() + 2 + strlen($title);

        if ($ui->section === 'nodes') {
            $spans[] = Span::fromString('   ');
            $spans[] = Span::styled('+ create', Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::UNDERLINED));
            $ui->drawn['create'] = ['area' => Area::fromScalars($x + 3, $split->get(0)->top(), 8, 1), 'header' => false];
        }

        if ($ui->hasFilters()) {
            foreach (['node', 'project'] as $filter) {
                $text = "{$filter}: ".($ui->filters[$filter] ?? 'all').' ▾';
                $spans[] = Span::fromString('   ');
                $spans[] = Span::styled($text, $ui->filters[$filter] === null ? $dim : Style::default()->fg(AnsiColor::Cyan));
                $x += 3;
                $ui->drawn["filter:{$filter}"] = ['area' => Area::fromScalars($x, $split->get(0)->top(), mb_strlen($text), 1), 'header' => false];
                $x += mb_strlen($text);
            }
        }

        [$headers, $widths, $rows] = $this->listTable($state, $ui);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(...$spans))),
                $this->pane($ui, 'list', '', $headers, $widths, $rows),
            );
    }

    /** @return array{list<string>, list<Constraint>, list<TableRow>} */
    private function listTable(State $state, UiState $ui): array
    {
        $rows = $state->listRows($ui->section, $ui->filters['node'], $ui->filters['project']);
        $this->paneRecords($ui, 'list', $rows);

        return match ($ui->section) {
            'nodes' => [
                ['Name', 'Status', 'Roles', 'WireGuard IP', 'Instances'],
                [Constraint::percentage(20), Constraint::percentage(14), Constraint::percentage(24), Constraint::percentage(20), Constraint::percentage(22)],
                array_map(fn (array $n): TableRow => $this->row([$n['name'], $n['status'], implode(', ', $n['roles']), $n['wireguard_ip'] ?? '—'], (string) count($state->instancesForNode($n['id'])), ! State::nodeHealthy($n)), $rows),
            ],
            'apps' => [
                ['Slug', 'Name', 'Default branch', 'Instances'],
                [Constraint::percentage(24), Constraint::percentage(34), Constraint::percentage(22), Constraint::percentage(20)],
                array_map(fn (array $a): TableRow => $this->row([$a['slug'], $a['name'], $a['default_branch'] ?? 'main'], (string) count($state->instancesForApp($a['slug'])), false), $rows),
            ],
            'instances' => [
                ['Project', 'Name', 'Environment', 'Node', 'Domain', 'Status'],
                [Constraint::percentage(18), Constraint::percentage(14), Constraint::percentage(14), Constraint::percentage(12), Constraint::percentage(28), Constraint::percentage(10)],
                array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $state->nodeName($i['node']['id']), $i['domain'] ?? '—'], $i['status'], ! State::instanceHealthy($i)), $rows),
            ],
            'processes' => [
                ['Name', 'Owner', 'Node', 'Runtime', 'Status', 'CPU/MEM'],
                [Constraint::percentage(18), Constraint::percentage(24), Constraint::percentage(13), Constraint::percentage(10), Constraint::percentage(13), Constraint::min(self::USAGE_WIDTH)],
                array_map(fn (array $p): TableRow => $this->row([$p['name'], $state->targetOwner($p), $state->targetNodeName($p), $p['runtime'], $p['runtime_status']], $this->processUsage($p), ! State::processHealthy($p)), $rows),
            ],
            'schedules' => [
                ['Name', 'Owner', 'Node', 'Calendar', 'Last run'],
                [Constraint::percentage(18), Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(30), Constraint::percentage(20)],
                array_map(fn (array $s): TableRow => $this->row([$s['name'], $state->targetOwner($s), $state->targetNodeName($s), $s['calendar']], $s['last_run_status'] ?? 'never', ! State::scheduleHealthy($s)), $rows),
            ],
            'databases' => [
                ['Slug', 'Driver', 'Node', 'Host', 'Database'],
                [Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(16), Constraint::percentage(28), Constraint::percentage(24)],
                array_map(fn (array $d): TableRow => $this->row([$d['slug'], $d['driver'], $state->nodeName((int) $d['node_id'])], $d['host'] !== null ? "{$d['host']}:{$d['port']}" : (string) ($d['path'] ?? '—'), false), $rows),
            ],
            'firewall' => [
                ['Node', 'Port', 'Action', 'Source', 'Status'],
                [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(30), Constraint::percentage(18)],
                array_map(fn (array $f): TableRow => $this->row([$state->nodeName($f['node_id']), "{$f['port']}/{$f['protocol']}", $f['action'], $f['source']], $f['status'], ! State::firewallHealthy($f)), $rows),
            ],
            default => [[], [], []],
        };
    }

    /**
     * The dashboard: one compact table row per node, a pane per family, and everything that
     * needs a look. Fleet counts live in the sidebar (see nav()), not here. The node table is
     * sized to its content; the family panes take a fixed slice each so the fleet's size cannot
     * push "Needs attention" off the screen, and each pane scrolls its own rows.
     */
    private function dashboard(State $state, UiState $ui, Area $area): Widget
    {
        $nodesHeight = min(count($state->nodes) + 3, max(3, intdiv($area->height, 3)), $area->height - 12);
        $familyHeight = min(8, intdiv($area->height - $nodesHeight - 4, 2));
        $constraints = [
            Constraint::length($nodesHeight),
            Constraint::length($familyHeight),
            Constraint::length($familyHeight),
            Constraint::min(4),
        ];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($area);
        $halves = static fn (Area $row): mixed => Layout::default()
            ->direction(Direction::Horizontal)
            ->constraints([Constraint::percentage(50), Constraint::percentage(50)])
            ->split($row);
        $upper = $halves($rows->get(1));
        $lower = $halves($rows->get(2));

        $ui->drawn['apps'] = ['area' => $upper->get(0), 'header' => true];
        $ui->drawn['instances'] = ['area' => $upper->get(1), 'header' => true];
        $ui->drawn['processes'] = ['area' => $lower->get(0), 'header' => true];
        $ui->drawn['schedules'] = ['area' => $lower->get(1), 'header' => true];
        $ui->drawn['attention'] = ['area' => $rows->get(3), 'header' => true];
        $ui->paneOrder = ['apps', 'instances', 'processes', 'schedules', 'attention'];

        foreach (['apps', 'instances', 'processes', 'schedules'] as $family) {
            $this->paneRecords($ui, $family, $state->{$family});
        }
        $attentionRows = $state->attentionRows();
        $this->paneRecords($ui, 'attention', array_column($attentionRows, 'record'));
        $ui->drawn['attention']['families'] = array_column($attentionRows, 'kind');
        $attention = array_map(fn (array $a): TableRow => $this->row([$a['label'], $a['name'], $a['where']], $a['state'], true), $attentionRows);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                $this->nodeSummaryTable($state, $ui, $rows->get(0)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                    ->widgets(
                        $this->pane($ui, 'apps', ' Projects ', ['Slug', 'Branch', 'Instances'], [Constraint::percentage(44), Constraint::percentage(30), Constraint::percentage(26)], array_map(fn (array $a): TableRow => $this->row([$a['slug'], $a['default_branch'] ?? 'main'], (string) count($state->instancesForApp($a['slug'])), false), $state->apps), 'No projects.'),
                        $this->pane($ui, 'instances', ' Instances ', ['Name', 'Project', 'Node', 'Status'], [Constraint::percentage(26), Constraint::percentage(26), Constraint::percentage(24), Constraint::percentage(24)], array_map(fn (array $i): TableRow => $this->row([$i['name'], $i['app']['slug'], $state->nodeName($i['node']['id'])], $i['status'], ! State::instanceHealthy($i)), $state->instances), 'No instances.'),
                    ),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                    ->widgets(
                        $this->pane($ui, 'processes', ' Processes ', ['Name', 'Where', 'Status', 'CPU/MEM'], [Constraint::percentage(20), Constraint::percentage(24), Constraint::percentage(20), Constraint::min(self::USAGE_WIDTH)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $state->targetOwner($p), $p['runtime_status']], $this->processUsage($p), ! State::processHealthy($p)), $state->processes), $state->processesLoaded ? 'No processes.' : 'Checking processes…'),
                        $this->pane($ui, 'schedules', ' Schedules ', ['Name', 'Where', 'Calendar', 'Last run'], [Constraint::percentage(22), Constraint::percentage(28), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $state->targetOwner($s), $s['calendar']], $s['last_run_status'] ?? 'never', ! State::scheduleHealthy($s)), $state->schedules), 'No schedules.'),
                    ),
                $this->pane($ui, 'attention', ' Needs attention ', ['Kind', 'Name', 'Where', 'State'], [Constraint::percentage(12), Constraint::percentage(32), Constraint::percentage(26), Constraint::percentage(28)], $attention, $state->processesLoaded ? 'Nothing needs attention.' : 'Checking processes…'),
            );
    }

    /** One line per node: name, status, cpu/mem/disk bars, and uptime; a yellow row means the node needs a look. */
    private function nodeSummaryTable(State $state, UiState $ui, Area $area): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $block = BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)->titles(Title::fromString(' Nodes '));
        $headers = ['Name', 'Status', 'CPU', 'Mem', 'Disk', 'Uptime'];
        $widths = [Constraint::percentage(14), Constraint::percentage(9), Constraint::percentage(21), Constraint::percentage(21), Constraint::percentage(19), Constraint::percentage(16)];
        $rows = array_map(fn (array $node): TableRow => $this->nodeSummaryRow($state, $node, [0, 0, 0, 0, 0, 0]), $state->nodes);
        if ($rows === []) {
            $ui->selected['node-summary'] = 0;
            if ($ui->focus === 'node-summary' || $ui->hover === 'node-summary') {
                $ui->focus = null;
                $ui->hover = $ui->paneOrder[0] ?? 'nav';
            }

            return $block->widget(ParagraphWidget::fromString('No nodes.')->style($dim));
        }
        $columns = TableColumns::resolve($area->width, $headers, $rows, $widths, selector: 0);
        if ($columns->widths === []) {
            return $this->nodeSummaryFallback($ui, $area, $headers, $rows);
        }
        $ui->selected['node-summary'] = 0;
        if ($ui->focus === 'node-summary' || $ui->hover === 'node-summary') {
            $ui->focus = null;
            $ui->hover = $ui->paneOrder[0] ?? 'nav';
        }
        $lastWidth = $columns->widths[count($columns->widths) - 1];

        $table = TableWidget::default();
        $table->columnSpacing = 1;
        $table->header($this->alignLast(TableRow::fromStrings(...$headers), $lastWidth));
        $table->widths(...$columns->constraints())->rows(...array_map(fn (array $node): TableRow => $this->alignLast($this->nodeSummaryRow($state, $node, $columns->widths), $lastWidth), $state->nodes));

        return $block->widget($table);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<TableRow>  $rows
     */
    private function nodeSummaryFallback(UiState $ui, Area $area, array $headers, array $rows): Widget
    {
        $lines = [];
        foreach ($rows as $row) {
            foreach ($headers as $index => $header) {
                array_push($lines, ...TerminalText::wrap($header.': '.TableColumns::text($row->getCell($index)), max(1, $area->width - 2)));
            }
            $lines[] = '';
        }
        array_pop($lines);
        $height = max(0, $area->height - 2);
        $offset = max(0, min($ui->selected['node-summary'] ?? 0, max(0, count($lines) - $height)));
        $ui->selected['node-summary'] = $offset;
        $ui->drawn['node-summary'] = ['area' => $area, 'header' => false, 'textLines' => count($lines)];
        array_unshift($ui->paneOrder, 'node-summary');
        $end = min(count($lines), $offset + $height);

        return BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg($ui->focus === 'node-summary' ? AnsiColor::Cyan : AnsiColor::DarkGray))
            ->titles(Title::fromString(' Nodes · '.($offset + 1)."–{$end}/".count($lines).' · ↑↓ scroll '))
            ->widget(ParagraphWidget::fromString(implode("\n", array_slice($lines, $offset, $height))));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<int>  $columns  Rendered pixel widths for [name, status, cpu, mem, disk, uptime].
     */
    private function nodeSummaryRow(State $state, array $node, array $columns): TableRow
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $warn = ! State::nodeHealthy($node);
        $textStyle = $warn ? Style::default()->fg(AnsiColor::Yellow) : Style::default();
        $name = $this->styledCell($node['name'], $textStyle);
        $status = $this->styledCell($node['status'], $textStyle);
        $metrics = $state->nodeMetrics($node['id']);

        if ($metrics === null) {
            $blank = TableCell::fromLine(Line::fromSpan(Span::styled('—', $dim)));

            return TableRow::fromCells($name, $status, $blank, $blank, $blank, $blank);
        }

        $cpu = array_sum($metrics['cores']) / max(1, count($metrics['cores']));
        [$mount, $used, $total] = $metrics['disks'][0] ?? ['/', 0.0, 0.0];

        return TableRow::fromCells(
            $name,
            $status,
            TableCell::fromLine(Line::fromSpans(...$this->bar('', $cpu, sprintf('%3.0f%%', $cpu * 100), $columns[2]))),
            TableCell::fromLine(Line::fromSpans(...$this->bar('', $metrics['mem'][1] > 0 ? $metrics['mem'][0] / $metrics['mem'][1] : 0, sprintf('%.1fG/%.0fG', $metrics['mem'][0], $metrics['mem'][1]), $columns[3]))),
            TableCell::fromLine(Line::fromSpans(...$this->bar(str_pad((string) $mount, 3), $total > 0 ? $used / $total : 0, sprintf('%.0fG/%.0fG', $used, $total), $columns[4], [80, 90]))),
            $this->styledCell($metrics['uptime'], $dim),
        );
    }

    /** @param array<string, mixed> $node */
    private function nodeSummaryBlock(State $state, array $node, int $width): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $metrics = $state->nodeMetrics($node['id']);

        if ($metrics === null) {
            return BlockWidget::default()
                ->borders(Borders::ALL)->borderType(BorderType::Rounded)
                ->titles(Title::fromString(" {$node['name']} · {$node['status']} "))
                ->borderStyle(State::nodeHealthy($node) ? $dim : Style::default()->fg(AnsiColor::Yellow))
                ->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString('No metrics.')->style($dim));
        }

        $inner = $width - 4;
        $third = intdiv($inner - 4, 3);
        $cpu = array_sum($metrics['cores']) / max(1, count($metrics['cores']));
        [$mount, $used, $total] = $metrics['disks'][0] ?? ['/', 0.0, 0.0];

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$node['name']} · {$node['status']} · up {$metrics['uptime']} "))
            ->borderStyle(State::nodeHealthy($node) ? $dim : Style::default()->fg(AnsiColor::Yellow))
            ->padding(Padding::horizontal(1))
            ->widget(ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(...[
                ...$this->bar('cpu', $cpu, sprintf('%3.0f%%', $cpu * 100), $third),
                Span::fromString('  '),
                ...$this->bar('mem', $metrics['mem'][1] > 0 ? $metrics['mem'][0] / $metrics['mem'][1] : 0, sprintf('%.1fG/%.0fG', $metrics['mem'][0], $metrics['mem'][1]), $third),
                Span::fromString('  '),
                ...$this->bar(str_pad((string) $mount, 3), $total > 0 ? $used / $total : 0, sprintf('%.0fG/%.0fG', $used, $total), $inner - 2 * $third - 4, [80, 90]),
            ]))));
    }

    /**
     * The node page's htop-like block: cores in two columns, then memory and swap beside the root
     * disk and uptime. The dashboard uses the compact one-line-per-node table instead
     * (nodeSummaryTable); nodeSummaryBlock now only serves this page's no-metrics fallback.
     *
     * @param  array<string, mixed>  $node
     */
    private function nodeMetricsPanel(State $state, array $node, int $width): Widget
    {
        $metrics = $state->nodeMetrics($node['id']);

        if ($metrics === null) {
            return $this->nodeSummaryBlock($state, $node, $width);
        }

        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $inner = $width - 4;
        $gap = 2;
        $half = intdiv($inner - $gap, 2);
        $lastHalf = $inner - $half - $gap;

        $coreLines = [];
        foreach (array_chunk($metrics['cores'], 2, true) as $group) {
            $spans = [];
            $position = 0;
            foreach ($group as $core => $load) {
                if ($position > 0) {
                    $spans[] = Span::fromString(str_repeat(' ', $gap));
                }
                $spans = [...$spans, ...$this->bar(str_pad((string) $core, 3), $load, sprintf('%3.0f%%', $load * 100), $position === 1 ? $lastHalf : $half)];
                $position++;
            }
            $coreLines[] = Line::fromSpans(...$spans);
        }

        [$mount, $used, $total] = $metrics['disks'][0] ?? ['/', 0.0, 0.0];
        $left = [
            Line::fromSpans(...$this->bar('Mem', $metrics['mem'][1] > 0 ? $metrics['mem'][0] / $metrics['mem'][1] : 0, sprintf('%.1fG/%.0fG', $metrics['mem'][0], $metrics['mem'][1]), $half)),
            Line::fromSpans(...$this->bar('Swp', $metrics['swap'][1] > 0 ? $metrics['swap'][0] / $metrics['swap'][1] : 0, sprintf('%.1fG/%.0fG', $metrics['swap'][0], $metrics['swap'][1]), $half)),
        ];
        $right = [
            Line::fromSpans(...$this->bar(str_pad((string) $mount, 3), $total > 0 ? $used / $total : 0, sprintf('%.0fG/%.0fG', $used, $total), $lastHalf, [80, 90])),
            Line::fromSpans(Span::styled('Up ', $dim), Span::fromString($metrics['uptime'])),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$node['name']} · {$node['status']} · metrics "))
            ->borderStyle(State::nodeHealthy($node) ? $dim : Style::default()->fg(AnsiColor::Yellow))
            ->padding(Padding::horizontal(1))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(Constraint::length(count($coreLines)), Constraint::min(2))
                    ->widgets(
                        ParagraphWidget::fromText(Text::fromLines(...$coreLines)),
                        GridWidget::default()
                            ->direction(Direction::Horizontal)
                            ->constraints(Constraint::length($half), Constraint::length($gap), Constraint::min($lastHalf))
                            ->widgets(ParagraphWidget::fromText(Text::fromLines(...$left)), BlockWidget::default(), ParagraphWidget::fromText(Text::fromLines(...$right))),
                    ),
            );
    }

    /** One record: crumbs, properties, and the panes the family has. */
    private function recordPage(State $state, UiState $ui, Area $area): Widget
    {
        $page = $ui->page() ?? throw new RuntimeException('No page is open.');
        $kind = $page['kind'];
        $row = $ui->pageRow($state);
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(5)])->split($area);
        $body = $split->get(1);
        $ui->drawn['back'] = ['area' => Area::fromScalars($split->get(0)->left(), $split->get(0)->top(), 10, 1), 'header' => false];

        $label = match ($kind) {
            'nodes' => 'Node',
            'apps' => 'Project',
            'instances' => 'Instance',
            'processes' => 'Process',
            'schedules' => 'Schedule',
            'databases' => 'Database',
            'firewall' => 'Firewall rule',
            'deployments' => 'Deployment',
            default => '',
        };
        $crumbs = ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(
            Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)),
            Span::styled("{$label}: ", $dim),
            Span::styled($row === null ? 'unavailable' : $this->rowTitle($kind, $row), Style::default()->addModifier(Modifier::BOLD)),
        )));

        if ($row === null) {
            $ui->focus = null;
            $ui->hover = 'nav';

            return GridWidget::default()->direction(Direction::Vertical)
                ->constraints(Constraint::length(1), Constraint::min(5))
                ->widgets($crumbs, ParagraphWidget::fromString(implode("\n", TerminalText::wrap('This record is no longer available. Press Esc or ‹ back.', max(1, $body->width)))));
        }

        $hasSubPanes = in_array($kind, ['nodes', 'apps', 'instances', 'databases'], true);
        $propertiesWidth = $hasSubPanes ? intdiv($body->width * 40, 100) : $body->width;
        $propertyRows = [];
        $index = 0;

        foreach ($this->properties($state, $kind, $row) as $name => [$value, $warn]) {
            $link = in_array($name, ['Project', 'Node'], true) && $value !== '—';

            if ($link) {
                $ui->drawn['link:'.str_replace(' ', '', strtolower($name))] = ['area' => Area::fromScalars($body->left() + 2, $body->top() + 1 + $index, max(10, $propertiesWidth - 4), 1), 'header' => false];
            }

            $valueCell = $link ? TableCell::fromLine(Line::fromSpan(Span::styled($value, Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::UNDERLINED)))) : $this->cell($value, $warn);
            $propertyRows[] = TableRow::fromCells(TableCell::fromLine(Line::fromSpan(Span::styled($name, $dim))), $valueCell);
            $index++;
        }

        $table = TableWidget::default()->widths(Constraint::length(18), Constraint::min(10))->rows(...$propertyRows);
        $table->columnSpacing = 1;
        $properties = BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Properties '))->borderStyle($dim)->padding(Padding::horizontal(1))->widget($table);
        $propertiesHeight = count($propertyRows) + 2;

        $content = match ($kind) {
            'nodes' => $this->nodePage($state, $ui, $row, $properties, $propertiesHeight, $body),
            'apps' => $this->appPage($state, $ui, $row, $properties, $propertiesHeight, $body),
            'instances' => $this->instancePage($state, $ui, $row, $properties, $propertiesHeight, $body),
            'databases' => $this->databasePage($state, $ui, $row, $properties, $propertiesHeight, $body),
            'processes' => $this->logPage($properties, $propertiesHeight, ' Log · process:logs ', $state->processLogs[$row['id']] ?? [], $body),
            'schedules' => $this->logPage($properties, $propertiesHeight, ' Log · schedule:logs ', $state->scheduleLogs[$row['id']] ?? [], $body),
            'deployments' => $this->logPage($properties, $propertiesHeight, ' Log · instance:deployment:show ', $state->deploymentLogs[$row['id']] ?? [], $body),
            default => $this->logPage($properties, $propertiesHeight, ' Detail ', [], $body),
        };

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets($crumbs, $content);
    }

    /** @param array<string, mixed> $node */
    private function nodePage(State $state, UiState $ui, array $node, Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $instances = $state->instancesForNode($node['id']);
        $processes = $state->processesForNode($node['id']);
        $firewall = $state->firewallForNode($node['id']);
        $metrics = $state->nodeMetrics($node['id']);
        $metricsHeight = $metrics === null ? 3 : intdiv(count($metrics['cores']) + 1, 2) + 4;
        $top = max($propertiesHeight, $metricsHeight);
        $constraints = [Constraint::length($top), Constraint::length(min(count($instances) + 3, max(5, $body->height - $top - 8))), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $topColumns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(60)])->split($rows->get(0));
        $bottom = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(50), Constraint::percentage(50)])->split($rows->get(2));
        $ui->drawn['instances'] = ['area' => $rows->get(1), 'header' => true];
        $ui->drawn['processes'] = ['area' => $bottom->get(0), 'header' => true];
        $ui->drawn['firewall'] = ['area' => $bottom->get(1), 'header' => true];
        $ui->paneOrder = ['instances', 'processes', 'firewall'];
        $this->paneRecords($ui, 'instances', $instances);
        $this->paneRecords($ui, 'processes', $processes);
        $this->paneRecords($ui, 'firewall', $firewall);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                    ->widgets($properties, $this->nodeMetricsPanel($state, $node, $topColumns->get(1)->width)),
                $this->pane($ui, 'instances', ' Instances on this node ', ['Project', 'Name', 'Environment', 'Domain', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(32), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['domain'] ?? '—'], $i['status'], ! State::instanceHealthy($i)), $instances)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                    ->widgets(
                        $this->pane($ui, 'processes', ' Node processes ', ['Name', 'Status', 'CPU/MEM'], [Constraint::percentage(40), Constraint::percentage(27), Constraint::min(self::USAGE_WIDTH)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime_status']], $this->processUsage($p), ! State::processHealthy($p)), $processes)),
                        $this->pane($ui, 'firewall', ' Firewall ', ['Port', 'Action', 'Source', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(40), Constraint::percentage(18)], array_map(fn (array $f): TableRow => $this->row(["{$f['port']}/{$f['protocol']}", $f['action'], $f['source']], $f['status'], ! State::firewallHealthy($f)), $firewall)),
                    ),
            );
    }

    /** @param array<string, mixed> $app */
    private function appPage(State $state, UiState $ui, array $app, Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $instances = $state->instancesForApp($app['slug']);
        $schedules = $state->schedulesForApp($app['slug']);
        $constraints = [Constraint::length($propertiesHeight), Constraint::length(count($instances) + 3), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $ui->drawn['instances'] = ['area' => $rows->get(1), 'header' => true];
        $ui->drawn['schedules'] = ['area' => $rows->get(2), 'header' => true];
        $ui->paneOrder = ['instances', 'schedules'];
        $this->paneRecords($ui, 'instances', $instances);
        $this->paneRecords($ui, 'schedules', $schedules);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                $properties,
                $this->pane($ui, 'instances', ' Instances ', ['Name', 'Environment', 'Node', 'Domain', 'Status'], [Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(40), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['name'], $i['environment'], $state->nodeName($i['node']['id']), $i['domain'] ?? '—'], $i['status'], ! State::instanceHealthy($i)), $instances)),
                $this->pane($ui, 'schedules', ' Schedules ', ['Name', 'Instance', 'Calendar', 'Last run'], [Constraint::percentage(20), Constraint::percentage(22), Constraint::percentage(36), Constraint::percentage(20)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $state->instanceName($s['target_id']), $s['calendar']], $s['last_run_status'] ?? 'never', ! State::scheduleHealthy($s)), $schedules)),
            );
    }

    /** @param array<string, mixed> $instance */
    private function instancePage(State $state, UiState $ui, array $instance, Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $processes = $state->processesForInstance($instance['id']);
        $schedules = $state->schedulesForInstance($instance['id']);
        $deploySteps = $instance['deploy_steps'];
        $deployments = $state->deploymentsFor($instance['id']);
        $topHeight = max($propertiesHeight, count($processes) + 3, count($schedules) + 3);
        $stepsHeight = count($deploySteps) + 3;
        $constraints = [Constraint::length($topHeight), Constraint::length($stepsHeight), Constraint::min(4)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(34), Constraint::percentage(34), Constraint::percentage(32)])->split($rows->get(0));
        $ui->drawn['processes'] = ['area' => $columns->get(1), 'header' => true];
        $ui->drawn['schedules'] = ['area' => $columns->get(2), 'header' => true];
        $ui->drawn['deploysteps'] = ['area' => $rows->get(1), 'header' => true];
        $ui->paneOrder = ['processes', 'schedules', 'deploysteps'];
        $this->paneRecords($ui, 'processes', $processes);
        $this->paneRecords($ui, 'schedules', $schedules);
        $this->paneRecords($ui, 'deploysteps', $deploySteps);

        if ($deployments !== null) {
            $ui->drawn['deployments'] = ['area' => $rows->get(2), 'header' => true];
            $ui->paneOrder[] = 'deployments';
            $this->paneRecords($ui, 'deployments', $deployments);
        }

        $deploymentsWidget = $deployments === null
            ? BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Deployments '))->borderStyle($dim)->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString('Deployment history unavailable right now.')->style($dim))
            : $this->pane($ui, 'deployments', ' Deployments ', ['Started', 'Release', 'Branch', 'Commit', 'By', 'Duration', 'Status'], [Constraint::percentage(16), Constraint::percentage(18), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(14)], array_map(fn (array $d): TableRow => $this->row([$d['started'], $d['release'], $d['branch'], $d['commit'], $d['by'], $d['duration']], $d['status'], ! State::deploymentHealthy($d)), $deployments), 'Not deployed yet.');

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(34), Constraint::percentage(34), Constraint::percentage(32))
                    ->widgets(
                        $properties,
                        $this->pane($ui, 'processes', ' Processes ', ['Name', 'Status', 'CPU/MEM'], [Constraint::percentage(26), Constraint::percentage(22), Constraint::min(self::USAGE_WIDTH)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime_status']], $this->processUsage($p), ! State::processHealthy($p)), $processes)),
                        $this->pane($ui, 'schedules', ' Schedules ', ['Name', 'Calendar', 'Last run'], [Constraint::percentage(30), Constraint::percentage(42), Constraint::percentage(24)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $s['calendar']], $s['last_run_status'] ?? 'never', ! State::scheduleHealthy($s)), $schedules)),
                    ),
                $this->pane($ui, 'deploysteps', ' Deploy steps in the order they run ', ['Phase', '#', 'Name', 'Timeout'], [Constraint::percentage(24), Constraint::percentage(8), Constraint::percentage(38), Constraint::percentage(30)], array_map(fn (int $index, array $s): TableRow => $this->row([$s['phase'], (string) ($index + 1), $s['name']], "{$s['timeout_seconds']} s", false), array_keys($deploySteps), $deploySteps), 'No deploy steps. instance:deploy-step:create adds one.'),
                $deploymentsWidget,
            );
    }

    /** @param array<string, mixed> $database */
    private function databasePage(State $state, UiState $ui, array $database, Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $tables = $state->databaseTables[$database['slug']] ?? [];
        $users = $state->databaseUsersFor($database['slug']);
        $top = $propertiesHeight;
        $constraints = [Constraint::length($top), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(45), Constraint::percentage(55)])->split($rows->get(1));
        $ui->drawn['tables'] = ['area' => $columns->get(0), 'header' => true];
        $ui->paneOrder = ['tables'];
        $this->paneRecords($ui, 'tables', array_map(static fn (string $table): array => ['id' => $table], $tables));

        if ($users !== null) {
            $ui->drawn['users'] = ['area' => $columns->get(1), 'header' => true];
            $ui->paneOrder[] = 'users';
            $this->paneRecords($ui, 'users', $users);
        }

        $usersWidget = $users === null
            ? BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Users '))->borderStyle($dim)->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString('Database users unavailable right now.')->style($dim))
            : $this->pane($ui, 'users', ' Users ', ['Username', 'Privileges', 'Created by'], [Constraint::percentage(24), Constraint::percentage(46), Constraint::percentage(30)], array_map(fn (array $u): TableRow => $this->row([$u['username'], $u['privileges']], $u['created_by'], false), $users), 'No users recorded.');

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                $properties,
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(45), Constraint::percentage(55))
                    ->widgets(
                        $this->pane($ui, 'tables', ' Tables · database:tables ', ['Table'], [Constraint::percentage(96)], array_map(fn (string $t): TableRow => $this->row([], $t, false), $tables), 'No tables, or not loaded yet.'),
                        $usersWidget,
                    ),
            );
    }

    /**
     * Properties across the top, then lines the record produced (a log, for example) over the
     * full width.
     *
     * @param  list<string>  $lines
     */
    private function logPage(Widget $properties, int $propertiesHeight, string $title, array $lines, Area $body): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $tail = max(1, $body->height - $propertiesHeight - 2);
        $text = $lines === [] ? 'No log lines yet.' : implode("\n", array_slice($lines, -$tail));

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($propertiesHeight), Constraint::min(4))
            ->widgets(
                $properties,
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString($title))->borderStyle($dim)->padding(Padding::horizontal(1))
                    ->widget(ParagraphWidget::fromString($text)->style($lines === [] ? $dim : Style::default())),
            );
    }

    /**
     * The properties a page lists, named as the show commands name them. Each entry carries
     * whether that one field, in its own vocabulary (see the `State::*Healthy()` family), needs
     * a look — never a blanket comparison against an unrelated field's healthy values.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array{string, bool}>
     */
    private function properties(State $state, string $kind, array $row): array
    {
        $value = fn (mixed $v): string => match (true) {
            $v === null, $v === '' => '—',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => implode(', ', $v),
            default => (string) $v,
        };

        $properties = match ($kind) {
            'nodes' => ['Name' => [$row['name'], false], 'Status' => [$row['status'], ! State::nodeHealthy($row)], 'Roles' => [$row['roles'], false], 'Platform' => [$row['platform'] ?? null, false], 'Architecture' => [$row['architecture'] ?? null, false], 'TLD' => [$row['tld'] ?? null, false], 'WireGuard IP' => [$row['wireguard_ip'] ?? null, false], 'SSH' => ["{$row['user']}@{$row['public_ssh_host']}:{$row['public_ssh_port']}", false]],
            'apps' => ['Name' => [$row['name'], false], 'Slug' => [$row['slug'], false], 'Repository' => [$row['repository_url'] ?? null, false], 'Default branch' => [$row['default_branch'] ?? null, false], 'Root' => [$row['root'] ?? null, false]],
            'instances' => ['Name' => [$row['name'], false], 'Project' => [$row['app']['slug'], false], 'Node' => [$state->nodeName($row['node']['id']), false], 'Environment' => [$row['environment'], false], 'Domain' => [$row['domain'] ?? null, false], 'Status' => [$row['status'], ! State::instanceHealthy($row)], 'Checkout' => [$row['checkout_path'] ?? null, false], 'Selected branch' => [$row['selected_branch'] ?? null, false], 'Deploy steps' => [count($row['deploy_steps']).' steps', false]],
            'databases' => ['Slug' => [$row['slug'], false], 'Driver' => [$row['driver'], false], 'Node' => [$state->nodeName((int) ($row['node_id'] ?? 0)), false], 'Host' => [$row['host'] !== null ? "{$row['host']}:{$row['port']}" : null, false], 'Path' => [$row['path'] ?? null, false], 'Database' => [$row['database'] ?? null, false], 'Username' => [$row['username'] ?? null, false], 'Password' => [$row['has_password'] ? '••••••••' : null, false]],
            'processes' => ['Name' => [$row['name'], false], 'Owner' => [$state->targetOwner($row), false], 'Node' => [$state->targetNodeName($row), false], 'Runtime' => [$row['runtime'], false], 'Working directory' => [$row['working_directory'] ?? null, false], 'Restart policy' => [$row['restart_policy'] ?? null, false], 'Desired state' => [$row['desired_state'], false], 'Runtime status' => [$row['runtime_status'], ! State::processHealthy($row)], 'CPU/MEM' => [$this->processUsage($row), false]],
            'schedules' => ['Name' => [$row['name'], false], 'Owner' => [$state->targetOwner($row), false], 'Node' => [$state->targetNodeName($row), false], 'Calendar' => [$row['calendar'], false], 'Timeout' => ["{$row['timeout_seconds']} s", false], 'Desired timer' => [$row['desired_timer_state'], $row['desired_timer_state'] !== 'enabled'], 'Status' => [$row['status'], $row['status'] === 'failed'], 'Last run' => [$row['last_run_at'] ?? 'never', false], 'Last run status' => [$row['last_run_status'] ?? '—', false]],
            'firewall' => ['Name' => [$row['name'], false], 'Port' => [$row['port'], false], 'Protocol' => [$row['protocol'], false], 'Action' => [$row['action'], false], 'Source' => [$row['source'], false], 'Status' => [$row['status'], ! State::firewallHealthy($row)], 'Node' => [$state->nodeName($row['node_id']), false]],
            'deployments' => ['Release' => [$row['release'], false], 'Branch' => [$row['branch'], false], 'Commit' => [$row['commit'], false], 'Started' => [$row['started'], false], 'Finished' => [$row['finished'], false], 'Duration' => [$row['duration'], false], 'Status' => [$row['status'], ! State::deploymentHealthy($row)], 'Failed step' => [$row['failed_step'], false], 'Error code' => [$row['error_code'], false], 'Selected release' => [$row['selected_release'], false], 'Triggered by' => [$row['by'], false]],
            default => [],
        };

        return array_map(static fn (array $p): array => [$value($p[0]), $p[1]], $properties);
    }

    /** @param array<string, mixed> $row */
    private function rowTitle(string $kind, array $row): string
    {
        return match ($kind) {
            'nodes' => $row['name'],
            'apps' => $row['slug'],
            'instances' => "{$row['app']['slug']}/{$row['name']}",
            'processes', 'schedules' => $row['name'],
            'databases' => $row['slug'],
            'firewall' => "{$row['port']}/{$row['protocol']} {$row['action']} {$row['source']}",
            'deployments' => "release {$row['release']}",
            default => '',
        };
    }

    // ---- node create form --------------------------------------------------------------------

    private function formPage(UiState $ui, Area $area): Widget
    {
        $form = $ui->form ?? throw new RuntimeException('No form is open.');
        $ui->drawn['back'] = ['area' => Area::fromScalars($area->left(), $area->top(), 10, 1), 'header' => false];

        if ($ui->creatingNode) {
            return GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(Constraint::length(1), Constraint::min(5))
                ->widgets(
                    ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(Span::styled('Create node', Style::default()->addModifier(Modifier::BOLD))))),
                    BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle(Style::default()->fg(AnsiColor::Cyan))
                        ->titles(Title::fromString(' node:add '))
                        ->padding(Padding::horizontal(1))
                        ->widget(ParagraphWidget::fromString('Adding node… (node:add provisions synchronously; this call blocks until the Gateway finishes or fails.)')),
                );
        }

        PanelTextPromptRenderer::$width = $area->width - 10;
        PanelMultiSelectPromptRenderer::$width = $area->width - 10;
        $lines = [];

        foreach ($form->prompts as $index => [, $prompt]) {
            $frame = rtrim($prompt->frame(), "\n");

            if ($index !== $form->active) {
                $frame = preg_replace('/\e\[7m(.*?)\e\[27m/', '$1', $frame) ?? $frame;
            }

            $top = $area->top() + 2 + count($lines);

            foreach (explode("\n", $frame) as $text) {
                $lines[] = AnsiLine::parse($text);
            }

            $ui->drawn["field:{$index}"] = ['area' => Area::fromScalars($area->left() + 1, $top, $area->width - 2, count($lines) - ($top - $area->top() - 2)), 'header' => false];
        }

        $lines[] = Line::fromString('');
        $onButton = $form->onButton();
        $ui->drawn['form:submit'] = ['area' => Area::fromScalars($area->left() + 3, $area->top() + 2 + count($lines), 15, 1), 'header' => false];
        $lines[] = Line::fromSpans(
            Span::styled('  [ Create node ]', $onButton ? Style::default()->addModifier(Modifier::REVERSED)->addModifier(Modifier::BOLD) : Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)),
            Span::styled($onButton ? '   Enter creates the node' : '   ↓ to reach it, or click', Style::default()->fg(AnsiColor::DarkGray)),
        );

        if ($form->error !== null) {
            $lines[] = Line::fromString('');
            $lines[] = Line::fromSpans(Span::styled($form->error, Style::default()->fg(AnsiColor::Red)));
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)), Span::styled('Create node', Style::default()->addModifier(Modifier::BOLD))))),
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle(Style::default()->fg(AnsiColor::Cyan))
                    ->titles(Title::fromString(' node:add '))
                    ->padding(Padding::horizontal(1))
                    ->widget(ParagraphWidget::fromText(Text::fromLines(...array_slice($lines, 0, max(1, $area->height - 4))))),
            );
    }

    // ---- shared widgets ----------------------------------------------------------------------

    /**
     * One htop-style bar: label, [|||||    ], and a reading; green, then yellow, then red past
     * the thresholds.
     *
     * @param  array{int, int}  $thresholds
     * @return list<Span>
     */
    private function bar(string $label, float $ratio, string $reading, int $width, array $thresholds = [60, 85]): array
    {
        $ratio = max(0, min(1, $ratio));
        $label = TerminalText::safe($label);
        $inner = max(4, $width - TerminalText::width($label) - TerminalText::width($reading) - 3);
        $filled = (int) round($ratio * $inner);
        $colour = match (true) {
            $ratio * 100 >= $thresholds[1] => AnsiColor::Red,
            $ratio * 100 >= $thresholds[0] => AnsiColor::Yellow,
            default => AnsiColor::Green,
        };

        return [
            Span::styled($label.'[', Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled(str_repeat('|', $filled), Style::default()->fg($colour)),
            Span::fromString(str_repeat(' ', $inner - $filled)),
            Span::styled('] ', Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled($reading, Style::default()->fg(AnsiColor::DarkGray)),
        ];
    }

    /** A small box over the screen listing the actions for the chosen row, at the pointer or in the middle. */
    private function menuPopup(UiState $ui, Area $area): Widget
    {
        $menu = $ui->menu ?? throw new RuntimeException('No menu is open.');

        if ($menu['confirm'] !== null) {
            return $menu['confirm']->widget($area);
        }

        $width = self::MENU_WIDTH;
        $labels = array_keys($menu['actions']);
        $lines = [];

        foreach ($labels as $index => $label) {
            $lines[] = Line::fromSpan(Span::styled(str_pad('  '.$label, $width - 2), $index === $menu['selected'] ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()));
        }

        $lines[] = Line::fromString(str_repeat(' ', $width - 2));
        $activeAction = $menu['actions'][$labels[$menu['selected']]];
        $description = $activeAction->description;
        $lines[] = Line::fromSpan(Span::styled(str_pad('  '.$description, $width - 2), Style::default()->fg(AnsiColor::DarkGray)));
        $height = count($lines) + 2;

        [$left, $top] = $menu['at'] ?? [intdiv($area->width - $width, 2), intdiv($area->height - $height, 2)];
        $left = max(0, min($left, $area->width - $width));
        $top = max(0, min($top, $area->height - $height));
        $ui->drawn['menu'] = ['area' => Area::fromScalars($left, $top, $width, $height), 'header' => false];

        $box = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD))
            ->titles(Title::fromString(" {$menu['title']} "))
            ->widget(ParagraphWidget::fromText(Text::fromLines(...$lines)));

        return BlockWidget::default()
            ->padding(Padding::fromScalars($left, max(0, $area->width - $width - $left), $top, max(0, $area->height - $height - $top)))
            ->widget($box);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<Constraint>  $widths
     * @param  list<TableRow>  $rows
     */
    private function pane(UiState $ui, string $name, string $title, array $headers, array $widths, array $rows, string $empty = 'None.'): Widget
    {
        $focused = $ui->focus === $name;
        $hovered = $ui->focus === null && $ui->hover === $name && $ui->form === null;
        $block = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle(match (true) {
                $focused => Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD),
                $hovered => Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD),
                default => Style::default()->fg(AnsiColor::DarkGray),
            });

        if ($title !== '') {
            $block = $block->titles(Title::fromString($title));
        }

        if ($rows === []) {
            return $block->widget(ParagraphWidget::fromString(' '.$empty)->style(Style::default()->fg(AnsiColor::DarkGray)));
        }

        $columns = TableColumns::resolve($ui->drawn[$name]['area']->width, $headers, $rows, $widths);
        if ($columns->widths === []) {
            $ui->drawn[$name]['ids'] = [];
            unset($ui->drawn[$name]['families'], $ui->drawn[$name]['table']);

            return $block->widget(ParagraphWidget::fromString(implode("\n", TerminalText::wrap("Needs {$columns->requiredWidth} columns. Resize to select.", max(1, $ui->drawn[$name]['area']->width - 2)))));
        }
        $lastWidth = $columns->widths[count($columns->widths) - 1];
        $rows = array_map(fn (TableRow $row): TableRow => $this->alignLast($row, $lastWidth), $rows);

        $table = TableWidget::default();
        $table->columnSpacing = 1;
        $ui->selected[$name] = max(0, min($ui->selected[$name] ?? 0, count($rows) - 1));
        $ui->drawn[$name]['table'] = $table->state;

        if ($headers !== []) {
            $table->header($this->alignLast(TableRow::fromStrings(...$headers), $lastWidth));
        }

        return $block->widget(
            $table
                ->widths(...$columns->constraints())
                ->rows(...$rows)
                ->select($ui->selected[$name])
                ->highlightSymbol('› ')
                ->highlightStyle($focused ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()->addModifier(Modifier::BOLD)),
        );
    }

    /** @param list<array<string, mixed>> $records */
    private function paneRecords(UiState $ui, string $name, array $records): void
    {
        $ui->drawn[$name]['kind'] = $ui->kindOf($name);
        $ui->drawn[$name]['ids'] = in_array($name, ['deploysteps', 'tables', 'users'], true)
            ? array_keys($records)
            : array_column($records, 'id');
    }

    private function alignLast(TableRow $row, int $width): TableRow
    {
        $cells = [];

        for ($i = 0; ($cell = $row->getCell($i)) !== null; $i++) {
            $cells[] = $cell;
        }

        if ($cells === [] || $width <= 0) {
            return $row;
        }

        $last = array_pop($cells);
        $text = TableColumns::text($last);
        $room = $width - 1;
        $aligned = TableCell::fromString(TerminalText::width($text) >= $room ? $text : str_repeat(' ', $room - TerminalText::width($text)).$text.' ');
        $aligned->style = $last->style;

        return TableRow::fromCells(...[...$cells, $aligned]);
    }

    /**
     * A Process's live CPU and memory as one reading, e.g. `20%/1.2G`: whole-percent CPU (matching
     * the node bars' `%3.0f%%`) over memory in GiB at one decimal (matching the node metrics
     * panel's `%.1fG`, which already shows sub-1 values this way — see nodeSummaryRow()). Either
     * field null (not running, or cAdvisor has no series for it) collapses to a single dash rather
     * than `—/—`.
     *
     * @param  array<string, mixed>  $process
     */
    private function processUsage(array $process): string
    {
        $cpu = $process['cpu'] ?? null;
        $memoryBytes = $process['memory_bytes'] ?? null;

        if (! is_numeric($cpu) || ! is_numeric($memoryBytes)) {
            return '—';
        }

        // Two decimals, because a Process's memory is small enough that one rounds most of the
        // fleet to the same 0.0G: the node columns measure whole machines, these measure one unit.
        return sprintf('%.0f%%/%.2fGB', $cpu * 100, $memoryBytes / self::BYTES_PER_GIB);
    }

    /**
     * A row that turns yellow when the record needs a look: yellow means "inspect".
     *
     * @param  list<string>  $cells
     */
    private function row(array $cells, string $last, bool $warn): TableRow
    {
        return TableRow::fromCells(...array_map(fn (string $cell): TableCell => $this->cell($cell, $warn), [...$cells, $last]));
    }

    private function cell(string $text, bool $warn): TableCell
    {
        return $this->styledCell($text, $warn ? Style::default()->fg(AnsiColor::Yellow) : Style::default());
    }

    /**
     * A plain-text cell styled as a whole cell rather than by span, so `alignLast()` (which
     * flattens a right-aligned last cell's spans into one string) keeps its color.
     */
    private function styledCell(string $text, Style $style): TableCell
    {
        $cell = TableCell::fromString(TerminalText::safe($text));
        $cell->style = $style;

        return $cell;
    }
}
