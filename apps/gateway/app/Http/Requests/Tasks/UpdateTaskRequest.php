<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'brief' => ['sometimes', 'string', 'max:8000'],
            'position' => ['sometimes', 'integer:strict', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['title', 'brief', 'position']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): UpdateTaskData
    {
        $position = $this->validated('position');

        return new UpdateTaskData(
            title: is_string($this->validated('title')) ? $this->validated('title') : null,
            brief: is_string($this->validated('brief')) ? $this->validated('brief') : null,
            position: is_int($position) ? $position : null,
        );
    }
}
