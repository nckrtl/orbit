<?php

declare(strict_types=1);

it('keeps documentation tooling guidance beside the project', function (): void {
    $guidance = file_get_contents(dirname(__DIR__, 2).'/AGENTS.md');

    expect($guidance)
        ->toBeString()
        ->toContain('repository-root `docs/`')
        ->toContain('console-only')
        ->toContain('composer check');
});
