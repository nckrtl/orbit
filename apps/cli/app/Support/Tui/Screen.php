<?php

declare(strict_types=1);

namespace App\Support\Tui;

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
 * Screen never mutates State or UiState except to record where it drew each pane in
 * `UiState::$drawn` and `UiState::$paneOrder`, which Interaction uses for keyboard and mouse
 * hit-testing on the next event.
 */
final class Screen
{
    private const int NAV_WIDTH = 16;

    private const int MENU_WIDTH = 40;

    public function screen(State $state, UiState $ui, string $header, string $footer, Area $area): Widget
    {
        $ui->drawn = [];
        $ui->paneOrder = [];
        $dim = Style::default()->fg(AnsiColor::DarkGray);

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
                    ->widgets($this->nav($ui), $body),
                ParagraphWidget::fromString($footer)->style($dim),
            );

        return $ui->menu === null ? $screen : CompositeWidget::fromWidgets($screen, $this->menuPopup($ui, $area));
    }

    private function nav(UiState $ui): Widget
    {
        $hovered = $ui->hover === 'nav' && $ui->focus === null && $ui->form === null;
        $rows = [];

        foreach (UiState::SECTIONS as $title) {
            $rows[] = TableRow::fromStrings($title);
        }

        $table = TableWidget::default()
            ->widths(Constraint::percentage(96))
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
            foreach (['node', 'app'] as $filter) {
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
        $rows = $state->listRows($ui->section, $ui->filters['node'], $ui->filters['app']);

        return match ($ui->section) {
            'nodes' => [
                ['Name', 'Status', 'Roles', 'WireGuard IP', 'Instances'],
                [Constraint::percentage(20), Constraint::percentage(14), Constraint::percentage(24), Constraint::percentage(20), Constraint::percentage(22)],
                array_map(fn (array $n): TableRow => $this->row([$n['name'], $n['status'], implode(', ', $n['roles']), $n['wireguard_ip'] ?? '—'], (string) count($state->instancesForNode($n['name'])), ! State::nodeHealthy($n)), $rows),
            ],
            'apps' => [
                ['Slug', 'Name', 'Default branch', 'Instances'],
                [Constraint::percentage(24), Constraint::percentage(34), Constraint::percentage(22), Constraint::percentage(20)],
                array_map(fn (array $a): TableRow => $this->row([$a['slug'], $a['name'], $a['default_branch'] ?? 'main'], (string) count($state->instancesForApp($a['slug'])), false), $rows),
            ],
            'instances' => [
                ['App', 'Name', 'Environment', 'Node', 'Domain', 'Status'],
                [Constraint::percentage(18), Constraint::percentage(14), Constraint::percentage(14), Constraint::percentage(12), Constraint::percentage(28), Constraint::percentage(10)],
                array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['node']['name'], $i['domain'] ?? '—'], $i['status'], ! State::instanceHealthy($i)), $rows),
            ],
            'processes' => [
                ['Name', 'Owner', 'Node', 'Runtime', 'Status'],
                [Constraint::percentage(20), Constraint::percentage(30), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(16)],
                array_map(fn (array $p): TableRow => $this->row([$p['name'], $state->processOwner($p), $state->processNodeName($p), $p['runtime']], $p['runtime_status'], ! State::processHealthy($p)), $rows),
            ],
            'schedules' => [
                ['Name', 'Instance', 'Node', 'Calendar', 'Last run'],
                [Constraint::percentage(18), Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(30), Constraint::percentage(20)],
                array_map(fn (array $s): TableRow => $this->row([$s['name'], $state->instanceName($s['target_id']), $state->instanceNodeName($s['target_id']), $s['calendar']], $s['last_run_status'] ?? 'never', ! State::scheduleHealthy($s)), $rows),
            ],
            'databases' => [
                ['Slug', 'Driver', 'Node', 'Host', 'Database'],
                [Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(16), Constraint::percentage(28), Constraint::percentage(24)],
                array_map(fn (array $d): TableRow => $this->row([$d['slug'], $d['driver'], $state->nodeName((int) $d['node_id'])], $d['host'] !== null ? "{$d['host']}:{$d['port']}" : (string) ($d['path'] ?? '—'), false), $rows),
            ],
            default => [[], [], []],
        };
    }

    /**
     * The dashboard: counts, one compact table row per node, and everything that needs a look.
     * The node table is sized to its content (one line per node, plus its border and header) so
     * "Needs attention" keeps the rest of the screen, with its own selection-following scroll,
     * regardless of how many nodes the fleet has.
     */
    private function dashboard(State $state, UiState $ui, Area $area): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $nodesHeight = count($state->nodes) + 3;
        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(3), Constraint::length($nodesHeight), Constraint::min(5)])->split($area);
        $ui->drawn['attention'] = ['area' => $split->get(2), 'header' => true];
        $ui->paneOrder = ['attention'];

        $attention = array_map(fn (array $a): TableRow => $this->row([$a['label'], $a['name'], $a['where']], $a['state'], true), $state->attentionRows());

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(3), Constraint::length($nodesHeight), Constraint::min(5))
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)
                    ->widget(ParagraphWidget::fromText(Text::fromLines($this->stats($state, $area->width - 2)))),
                $this->nodeSummaryTable($state, $split->get(1)),
                $this->pane($ui, 'attention', ' Needs attention ', ['Kind', 'Name', 'Where', 'State'], [Constraint::percentage(12), Constraint::percentage(32), Constraint::percentage(26), Constraint::percentage(28)], $attention, 'Nothing needs attention.'),
            );
    }

    /** One line per node: name, status, cpu/mem/disk bars, and uptime; a yellow row means the node needs a look. */
    private function nodeSummaryTable(State $state, Area $area): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $widths = [Constraint::percentage(14), Constraint::percentage(9), Constraint::percentage(21), Constraint::percentage(21), Constraint::percentage(19), Constraint::percentage(16)];
        $columns = $this->columnWidths($area, $widths);
        $lastWidth = $columns[count($columns) - 1] ?? 0;

        $table = TableWidget::default();
        $table->columnSpacing = 1;
        $table->header($this->alignLast(TableRow::fromStrings('Name', 'Status', 'CPU', 'Mem', 'Disk', 'Uptime'), $lastWidth));
        $table->widths(...$widths)->rows(...array_map(fn (array $node): TableRow => $this->alignLast($this->nodeSummaryRow($state, $node, $columns), $lastWidth), $state->nodes));

        return BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)->widget($table);
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
        $name = TableCell::fromLine(Line::fromSpan(Span::styled($node['name'], $textStyle)));
        $status = TableCell::fromLine(Line::fromSpan(Span::styled($node['status'], $textStyle)));
        $metrics = $state->nodeMetrics($node['id']);

        if ($metrics === null) {
            $blank = TableCell::fromLine(Line::fromSpan(Span::styled('—', $dim)));

            return TableRow::fromCells($name, $status, $blank, $blank, $blank, $this->styledCell('No metrics.', $dim));
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

    /** Counts for the whole network, a count in yellow when something in it needs a look. */
    private function stats(State $state, int $width): Line
    {
        $segments = $state->counts();
        $texts = array_map(fn (string $label) => "{$label} {$segments[$label][0]}", array_keys($segments));
        $slack = max(0, $width - 2 - array_sum(array_map(strlen(...), $texts)));
        $gaps = max(1, count($texts) - 1);
        $spans = [Span::fromString(' ')];
        $index = 0;

        foreach ($segments as $label => [$count, $warn]) {
            if ($index > 0) {
                $spans[] = Span::fromString(str_repeat(' ', intdiv($slack * $index, $gaps) - intdiv($slack * ($index - 1), $gaps)));
            }
            $spans[] = Span::styled($texts[$index], $warn > 0 ? Style::default()->fg(AnsiColor::Yellow) : Style::default());
            $index++;
        }

        return Line::fromSpans(...$spans);
    }

    /** One record: crumbs, properties, and the panes the family has. */
    private function recordPage(State $state, UiState $ui, Area $area): Widget
    {
        $page = $ui->page() ?? throw new RuntimeException('No page is open.');
        $kind = $page['kind'];
        $row = $page['row'];
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(5)])->split($area);
        $body = $split->get(1);
        $ui->drawn['back'] = ['area' => Area::fromScalars($split->get(0)->left(), $split->get(0)->top(), 10, 1), 'header' => false];

        $label = match ($kind) {
            'nodes' => 'Node',
            'apps' => 'App',
            'instances' => 'App instance',
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
            Span::styled($this->rowTitle($kind, $row), Style::default()->addModifier(Modifier::BOLD)),
        )));

        $hasSubPanes = in_array($kind, ['nodes', 'apps', 'instances', 'databases'], true);
        $propertiesWidth = $hasSubPanes ? intdiv($body->width * 40, 100) : $body->width;
        $propertyRows = [];
        $index = 0;

        foreach ($this->properties($state, $kind, $row) as $name => [$value, $warn]) {
            $link = in_array($name, ['App', 'Node'], true) && $value !== '—';

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
        $instances = $state->instancesForNode($node['name']);
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

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                    ->widgets($properties, $this->nodeMetricsPanel($state, $node, $topColumns->get(1)->width)),
                $this->pane($ui, 'instances', ' Instances on this node ', ['App', 'Name', 'Environment', 'Domain', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(32), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['domain'] ?? '—'], $i['status'], ! State::instanceHealthy($i)), $instances)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                    ->widgets(
                        $this->pane($ui, 'processes', ' Node processes ', ['Name', 'Runtime', 'Status'], [Constraint::percentage(46), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], ! State::processHealthy($p)), $state->processesForNode($node['id']))),
                        $this->pane($ui, 'firewall', ' Firewall ', ['Port', 'Action', 'Source', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(40), Constraint::percentage(18)], array_map(fn (array $f): TableRow => $this->row(["{$f['port']}/{$f['protocol']}", $f['action'], $f['source']], $f['status'], ! State::firewallHealthy($f)), $state->firewallForNode($node['id']))),
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

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                $properties,
                $this->pane($ui, 'instances', ' Instances ', ['Name', 'Environment', 'Node', 'Domain', 'Status'], [Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(40), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['name'], $i['environment'], $i['node']['name'], $i['domain'] ?? '—'], $i['status'], ! State::instanceHealthy($i)), $instances)),
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
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(26), Constraint::percentage(34)])->split($rows->get(0));
        $ui->drawn['processes'] = ['area' => $columns->get(1), 'header' => true];
        $ui->drawn['schedules'] = ['area' => $columns->get(2), 'header' => true];
        $ui->drawn['deploysteps'] = ['area' => $rows->get(1), 'header' => true];
        $ui->paneOrder = ['processes', 'schedules', 'deploysteps'];

        $deploymentsWidget = $deployments === null
            ? BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Deployments '))->borderStyle($dim)->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString('Deployment history unavailable right now.')->style($dim))
            : $this->pane($ui, 'deployments', ' Deployments ', ['Started', 'Release', 'Branch', 'Commit', 'By', 'Duration', 'Status'], [Constraint::percentage(16), Constraint::percentage(18), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(14)], array_map(fn (array $d): TableRow => $this->row([$d['started'], $d['release'], $d['branch'], $d['commit'], $d['by'], $d['duration']], $d['status'], ! State::deploymentHealthy($d)), $deployments), 'Not deployed yet.');

        if ($deployments !== null) {
            $ui->drawn['deployments'] = ['area' => $rows->get(2), 'header' => true];
            $ui->paneOrder[] = 'deployments';
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(26), Constraint::percentage(34))
                    ->widgets(
                        $properties,
                        $this->pane($ui, 'processes', ' Processes ', ['Name', 'Runtime', 'Status'], [Constraint::percentage(46), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], ! State::processHealthy($p)), $processes)),
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

        $usersWidget = $users === null
            ? BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Users '))->borderStyle($dim)->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString('Database users unavailable right now.')->style($dim))
            : $this->pane($ui, 'users', ' Users ', ['Username', 'Privileges', 'Created by'], [Constraint::percentage(24), Constraint::percentage(46), Constraint::percentage(30)], array_map(fn (array $u): TableRow => $this->row([$u['username'], $u['privileges']], $u['created_by'], false), $users), 'No users recorded.');

        if ($users !== null) {
            $ui->drawn['users'] = ['area' => $columns->get(1), 'header' => true];
            $ui->paneOrder[] = 'users';
        }

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
            'instances' => ['Name' => [$row['name'], false], 'App' => [$row['app']['slug'], false], 'Node' => [$row['node']['name'], false], 'Environment' => [$row['environment'], false], 'Domain' => [$row['domain'] ?? null, false], 'Status' => [$row['status'], ! State::instanceHealthy($row)], 'Checkout' => [$row['checkout_path'] ?? null, false], 'Selected branch' => [$row['selected_branch'] ?? null, false], 'Deploy steps' => [count($row['deploy_steps']).' steps', false]],
            'databases' => ['Slug' => [$row['slug'], false], 'Driver' => [$row['driver'], false], 'Node' => [$state->nodeName((int) ($row['node_id'] ?? 0)), false], 'Host' => [$row['host'] !== null ? "{$row['host']}:{$row['port']}" : null, false], 'Path' => [$row['path'] ?? null, false], 'Database' => [$row['database'] ?? null, false], 'Username' => [$row['username'] ?? null, false], 'Password' => [$row['has_password'] ? '••••••••' : null, false]],
            'processes' => ['Name' => [$row['name'], false], 'Owner' => [$state->processOwner($row), false], 'Node' => [$state->processNodeName($row), false], 'Runtime' => [$row['runtime'], false], 'Working directory' => [$row['working_directory'] ?? null, false], 'Restart policy' => [$row['restart_policy'] ?? null, false], 'Desired state' => [$row['desired_state'], false], 'Runtime status' => [$row['runtime_status'], ! State::processHealthy($row)]],
            'schedules' => ['Name' => [$row['name'], false], 'Instance' => [$state->instanceName($row['target_id']), false], 'Node' => [$state->instanceNodeName($row['target_id']), false], 'Calendar' => [$row['calendar'], false], 'Timeout' => ["{$row['timeout_seconds']} s", false], 'Desired timer' => [$row['desired_timer_state'], $row['desired_timer_state'] !== 'enabled'], 'Status' => [$row['status'], $row['status'] === 'failed'], 'Last run' => [$row['last_run_at'] ?? 'never', false], 'Last run status' => [$row['last_run_status'] ?? '—', false]],
            'firewall' => ['Name' => [$row['name'], false], 'Port' => [$row['port'], false], 'Protocol' => [$row['protocol'], false], 'Action' => [$row['action'], false], 'Source' => [$row['source'], false], 'Status' => [$row['status'], ! State::firewallHealthy($row)], 'Node' => [$row['node'], false]],
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
        $inner = max(4, $width - strlen($label) - strlen($reading) - 3);
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
        $width = self::MENU_WIDTH;
        $labels = array_keys($menu['actions']);
        $lines = [];

        foreach ($labels as $index => $label) {
            $lines[] = Line::fromSpan(Span::styled(str_pad('  '.$label, $width - 2), $index === $menu['selected'] ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()));
        }

        $lines[] = Line::fromString(str_repeat(' ', $width - 2));
        $activeAction = $menu['actions'][$labels[$menu['selected']]];
        $description = $menu['confirm'] ? "Confirm? {$activeAction->description} (y/N)" : $activeAction->description;
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

        $lastWidth = $this->lastColumnWidth($ui, $name, $widths);
        $rows = array_map(fn (TableRow $row): TableRow => $this->alignLast($row, $lastWidth), $rows);

        $table = TableWidget::default();
        $table->columnSpacing = 1;

        if ($headers !== []) {
            $table->header($this->alignLast(TableRow::fromStrings(...$headers), $lastWidth));
        }

        return $block->widget(
            $table
                ->widths(...$widths)
                ->rows(...$rows)
                ->select(min($ui->selected[$name] ?? 0, count($rows) - 1))
                ->highlightSymbol('› ')
                ->highlightStyle($focused ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()->addModifier(Modifier::BOLD)),
        );
    }

    /** @param list<Constraint> $widths */
    private function lastColumnWidth(UiState $ui, string $pane, array $widths): int
    {
        $area = $ui->drawn[$pane]['area'] ?? null;

        if ($area === null || $widths === []) {
            return 0;
        }

        $columns = $this->columnWidths($area, $widths);

        return $columns[count($columns) - 1];
    }

    /**
     * The rendered pixel width of every column a `TableWidget` with these width constraints
     * would give them inside $area, mirroring the borders, selector gutter, and one-cell
     * column spacing `pane()` and `TableWidget` apply. Used wherever a cell's content (a bar,
     * for example) needs to fit its column exactly rather than truncate or leave slack.
     *
     * @param  list<Constraint>  $widths
     * @return list<int>
     */
    private function columnWidths(Area $area, array $widths): array
    {
        if ($widths === []) {
            return [];
        }

        $constraints = [Constraint::length(2)];

        foreach ($widths as $width) {
            $constraints[] = $width;
            $constraints[] = Constraint::length(1);
        }

        array_pop($constraints);
        $chunks = Layout::default()->direction(Direction::Horizontal)->constraints($constraints)->split(Area::fromDimensions(max(1, $area->width - 2), 1));

        $columns = [];

        for ($index = 1; $index < count($constraints); $index += 2) {
            $columns[] = $chunks->get($index)->width;
        }

        return $columns;
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
        $text = implode('', array_map(fn (Line $line): string => implode('', array_map(fn (Span $span): string => $span->content, iterator_to_array($line))), $last->content->lines));
        $room = $width - 1;
        $aligned = TableCell::fromString(mb_strlen($text) >= $room ? $text : str_repeat(' ', $room - mb_strlen($text)).$text.' ');
        $aligned->style = $last->style;

        return TableRow::fromCells(...[...$cells, $aligned]);
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
        $cell = TableCell::fromString($text);
        $cell->style = $style;

        return $cell;
    }
}
