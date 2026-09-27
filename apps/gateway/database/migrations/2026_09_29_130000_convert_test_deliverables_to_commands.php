<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Convert the former Pest-specific deliverables in open task groups to generic commands (ADR 0178). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->join('task_groups', 'task_groups.id', '=', 'tasks.task_group_id')
            ->join('apps', 'apps.id', '=', 'task_groups.app_id')
            ->whereNotIn('task_groups.status', ['completed', 'failed', 'cancelled'])
            ->whereNotNull('tasks.deliverables')
            ->select('tasks.id', 'tasks.deliverables', 'apps.test_command')
            ->orderBy('tasks.id')
            ->chunk(100, function ($tasks): void {
                foreach ($tasks as $task) {
                    $deliverables = is_array($task->deliverables)
                        ? $task->deliverables
                        : json_decode((string) $task->deliverables, true);
                    if (! is_array($deliverables)) {
                        continue;
                    }
                    $changed = false;
                    foreach ($deliverables as &$deliverable) {
                        if (! is_array($deliverable) || ($deliverable['type'] ?? null) !== 'test') {
                            continue;
                        }
                        $project = $this->normalizeLegacyPath($deliverable['project'] ?? '.', allowRoot: true);
                        $file = $this->normalizeLegacyPath($deliverable['file'] ?? null, allowRoot: false);
                        $name = is_string($deliverable['name'] ?? null) ? trim($deliverable['name']) : '';
                        if ($project === null || $file === null || ! str_ends_with($file, '.php') || $name === '') {
                            throw new RuntimeException("Task {$task->id} has a legacy test deliverable with an unsafe project, file, or test name.");
                        }
                        $workspaceFile = $project === '.' ? $file : $project.'/'.$file;
                        $testCommand = is_string($task->test_command) ? trim($task->test_command) : '';
                        if ($testCommand === '') {
                            // The removed `test` type was explicitly Pest-specific. Preserve its runner as a documented legacy map.
                            $filter = '/'.preg_quote($name, '/').'/';
                            $command = 'cd '.escapeshellarg($project)
                                .' && vendor/bin/pest '.escapeshellarg($file)
                                .' --filter='.escapeshellarg($filter);
                        } else {
                            if (! str_contains($testCommand, '{file}') && ! str_contains($testCommand, '{project_file}')) {
                                throw new RuntimeException("Project test_command must include {file} or {project_file} to convert task {$task->id}.");
                            }
                            if (! str_contains($testCommand, '{name}')) {
                                throw new RuntimeException("Project test_command must include {name} to convert task {$task->id}.");
                            }
                            $command = strtr($testCommand, [
                                '{file}' => escapeshellarg($workspaceFile),
                                '{project_file}' => escapeshellarg($file),
                                '{project}' => escapeshellarg($project),
                                '{name}' => escapeshellarg($name),
                            ]);
                        }
                        $failsOnBase = ($deliverable['fails_on_base'] ?? false) === true;
                        $hadFailsOnBase = array_key_exists('fails_on_base', $deliverable);
                        $paths = [$workspaceFile];
                        $deliverable = [
                            'id' => $deliverable['id'] ?? '',
                            'type' => 'command',
                            'description' => $deliverable['description'] ?? '',
                            'command' => $command,
                            'directory' => '.',
                        ];
                        if ($hadFailsOnBase) {
                            $deliverable['fails_on_base'] = $failsOnBase;
                        }
                        if ($failsOnBase) {
                            $deliverable['paths'] = $paths;
                        }
                        $changed = true;
                    }
                    unset($deliverable);
                    if ($changed) {
                        DB::table('tasks')->where('id', $task->id)->update(['deliverables' => json_encode($deliverables, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Converted rows are intentionally not rewritten: a command deliverable may have been edited since migration.
    }

    private function normalizeLegacyPath(mixed $path, bool $allowRoot): ?string
    {
        if (! is_string($path) || str_starts_with($path, '/') || str_contains($path, '..')) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        if ($segments === []) {
            return $allowRoot ? '.' : null;
        }

        return implode('/', $segments);
    }
};
