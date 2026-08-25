<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Command\Command;

it('exposes only the implemented Orbit product commands', function (): void {
    $visibleCommands = collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden())
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($visibleCommands)->toBe([
        'app:list',
        'app:new',
        'app:remove',
        'app:show',
        'gateway:add',
        'gateway:status',
        'gateway:use',
        'instance:list',
        'instance:new',
        'instance:php',
        'instance:remove',
        'instance:show',
        'node:list',
        'node:provision',
        'node:show',
        'workspace:list',
        'workspace:new',
        'workspace:php',
        'workspace:remove',
        'workspace:show',
    ]);
});

it('keeps the hidden Boost MCP entrypoint available to coding agents', function (): void {
    $commands = app(Kernel::class)->all();
    $codexConfig = file_get_contents(base_path('.codex/config.toml'));

    expect($commands)
        ->toHaveKeys(['boost:mcp', 'mcp:start'])
        ->and($commands['boost:mcp']->isHidden())
        ->toBeTrue()
        ->and($commands['mcp:start']->isHidden())
        ->toBeTrue()
        ->and($codexConfig)
        ->toBeString()
        ->toContain('args = ["orbit", "boost:mcp"]');
});
