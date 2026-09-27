<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Convert the former Pest-specific deliverables in open task groups to generic commands (ADR 0178). */
return new class extends Migration
{
    private const int MAX_DELIVERABLES = 5;

    public function up(): void
    {
        DB::table('tasks')
            ->join('task_groups', 'task_groups.id', '=', 'tasks.task_group_id')
            ->whereNotIn('task_groups.status', ['completed', 'failed', 'cancelled'])
            ->whereNotNull('tasks.deliverables')
            ->select('tasks.id', 'tasks.task_group_id', 'tasks.position', 'tasks.title', 'tasks.brief', 'tasks.type', 'tasks.subtask_start_commit', 'tasks.deliverables')
            ->orderBy('tasks.id')
            ->chunk(100, function ($tasks): void {
                foreach ($tasks as $task) {
                    DB::transaction(function () use ($task): void {
                        $deliverables = is_array($task->deliverables)
                            ? $task->deliverables
                            : json_decode((string) $task->deliverables, true);
                        if (! is_array($deliverables)) {
                            return;
                        }

                        $usedIds = [];
                        foreach ($deliverables as $deliverable) {
                            if (is_array($deliverable) && ($deliverable['type'] ?? null) !== 'test' && is_string($deliverable['id'] ?? null)) {
                                $usedIds[$deliverable['id']] = true;
                            }
                        }

                        /** @var list<list<array<string, mixed>>> $units */
                        $units = [];
                        $changed = false;
                        foreach ($deliverables as $deliverable) {
                            if (! is_array($deliverable)) {
                                throw new RuntimeException("Task {$task->id} has a malformed deliverable list.");
                            }
                            /** @var array<string, mixed> $deliverable */
                            if (($deliverable['type'] ?? null) !== 'test') {
                                $units[] = [$deliverable];

                                continue;
                            }

                            $project = $this->normalizeLegacyPath($deliverable['project'] ?? '.', allowRoot: true);
                            $file = $this->normalizeLegacyPath($deliverable['file'] ?? null, allowRoot: false);
                            $name = is_string($deliverable['name'] ?? null) ? trim($deliverable['name']) : '';
                            if ($project === null || $file === null || ! str_ends_with($file, '.php') || $name === '') {
                                throw new RuntimeException("Task {$task->id} has a legacy test deliverable with an unsafe project, file, or test name.");
                            }

                            $workspaceFile = $project === '.' ? $file : $project.'/'.$file;
                            // The removed `test` type was explicitly Pest-specific. Preserve its runner as a documented legacy map.
                            $filter = '/'.preg_quote($name, '/').'/';
                            $command = 'cd '.escapeshellarg($project)
                                .' && vendor/bin/pest '.escapeshellarg($file)
                                .' --filter='.escapeshellarg($filter);
                            $deliverableId = is_string($deliverable['id'] ?? null) && $deliverable['id'] !== ''
                                ? $deliverable['id']
                                : 'converted-test';
                            $commandId = $this->uniqueId($deliverableId, $usedIds);
                            $fileId = $this->uniqueId(substr($commandId, 0, 59).'-file', $usedIds);
                            $failsOnBase = ($deliverable['fails_on_base'] ?? false) === true;
                            $commandDeliverable = [
                                'id' => $commandId,
                                'type' => 'command',
                                'description' => $deliverable['description'] ?? '',
                                'command' => $command,
                                'directory' => '.',
                            ];
                            if (array_key_exists('fails_on_base', $deliverable)) {
                                $commandDeliverable['fails_on_base'] = $failsOnBase;
                            }
                            if ($failsOnBase) {
                                $commandDeliverable['paths'] = [$workspaceFile];
                            }

                            $units[] = [$commandDeliverable, [
                                'id' => $fileId,
                                'type' => 'file',
                                'description' => 'The converted test file is changed.',
                                'path' => $workspaceFile,
                                'change' => 'any',
                            ]];
                            $changed = true;
                        }

                        if (! $changed) {
                            return;
                        }

                        $chunks = $this->boundedChunks($units);
                        DB::table('tasks')->where('id', $task->id)->update([
                            'deliverables' => json_encode($chunks[0], JSON_THROW_ON_ERROR),
                        ]);

                        if (count($chunks) === 1) {
                            return;
                        }

                        $sourcePositionValue = DB::table('tasks')->where('id', $task->id)->value('position');
                        if (! is_numeric($sourcePositionValue)) {
                            throw new RuntimeException("Task {$task->id} has no valid position for continuation tasks.");
                        }
                        $sourcePosition = (int) $sourcePositionValue;
                        $continuationCount = count($chunks) - 1;
                        $followingTasks = DB::table('tasks')
                            ->where('task_group_id', $task->task_group_id)
                            ->where('position', '>', $sourcePosition)
                            ->orderByDesc('position')
                            ->get(['id', 'position']);
                        foreach ($followingTasks as $followingTask) {
                            if (! is_numeric($followingTask->position)) {
                                throw new RuntimeException("Task {$followingTask->id} has no valid position to shift.");
                            }
                            DB::table('tasks')->where('id', $followingTask->id)->update([
                                'position' => (int) $followingTask->position + $continuationCount,
                            ]);
                        }

                        foreach (array_slice($chunks, 1) as $index => $chunk) {
                            DB::table('tasks')->insert([
                                'task_group_id' => $task->task_group_id,
                                'position' => $sourcePosition + $index + 1,
                                'title' => $this->continuationTitle((string) $task->title, $index + 1),
                                'brief' => $task->brief,
                                'type' => $task->type,
                                'status' => 'todo',
                                'continuation_of_task_id' => $task->id,
                                'subtask_start_commit' => $task->subtask_start_commit,
                                'deliverables' => json_encode($chunk, JSON_THROW_ON_ERROR),
                            ]);
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // Converted rows are intentionally not rewritten: a command deliverable may have been edited since migration.
    }

    /**
     * @param  list<list<array<string, mixed>>>  $units
     * @return list<list<array<string, mixed>>>
     */
    private function boundedChunks(array $units): array
    {
        $chunks = [];
        $chunk = [];
        foreach ($units as $unit) {
            if (count($chunk) + count($unit) > self::MAX_DELIVERABLES) {
                $chunks[] = $chunk;
                $chunk = [];
            }
            array_push($chunk, ...$unit);
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /** @param array<string, true> $usedIds */
    private function uniqueId(string $preferred, array &$usedIds): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($preferred)), '-');
        $base = $base !== '' ? $base : 'converted-test';
        for ($number = 1; ; $number++) {
            $suffix = $number === 1 ? '' : '-'.$number;
            $candidate = rtrim(substr($base, 0, 64 - strlen($suffix)), '-').$suffix;
            if (! isset($usedIds[$candidate])) {
                $usedIds[$candidate] = true;

                return $candidate;
            }
        }
    }

    private function continuationTitle(string $title, int $part): string
    {
        $suffix = ' (continued '.$part.')';

        return rtrim(mb_substr($title, 0, 160 - mb_strlen($suffix))).$suffix;
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
