<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\DeliverablePathChecker;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Validation\Validator;

trait ValidatesDeliverablePaths
{
    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            $group = $this->route('group');
            $task = $this->route('task');
            if ($task instanceof Task && ($task->status !== TaskStatus::Todo || ! $group instanceof Task || $task->parent_id !== $group->id)) {
                return;
            }
            $project = $group instanceof Task ? $group->project : Project::query()->find($data['project_id'] ?? null);
            if (! $project instanceof Project || (! $group instanceof Task && $project->source_access === ProjectSourceAccess::GhCli)) {
                return;
            }
            $lists = [];
            if ($group instanceof Task) {
                if (! array_key_exists('deliverables', $data) || $data['deliverables'] === []) {
                    return;
                }
                $lists['deliverables'] = $data['deliverables'];
                if (! $task instanceof Task) {
                    $lastPosition = $group->tasks()->max('position');
                    $task = new Task(['parent_id' => $group->id, 'position' => ($lastPosition === null ? 0 : StoredInteger::from($lastPosition)) + 1]);
                    $task->id = PHP_INT_MAX;
                    $task->setRelation('parent', $group);
                }
                $commit = TaskReviewBase::commit($task);
            } else {
                foreach ($data['tasks'] ?? [] as $index => $input) {
                    if (($input['deliverables'] ?? []) !== []) {
                        $lists["tasks.{$index}.deliverables"] = $input['deliverables'];
                    }
                }
                $commit = '';
            }
            if ($lists === [] || ! array_any($lists, static fn (array $list): bool => array_any($list, static fn (array $item): bool => (($item['type'] ?? null) === 'file' && ($item['change'] ?? null) !== 'created') || ($item['paths'] ?? []) !== []))) {
                return;
            }
            $kind = $commit === '' ? 'provisional' : 'resolved';
            if ($commit === '') {
                $commit = app(DeliverablePathRepository::class)->defaultBranchCommit($project);
            }
            foreach ($lists as $prefix => $deliverables) {
                foreach (app(DeliverablePathChecker::class)->check($project, $deliverables, $commit, $kind) as $field => $message) {
                    $validator->errors()->add("{$prefix}.{$field}", $message);
                }
            }
        }];
    }
}
