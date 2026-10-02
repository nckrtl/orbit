<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class StoreProjectDevelopmentDeployStepRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'command' => ['required', 'string'],
            'timeout_seconds' => ['sometimes', 'integer:strict'],
            'required' => ['sometimes', 'boolean:strict'],
            'before' => ['sometimes', 'string'],
            'after' => ['sometimes', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'name',
                'command',
                'timeout_seconds',
                'required',
                'before',
                'after',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function step(): DevelopmentDeployStep
    {
        $payload = $this->validated();

        try {
            return new DevelopmentDeployStep(
                is_string($payload['name'] ?? null) ? $payload['name'] : '',
                is_string($payload['command'] ?? null) ? $payload['command'] : '',
                is_int($payload['timeout_seconds'] ?? null)
                    ? $payload['timeout_seconds']
                    : DevelopmentDeployStep::DefaultTimeoutSeconds,
                is_bool($payload['required'] ?? null) ? $payload['required'] : true,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function beforeStep(): ?string
    {
        $before = $this->validated('before');

        return is_string($before) ? $before : null;
    }

    public function afterStep(): ?string
    {
        $after = $this->validated('after');

        return is_string($after) ? $after : null;
    }
}
