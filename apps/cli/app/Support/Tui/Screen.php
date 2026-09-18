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
                array_map(fn (array $n): TableRow => $this->row([$n['name'], $n['status'], implode(', ', $n['roles']), $n['wireguard_ip'] ?? '—'], (string) count($state->instancesForNode($n['name'])), $n['status'] !== 'active'), $rows),
            ],
            'apps' => [
                ['Slug', 'Name', 'Default branch', 'Instances'],
                [Constraint::percentage(24), Constraint::percentage(34), Constraint::percentage(22), Constraint::percentage(20)],
                array_map(fn (array $a): TableRow => $this->row([$a['slug'], $a['name'], $a['default_branch'] ?? 'main'], (string) count($state->instancesForApp($a['slug'])), false), $rows),
            ],
            'instances' => [
                ['App', 'Name', 'Environment', 'Node', 'Domain', 'Status'],
                [Constraint::percentage(18), Constraint::percentage(14), Constraint::percentage(14), Constraint::percentage(12), Constraint::percentage(28), Constraint::percentage(10)],
                array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['node']['name'], $i['domain'] ?? '—'], $i['status'], $i['status'] !== 'active'), $rows),
            ],
            'processes' => [
                ['Name', 'Owner', 'Node', 'Runtime', 'Status'],
                [Constraint::percentage(20), Constraint::percentage(30), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(16)],
                array_map(fn (array $p): TableRow => $this->row([$p['name'], $state->processOwner($p), $state->processNodeName($p), $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $rows),
            ],
            'schedules' => [
                ['Name', 'Instance', 'Node', 'Calendar', 'Last run'],
                [Constraint::percentage(18), Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(30), Constraint::percentage(20)],
                array_map(fn (array $s): TableRow => $this->row([$s['name'], $state->instanceName($s['target_id']), $state->instanceNodeName($s['target_id']), $s['calendar']], $s['last_run_status'] ?? 'never', $s['desired_timer_state'] !== 'enabled'), $rows),
            ],
            'databases' => [
                ['Slug', 'Driver', 'Node', 'Host', 'Database'],
                [Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(16), Constraint::percentage(28), Constraint::percentage(24)],
                array_map(fn (array $d): TableRow => $this->row([$d['slug'], $d['driver'], $state->nodeName((int) $d['node_id'])], $d['host'] !== null ? "{$d['host']}:{$d['port']}" : (string) ($d['path'] ?? '—'), false), $rows),
            ],
            default => [[], [], []],
        };
    }

    /** The dashboard: counts, one compact metrics line per node, and everything that needs a look. */
    private function dashboard(State $state, UiState $ui, Area $area): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $nodeBlocks = count($state->nodes) * 3;
        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(3), Constraint::length($nodeBlocks), Constraint::min(5)])->split($area);
        $ui->drawn['attention'] = ['area' => $split->get(2), 'header' => true];
        $ui->paneOrder = ['attention'];

        $nodeWidgets = [];

        foreach ($state->nodes as $node) {
            $nodeWidgets[] = $this->nodeSummaryBlock($state, $node, $area->width - 2);
        }

        $attention = array_map(fn (array $a): TableRow => $this->row([$a['label'], $a['name'], $a['where']], $a['state'], true), $state->attentionRows());

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(3), Constraint::length($nodeBlocks), Constraint::min(5))
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)
                    ->widget(ParagraphWidget::fromText(Text::fromLines($this->stats($state, $area->width - 2)))),
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(...array_fill(0, max(1, count($nodeWidgets)), Constraint::length(3)))
                    ->widgets(...$nodeWidgets),
                $this->pane($ui, 'attention', ' Needs attention ', ['Kind', 'Name', 'Where', 'State'], [Constraint::percentage(12), Constraint::percentage(32), Constraint::percentage(26), Constraint::percentage(28)], $attention, 'Nothing needs attention.'),
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
                ->borderStyle($node['status'] === 'active' ? $dim : Style::default()->fg(AnsiColor::Yellow))
                ->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString('Metrics not available on this Gateway yet.')->style($dim));
        }

        $inner = $width - 4;
        $third = intdiv($inner - 4, 3);
        $cpu = array_sum($metrics['cores']) / max(1, count($metrics['cores']));
        [$mount, $used, $total] = $metrics['disks'][0] ?? ['/', 0.0, 0.0];

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$node['name']} · {$node['status']} · up {$metrics['uptime']} "))
            ->borderStyle($node['status'] === 'active' ? $dim : Style::default()->fg(AnsiColor::Yellow))
            ->padding(Padding::horizontal(1))
            ->widget(ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(...[
                ...$this->bar('cpu', $cpu, sprintf('%3.0f%%', $cpu * 100), $third),
                Span::fromString('  '),
                ...$this->bar('mem', $metrics['mem'][1] > 0 ? $metrics['mem'][0] / $metrics['mem'][1] : 0, sprintf('%.1fG/%.0fG', $metrics['mem'][0], $metrics['mem'][1]), $third),
                Span::fromString('  '),
                ...$this->bar(str_pad((string) $mount, 3), $total > 0 ? $used / $total : 0, sprintf('%.0fG/%.0fG', $used, $total), $inner - 2 * $third - 4, [80, 90]),
            ]))));
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

        foreach ($this->properties($state, $kind, $row) as $name => $value) {
            $warn = in_array($name, ['Runtime status', 'Status'], true) && ! in_array($value, ['active', 'running', 'enabled', 'applied', 'succeeded'], true);
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
        $metricsHeight = 3;
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
                    ->widgets($properties, $this->nodeSummaryBlock($state, $node, $topColumns->get(1)->width)),
                $this->pane($ui, 'instances', ' Instances on this node ', ['App', 'Name', 'Environment', 'Domain', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(32), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['domain'] ?? '—'], $i['status'], $i['status'] !== 'active'), $instances)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                    ->widgets(
                        $this->pane($ui, 'processes', ' Node processes ', ['Name', 'Runtime', 'Status'], [Constraint::percentage(46), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $state->processesForNode($node['id']))),
                        $this->pane($ui, 'firewall', ' Firewall ', ['Port', 'Action', 'Source', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(40), Constraint::percentage(18)], array_map(fn (array $f): TableRow => $this->row(["{$f['port']}/{$f['protocol']}", $f['action'], $f['source']], $f['status'], $f['status'] !== 'applied'), $state->firewallForNode($node['id']))),
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
                $this->pane($ui, 'instances', ' Instances ', ['Name', 'Environment', 'Node', 'Domain', 'Status'], [Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(40), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['name'], $i['environment'], $i['node']['name'], $i['domain'] ?? '—'], $i['status'], $i['status'] !== 'active'), $instances)),
                $this->pane($ui, 'schedules', ' Schedules ', ['Name', 'Instance', 'Calendar', 'Last run'], [Constraint::percentage(20), Constraint::percentage(22), Constraint::percentage(36), Constraint::percentage(20)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $state->instanceName($s['target_id']), $s['calendar']], $s['last_run_status'] ?? 'never', $s['desired_timer_state'] !== 'enabled'), $schedules)),
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
                ->widget(ParagraphWidget::fromString('Not available on this Gateway yet.')->style($dim))
            : $this->pane($ui, 'deployments', ' Deployments ', ['Started', 'Release', 'Branch', 'Commit', 'By', 'Duration', 'Status'], [Constraint::percentage(16), Constraint::percentage(18), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(12), Constraint::percentage(14)], array_map(fn (array $d): TableRow => $this->row([$d['started'], $d['release'], $d['branch'], $d['commit'], $d['by'], $d['duration']], $d['status'], $d['status'] !== 'succeeded'), $deployments), 'Not deployed yet.');

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
                        $this->pane($ui, 'processes', ' Processes ', ['Name', 'Runtime', 'Status'], [Constraint::percentage(46), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $processes)),
                        $this->pane($ui, 'schedules', ' Schedules ', ['Name', 'Calendar', 'Last run'], [Constraint::percentage(30), Constraint::percentage(42), Constraint::percentage(24)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $s['calendar']], $s['last_run_status'] ?? 'never', $s['desired_timer_state'] !== 'enabled'), $schedules)),
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
                ->widget(ParagraphWidget::fromString('Not available on this Gateway yet.')->style($dim))
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
     * The properties a page lists, named as the show commands name them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
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
            'nodes' => ['Name' => $row['name'], 'Status' => $row['status'], 'Roles' => $row['roles'], 'Platform' => $row['platform'] ?? null, 'Architecture' => $row['architecture'] ?? null, 'TLD' => $row['tld'] ?? null, 'WireGuard IP' => $row['wireguard_ip'] ?? null, 'SSH' => "{$row['user']}@{$row['public_ssh_host']}:{$row['public_ssh_port']}"],
            'apps' => ['Name' => $row['name'], 'Slug' => $row['slug'], 'Repository' => $row['repository_url'] ?? null, 'Default branch' => $row['default_branch'] ?? null, 'Root' => $row['root'] ?? null],
            'instances' => ['Name' => $row['name'], 'App' => $row['app']['slug'], 'Node' => $row['node']['name'], 'Environment' => $row['environment'], 'Domain' => $row['domain'] ?? null, 'Status' => $row['status'], 'Checkout' => $row['checkout_path'] ?? null, 'Selected branch' => $row['selected_branch'] ?? null, 'Deploy steps' => count($row['deploy_steps']).' steps'],
            'databases' => ['Slug' => $row['slug'], 'Driver' => $row['driver'], 'Node' => $state->nodeName((int) ($row['node_id'] ?? 0)), 'Host' => $row['host'] !== null ? "{$row['host']}:{$row['port']}" : null, 'Path' => $row['path'] ?? null, 'Database' => $row['database'] ?? null, 'Username' => $row['username'] ?? null, 'Password' => $row['has_password'] ? '••••••••' : null],
            'processes' => ['Name' => $row['name'], 'Owner' => $state->processOwner($row), 'Node' => $state->processNodeName($row), 'Runtime' => $row['runtime'], 'Working directory' => $row['working_directory'] ?? null, 'Restart policy' => $row['restart_policy'] ?? null, 'Desired state' => $row['desired_state'], 'Runtime status' => $row['runtime_status']],
            'schedules' => ['Name' => $row['name'], 'Instance' => $state->instanceName($row['target_id']), 'Node' => $state->instanceNodeName($row['target_id']), 'Calendar' => $row['calendar'], 'Timeout' => "{$row['timeout_seconds']} s", 'Desired timer' => $row['desired_timer_state'], 'Status' => $row['status'], 'Last run' => $row['last_run_at'] ?? 'never', 'Last run status' => $row['last_run_status'] ?? '—'],
            'firewall' => ['Name' => $row['name'], 'Port' => $row['port'], 'Protocol' => $row['protocol'], 'Action' => $row['action'], 'Source' => $row['source'], 'Status' => $row['status'], 'Node' => $row['node']],
            'deployments' => ['Release' => $row['release'], 'Branch' => $row['branch'], 'Commit' => $row['commit'], 'Started' => $row['started'], 'Finished' => $row['finished'], 'Duration' => $row['duration'], 'Status' => $row['status'], 'Failed step' => $row['failed_step'], 'Error code' => $row['error_code'], 'Selected release' => $row['selected_release'], 'Triggered by' => $row['by']],
            default => [],
        };

        return array_map($value, $properties);
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

        $constraints = [Constraint::length(2)];

        foreach ($widths as $width) {
            $constraints[] = $width;
            $constraints[] = Constraint::length(1);
        }

        array_pop($constraints);
        $chunks = Layout::default()->direction(Direction::Horizontal)->constraints($constraints)->split(Area::fromDimensions(max(1, $area->width - 2), 1));

        return $chunks->get(count($constraints) - 1)->width;
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
        $cell = TableCell::fromString($text);
        $cell->style = $warn ? Style::default()->fg(AnsiColor::Yellow) : Style::default();

        return $cell;
    }
}
