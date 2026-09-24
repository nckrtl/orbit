<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App;
use App\Rules\DistinctDeliverableIds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CreateTaskGroupRequest extends FormRequest
{
    /** @return array<string, list<string|Exists|In|Enum|DistinctDeliverableIds>> */
    public function rules(): array
    {
        return [
            'app_id' => ['required', 'integer:strict', 'min:1', Rule::exists(App::class, 'id')],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::in([TaskGroupStatus::Backlog->value, TaskGroupStatus::Todo->value])],
            'notify_coder' => ['sometimes', 'boolean'],
            'plan' => ['sometimes', 'boolean'],
            'notify_on_settle' => ['sometimes', 'boolean'],
            'tasks' => ['sometimes', 'array', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:160'],
            'tasks.*.brief' => ['required', 'string', 'max:8000'],
            'tasks.*.deliverables' => ['sometimes', 'array', 'list', 'max:5', new DistinctDeliverableIds],
            'tasks.*.deliverables.*' => ['required', 'array:id,type,description,path,change,project,file,name,command,directory'],
            'tasks.*.deliverables.*.id' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'tasks.*.deliverables.*.type' => ['required', 'string', Rule::enum(TaskDeliverableType::class)],
            'tasks.*.deliverables.*.description' => ['required', 'string', 'max:500'],
            'tasks.*.deliverables.*.path' => ['required_if:tasks.*.deliverables.*.type,file', 'prohibited_unless:tasks.*.deliverables.*.type,file', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'tasks.*.deliverables.*.change' => ['required_if:tasks.*.deliverables.*.type,file', 'prohibited_unless:tasks.*.deliverables.*.type,file', 'string', Rule::in(['created', 'modified', 'any'])],
            'tasks.*.deliverables.*.project' => ['required_if:tasks.*.deliverables.*.type,test', 'prohibited_unless:tasks.*.deliverables.*.type,test', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'tasks.*.deliverables.*.file' => ['required_if:tasks.*.deliverables.*.type,test', 'prohibited_unless:tasks.*.deliverables.*.type,test', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'tasks.*.deliverables.*.name' => ['required_if:tasks.*.deliverables.*.type,test', 'prohibited_unless:tasks.*.deliverables.*.type,test', 'string', 'max:200'],
            'tasks.*.deliverables.*.command' => ['required_if:tasks.*.deliverables.*.type,command', 'prohibited_unless:tasks.*.deliverables.*.type,command', 'string', 'max:1000'],
            'tasks.*.deliverables.*.directory' => ['sometimes', 'prohibited_unless:tasks.*.deliverables.*.type,command', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'app_id',
                'title',
                'brief',
                'status',
                'notify_coder',
                'notify_on_settle',
                'plan',
                'tasks',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateTaskGroupData
    {
        $tasks = [];

        foreach ($this->validated('tasks') ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }

            $tasks[] = new TaskInputData(
                title: (string) $task['title'],
                brief: (string) $task['brief'],
                deliverables: CreateTaskRequest::deliverables($task['deliverables'] ?? null),
            );
        }

        return new CreateTaskGroupData(
            appId: (int) $this->validated('app_id'),
            title: (string) $this->validated('title'),
            brief: (string) $this->validated('brief'),
            status: TaskGroupStatus::from((string) ($this->validated('status') ?? TaskGroupStatus::Backlog->value)),
            notifyCoder: $this->boolean('notify_coder') || $this->boolean('notify_on_settle'),
            plan: $this->boolean('plan'),
            tasks: $tasks,
        );
    }
}
