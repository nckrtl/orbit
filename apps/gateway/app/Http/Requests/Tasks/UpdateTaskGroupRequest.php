<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateTaskGroupRequest extends FormRequest
{
    /** @return array<string, list<string|In>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'brief' => ['sometimes', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::in([TaskGroupStatus::Backlog->value, TaskGroupStatus::Todo->value])],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['title', 'brief', 'status']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): UpdateTaskGroupData
    {
        $status = $this->validated('status');

        return new UpdateTaskGroupData(
            title: is_string($this->validated('title')) ? $this->validated('title') : null,
            brief: is_string($this->validated('brief')) ? $this->validated('brief') : null,
            status: is_string($status) ? TaskGroupStatus::from($status) : null,
        );
    }
}
