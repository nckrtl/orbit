<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskTopology;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Project;
use App\Rules\CommandPaths;
use App\Rules\DistinctDeliverableIds;
use App\Rules\FailsOnBase;
use App\Rules\TaskTopologyList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CreateTaskGroupRequest extends FormRequest
{
    use ValidatesDeliverablePaths;

    /** @return array<string, list<string|Exists|In|Enum|DistinctDeliverableIds|FailsOnBase|CommandPaths|TaskTopologyList>> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer:strict', 'min:1', Rule::exists(Project::class, 'id')],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::in([TaskGroupStatus::Backlog->value, TaskGroupStatus::Todo->value])],
            'preview' => ['sometimes', 'boolean:strict'],
            'notify_coder' => ['sometimes', 'boolean'],
            'notify_on_settle' => ['sometimes', 'boolean'],
            'tasks' => ['sometimes', 'array', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:160'],
            'tasks.*.brief' => ['required', 'string', 'max:8000'],
            'tasks.*.topology' => ['sometimes', 'array', 'list', 'max:3', new TaskTopologyList],
            'tasks.*.topology.*' => ['required', 'string', Rule::in(['app-dev', 'app-prod', 'app-prod-2'])],
            'tasks.*.deliverables' => ['sometimes', 'array', 'list', 'max:5', new DistinctDeliverableIds],
            'tasks.*.deliverables.*' => ['required', 'array:id,type,description,path,change,command,directory,fails_on_base,paths'],
            'tasks.*.deliverables.*.id' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'tasks.*.deliverables.*.type' => ['required', 'string', Rule::enum(TaskDeliverableType::class)],
            'tasks.*.deliverables.*.description' => ['required', 'string', 'max:500'],
            'tasks.*.deliverables.*.path' => ['required_if:tasks.*.deliverables.*.type,file', 'prohibited_unless:tasks.*.deliverables.*.type,file', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'tasks.*.deliverables.*.change' => ['required_if:tasks.*.deliverables.*.type,file', 'prohibited_unless:tasks.*.deliverables.*.type,file', 'string', Rule::in(['created', 'modified', 'any'])],
            'tasks.*.deliverables.*.fails_on_base' => ['sometimes', new FailsOnBase, 'boolean:strict'],
            'tasks.*.deliverables.*.command' => ['required_if:tasks.*.deliverables.*.type,command', 'prohibited_unless:tasks.*.deliverables.*.type,command', 'string', 'max:1000'],
            'tasks.*.deliverables.*.directory' => ['sometimes', 'prohibited_unless:tasks.*.deliverables.*.type,command', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'tasks.*.deliverables.*.paths' => [new CommandPaths, 'prohibited_unless:tasks.*.deliverables.*.type,command', 'array', 'list', 'max:100'],
            'tasks.*.deliverables.*.paths.*' => ['required', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'project_id',
                'title',
                'brief',
                'status',
                'preview',
                'notify_coder',
                'notify_on_settle',
                'tasks',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateTaskGroupData
    {
        $tasks = [];
        $validatedTasks = $this->validated('tasks');

        foreach (is_array($validatedTasks) ? $validatedTasks : [] as $task) {
            if (! is_array($task) || ! is_string($task['title'] ?? null) || ! is_string($task['brief'] ?? null)) {
                continue;
            }

            $tasks[] = new TaskInputData(
                title: $task['title'],
                brief: $task['brief'],
                deliverables: CreateTaskRequest::deliverables($task['deliverables'] ?? null),
                topology: TaskTopology::from($task['topology'] ?? []),
            );
        }

        return new CreateTaskGroupData(
            projectId: $this->integer('project_id'),
            title: $this->string('title')->toString(),
            brief: $this->string('brief')->toString(),
            status: TaskGroupStatus::from($this->string('status', TaskGroupStatus::Backlog->value)->toString()),
            notifyCoder: $this->boolean('notify_coder') || $this->boolean('notify_on_settle'),
            tasks: $tasks,
            preview: $this->boolean('preview'),
        );
    }
}
