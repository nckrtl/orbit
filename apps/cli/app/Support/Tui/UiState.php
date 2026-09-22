<?php

declare(strict_types=1);

namespace App\Support\Tui;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\Table\TableState;

/**
 * Everything about the screen that is not fleet data: which section and page are open, hover
 * and focus, the open actions menu or node form, and where the last frame drew each pane (for
 * mouse hit-testing). Screen reads and populates it every frame; Input mutates it on every key
 * or click.
 */
final class UiState
{
    public const array SECTIONS = [
        'dashboard' => 'Dashboard',
        'nodes' => 'Nodes',
        'apps' => 'Projects',
        'instances' => 'Instances',
        'processes' => 'Processes',
        'schedules' => 'Schedules',
        'databases' => 'Databases',
        'firewall' => 'Firewall',
    ];

    public string $section = 'dashboard';

    /** The pane the arrows point at while hovering ('nav' or a page pane), and the pane Enter focused. */
    public string $hover = 'nav';

    public ?string $focus = null;

    /** @var array<string, int> */
    public array $selected = [];

    /**
     * Open record pages, last on top; empty means the section's own view.
     *
     * @var list<array{kind: string, row: array<string, mixed>}>
     */
    public array $pages = [];

    /** @var array{node: string|null, project: string|null} */
    public array $filters = ['node' => null, 'project' => null];

    /** @var array{kind: string, title: string, row: array<string, mixed>, actions: array<string, Action>, selected: int, confirm: Confirmation|null, at: array{int, int}|null}|null */
    public ?array $menu = null;

    public ?NodeFormState $form = null;

    /**
     * True for exactly one drawn frame while the form's submit button runs the blocking
     * `node:add` request: `node:add` provisions synchronously and its response already
     * confirms the final status (see `NodeOutput::mutationState()`), so there is no separate
     * provisioning phase to poll or stream; the form only needs to say the call is in flight.
     */
    public bool $creatingNode = false;

    /** The last footer message: an action's outcome, or the command a "leaves the TUI" action printed. */
    public string $message = '';

    /**
     * Where each pane, link, and button was drawn in the last frame, for mouse hit-testing.
     *
     * Table panes also carry the ordered identities and the renderer's actual scroll state.
     *
     * @var array<string, array{area: Area, header: bool, kind?: string, ids?: list<int|string>, families?: list<string>, table?: TableState}>
     */
    public array $drawn = [];

    /** @var list<string> */
    public array $paneOrder = [];

    /**
     * Retain only the renderer's offset; identities and bounds always come from the new frame.
     *
     * @param  array<string, array{area: Area, header: bool, kind?: string, ids?: list<int|string>, families?: list<string>, table?: TableState}>  $previous
     */
    public function retainTableOffsets(array $previous): void
    {
        foreach ($this->drawn as $name => $drawn) {
            if (isset($drawn['table'], $previous[$name]['table']) && ($drawn['kind'] ?? null) === ($previous[$name]['kind'] ?? null)) {
                $drawn['table']->offset = $previous[$name]['table']->offset;
            }
        }
    }

    /** @return array{kind: string, row: array<string, mixed>}|null */
    public function page(): ?array
    {
        return $this->pages[count($this->pages) - 1] ?? null;
    }

    public function hasFilters(): bool
    {
        return $this->pages === [] && in_array($this->section, ['instances', 'processes', 'schedules'], true);
    }

    public function goTo(string $section): void
    {
        $this->section = $section;
        $this->pages = [];
        $this->form = null;
        $this->focus = null;
        $this->hover = 'nav';
        $this->filters = ['node' => null, 'project' => null];
        $this->drawn = [];
        $this->paneOrder = [];
    }

    /** @param array<string, mixed> $row */
    public function open(string $kind, array $row): void
    {
        $this->pages[] = ['kind' => $kind, 'row' => $row];
        $this->focus = null;
        $this->hover = 'nav';
        $this->drawn = [];
        $this->paneOrder = [];
    }

    public function back(): void
    {
        if ($this->form !== null) {
            $this->form = null;

            return;
        }
        array_pop($this->pages);
        $this->focus = null;
        $this->drawn = [];
        $this->paneOrder = [];
    }

    /** Which record family a pane's rows belong to. */
    public function kindOf(string $pane): string
    {
        return $pane === 'list' ? $this->section : $pane;
    }
}
