<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Tools\EloquentNodeRoleToolIntentGuard;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

describe(EloquentNodeRoleToolIntentGuard::class, function (): void {
    it('applies the preview limit in the database query', function (): void {
        $node = Node::query()->create([
            'name' => 'guard-limit-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.10',
        ]);
        $manager = ToolManagerRecord::query()->create([
            'node_id' => $node->id,
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Active,
        ]);

        foreach (range(start: 1, end: 11) as $number) {
            Tool::query()->create([
                'node_id' => $node->id,
                'tool_manager_id' => $manager->id,
                'package' => "package-{$number}",
                'status' => ToolStatus::Installed,
            ]);
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        expect(new EloquentNodeRoleToolIntentGuard()->preview($node, RoleName::AppDev))
            ->toHaveCount(10);

        $toolQuery = collect($queries)->first(
            static fn (string $sql): bool => str_contains($sql, 'from "tools"') && str_contains($sql, 'tool_managers'),
        );

        expect($toolQuery)->toContain('limit 10');
    });
});
