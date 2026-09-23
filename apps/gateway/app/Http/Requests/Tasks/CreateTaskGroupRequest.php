<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CreateTaskGroupRequest extends FormRequest
{
    /** @return array<string, list<string|Exists|In>> */
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
