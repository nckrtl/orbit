<?php

declare(strict_types=1);

use App\E2E\Value\LegacyTopologySnapshotInventory;

it('rejects a network name that is not a string', function (): void {
    $inventory = new LegacyTopologySnapshotInventory(
        ['remote' => 'local', 'project' => 'default', 'pool' => 'orbit-e2e'],
        [],
        [],
        [],
        [],
        ['name' => 15],
    );

    expect(fn () => $inventory->resourceNames())
        ->toThrow(InvalidArgumentException::class, 'The legacy topology snapshot inventory is invalid.');
});
