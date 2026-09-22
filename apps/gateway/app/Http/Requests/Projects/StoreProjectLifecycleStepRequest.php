<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\LifecycleStep;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class StoreProjectLifecycleStepRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'command' => ['required', 'string'],
            'timeout_seconds' => ['sometimes', 'integer'],
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
                'before',
                'after',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function step(): LifecycleStep
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->validated();

        try {
            return new LifecycleStep(
                is_string($payload['name'] ?? null) ? $payload['name'] : '',
                is_string($payload['command'] ?? null) ? $payload['command'] : '',
                is_int($payload['timeout_seconds'] ?? null)
                    ? $payload['timeout_seconds']
                    : LifecycleStep::DefaultTimeoutSeconds,
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
