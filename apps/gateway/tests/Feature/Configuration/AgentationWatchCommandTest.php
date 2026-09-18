<?php

declare(strict_types=1);

use App\Infrastructure\Processes\SystemdProcessRenderer;

it('gives the process renderer the configured Antigravity watcher command', function (): void {
    config(['orbit.agentation.watch_command' => '/usr/local/bin/orbit-agentation-watch']);

    $renderer = app(SystemdProcessRenderer::class);
    $command = new ReflectionProperty($renderer, 'antigravityWatchCommand');

    expect($command->getValue($renderer))->toBe('/usr/local/bin/orbit-agentation-watch');
});
