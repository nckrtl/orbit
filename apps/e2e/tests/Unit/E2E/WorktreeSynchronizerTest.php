<?php

declare(strict_types=1);

use App\E2E\Value\DirtyOverlay;
use App\E2E\Value\SourceState;

describe('worktree synchronization values', function () {
    it('records exact dirty source and operation evidence', function () {
        $state = new SourceState(
            str_repeat('a', 40),
            str_repeat('a', 40),
            true,
            str_repeat('b', 64),
            ['apps/cli/app/Commands/StatusCommand.php'],
            str_repeat('c', 32),
        );

        expect(SourceState::fromArray($state->toArray()))
            ->toEqual($state)
            ->and($state->toArray()['overlay_paths'])
            ->toBe(['apps/cli/app/Commands/StatusCommand.php']);
    });

    it('rejects unsafe overlay path segments before transfer', function (string $path) {
        expect(fn () => new DirtyOverlay([$path], str_repeat('a', 64)))
            ->toThrow(InvalidArgumentException::class);
    })->with(['absolute' => '/etc/passwd', 'parent' => 'apps/../.env', 'duplicate separator' => 'apps//cli']);

    it('rejects inconsistent source evidence', function () {
        expect(fn () => new SourceState(
            str_repeat('a', 40),
            str_repeat('a', 40),
            false,
            null,
            ['README.md'],
        ))
            ->toThrow(InvalidArgumentException::class, 'Clean source');
    });
});
