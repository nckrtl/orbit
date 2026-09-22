<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class CreateTaskGroupRequest extends FormRequest
{
    /** @return array<string, list<string|Exists>> */
    public function rules(): array
    {
        return [
            'app_id' => ['required', 'integer:strict', 'min:1', Rule::exists(App::class, 'id')],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'notify_coder' => ['sometimes', 'boolean'],
            'notify_on_settle' => ['sometimes', 'boolean'],
            'tasks' => ['sometimes', 'array', 'max:50'],
            'tasks.*' => ['array:title,brief,verification'],
            'tasks.*.title' => ['required', 'string', 'max:160'],
            'tasks.*.brief' => ['required', 'string', 'max:8000'],
            'tasks.*.verification' => ['sometimes', 'array', 'min:1', 'max:3'],
            'tasks.*.verification.*' => ['required', 'array:id,requirement,question,true,false,environment'],
            'tasks.*.verification.*.id' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'tasks.*.verification.*.requirement' => ['required', 'string', 'max:1000'],
            'tasks.*.verification.*.question' => ['required', 'string', 'max:1000'],
            'tasks.*.verification.*.true' => ['required', 'string', 'max:500'],
            'tasks.*.verification.*.false' => ['required', 'string', 'max:500'],
            'tasks.*.verification.*.environment' => ['required', 'string', 'in:local'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('tasks', []) as $index => $task) {
                $ids = array_column($task['verification'] ?? [], 'id');
                if (count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add('tasks.'.$index.'.verification', 'Verification criterion IDs must be unique within a task.');
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'app_id',
                'title',
                'brief',
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

        foreach ($this->validated('tasks') ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }

            $tasks[] = new TaskInputData(
                title: (string) $task['title'],
                brief: (string) $task['brief'],
                verification: $task['verification'] ?? [],
            );
        }

        return new CreateTaskGroupData(
            appId: (int) $this->validated('app_id'),
            title: (string) $this->validated('title'),
            brief: (string) $this->validated('brief'),
            notifyCoder: $this->boolean('notify_coder') || $this->boolean('notify_on_settle'),
            tasks: $tasks,
        );
    }
}
