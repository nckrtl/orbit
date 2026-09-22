<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\AddTaskData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AddTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'verification' => ['sometimes', 'array', 'min:1', 'max:3'],
            'verification.*' => ['required', 'array:id,requirement,question,true,false,environment'],
            'verification.*.id' => ['required', 'string', 'distinct:strict', 'max:64', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'verification.*.requirement' => ['required', 'string', 'max:1000'],
            'verification.*.question' => ['required', 'string', 'max:1000'],
            'verification.*.true' => ['required', 'string', 'max:500'],
            'verification.*.false' => ['required', 'string', 'max:500'],
            'verification.*.environment' => ['required', 'string', 'in:local'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['title', 'brief', 'verification']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): AddTaskData
    {
        return new AddTaskData(
            title: (string) $this->validated('title'),
            brief: (string) $this->validated('brief'),
            verification: $this->validated('verification', []),
        );
    }
}
