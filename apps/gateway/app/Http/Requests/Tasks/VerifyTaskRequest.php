<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\VerifyTaskData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class VerifyTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'run_key' => ['required', 'uuid'],
            'evidence' => ['required', 'array', 'min:1', 'max:3'],
            'evidence.*' => ['required', 'array:criterion_id,project,path,test'],
            'evidence.*.criterion_id' => ['required', 'string', 'distinct:strict', 'max:64'],
            'evidence.*.project' => ['required', 'string', 'in:apps/cli,apps/docs,apps/gateway,apps/e2e,packages/php-sdk'],
            'evidence.*.path' => ['required', 'string', 'max:300', 'regex:~\Atests/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php\z~'],
            'evidence.*.test' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['run_key', 'evidence']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): VerifyTaskData
    {
        return new VerifyTaskData((string) $this->validated('run_key'), $this->validated('evidence'));
    }
}
