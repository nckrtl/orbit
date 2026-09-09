<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Tools\EloquentNodeRoleToolIntentGuard;
use App\Models\Node;

describe(EloquentNodeRoleToolIntentGuard::class, function (): void {
    it('does not couple role removal to retained Tool or manager state', function (): void {
        $node = Node::query()->create([
            'name' => 'guard-limit-node',
            'status' => 'active',
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.10',
        ]);
        $guard = new EloquentNodeRoleToolIntentGuard;

        expect($guard->preview($node, RoleName::AppDev))
            ->toBeEmpty()
            ->and($guard->retirementPreview($node, RoleName::AppDev))
            ->toBeEmpty();

        $guard->assertRemovalSafe($node, RoleName::AppDev);
        $guard->retireUnsupportedManagers($node);
    });
});
