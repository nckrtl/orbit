<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores whether new task workspaces get a Route, and the mode already provisioned
 * on each existing workspace. The slug seed runs once; the engine does not read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', static function (Blueprint $table): void {
            $table->boolean('task_workspace_routed')->default(true);
        });

        // Legacy Orbit checkouts were created without a Route. Every other Project was routed.
        DB::table('projects')->where('slug', 'orbit')->update(['task_workspace_routed' => false]);

        Schema::table('instances', static function (Blueprint $table): void {
            $table->boolean('task_workspace_routed')->nullable();
        });

        $this->backfillWorkspaceModes();
    }

    public function down(): void
    {
        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropColumn('task_workspace_routed');
        });

        Schema::table('projects', static function (Blueprint $table): void {
            $table->dropColumn('task_workspace_routed');
        });
    }

    /**
     * A managed task workspace is named task-{id}. A Route or an active lifecycle means it was routed.
     * A root was stored only when provisioning had already chosen a Route for a workspace that has not settled.
     * Ordinary Instances stay null.
     *
     * Rehearsals that stop before groups and subtasks share one table still keep the link on task_groups.
     */
    private function backfillWorkspaceModes(): void
    {
        if (Schema::hasTable('task_groups') && Schema::hasColumn('task_groups', 'taskable_id')) {
            $this->backfillLinkedWorkspaces('task_groups', false);

            return;
        }

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'taskable_id') && Schema::hasColumn('tasks', 'parent_id')) {
            $this->backfillLinkedWorkspaces('tasks', true);
        }
    }

    private function backfillLinkedWorkspaces(string $tasksTable, bool $parentScoped): void
    {
        if (! in_array($tasksTable, ['tasks', 'task_groups'], true)
            || ! Schema::hasColumn($tasksTable, 'execution_mode')
            || ! Schema::hasColumn($tasksTable, 'taskable_type')
            || ! Schema::hasTable('route_targets')
            || ! Schema::hasColumn('route_targets', 'instance_id')) {
            return;
        }

        $workspaces = DB::table('instances')
            ->select(['instances.id', 'instances.status', 'instances.root'])
            ->whereExists(function ($query) use ($tasksTable, $parentScoped): void {
                $query->selectRaw('1')
                    ->from($tasksTable)
                    ->whereColumn($tasksTable.'.taskable_id', 'instances.id')
                    ->where($tasksTable.'.taskable_type', 'instance')
                    ->where($tasksTable.'.execution_mode', 'managed')
                    ->whereRaw("instances.name = 'task-' || {$tasksTable}.id");

                if ($parentScoped) {
                    $query->whereNull($tasksTable.'.parent_id');
                }
            })
            ->get();

        if ($workspaces->isEmpty()) {
            return;
        }

        $routedIds = DB::table('route_targets')
            ->whereIn('instance_id', $workspaces->pluck('id')->all())
            ->pluck('instance_id')
            ->map(fn (mixed $id): int => $this->intValue($id))
            ->flip();

        $routed = [];
        $unrouted = [];

        foreach ($workspaces as $workspace) {
            $id = $this->intValue($workspace->id);
            $hasRoute = $routedIds->has($id);
            $choseRoute = is_string($workspace->root) && $workspace->root !== '';

            if ($hasRoute || $workspace->status === 'active' || $choseRoute) {
                $routed[] = $id;
            } else {
                $unrouted[] = $id;
            }
        }

        if ($routed !== []) {
            DB::table('instances')->whereIn('id', $routed)->update(['task_workspace_routed' => true]);
        }

        if ($unrouted !== []) {
            DB::table('instances')->whereIn('id', $unrouted)->update(['task_workspace_routed' => false]);
        }
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new RuntimeException('Expected an integer Instance id.');
    }
};
