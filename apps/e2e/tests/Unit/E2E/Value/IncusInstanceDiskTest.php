<?php

declare(strict_types=1);

use App\E2E\Value\IncusInstance;

it('rejects a disk source that is not a string', function (): void {
    expect(fn () => new IncusInstance(
        'lab',
        'orbit',
        'vm',
        'pool',
        disks: ['orbit-source' => ['source' => 1, 'path' => '/srv/worktree']],
    ))->toThrow(InvalidArgumentException::class, 'Invalid Incus instance disk device.');
});
