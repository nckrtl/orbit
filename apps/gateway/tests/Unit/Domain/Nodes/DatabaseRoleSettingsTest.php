<?php

declare(strict_types=1);

use App\Domain\Nodes\DatabaseRoleSettings;
use App\Domain\Nodes\NodeRoleValidationException;

it('accepts empty database role settings', function (): void {
    expect(DatabaseRoleSettings::from([]))
        ->toBeInstanceOf(DatabaseRoleSettings::class);
});

it('rejects non-empty database role settings', function (): void {
    expect(fn () => DatabaseRoleSettings::from(['engine' => 'mysql']))
        ->toThrow(NodeRoleValidationException::class, 'The database role does not accept settings.');
});
