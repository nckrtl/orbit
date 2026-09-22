<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateProjectLifecycleStepRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'command' => ['sometimes', 'string'],
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
                'command',
                'timeout_seconds',
                'before',
                'after',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function () use ($validator): void {
            $payload = $this->validated();

            if ($payload === []) {
                $validator->errors()->add('body', 'Provide at least one lifecycle step update field.');
            }
        });
    }

    public function command(): ?string
    {
        $command = $this->validated('command');

        return is_string($command) ? $command : null;
    }

    public function hasCommand(): bool
    {
        return array_key_exists('command', $this->validated());
    }

    public function timeoutSeconds(): ?int
    {
        $timeout = $this->validated('timeout_seconds');

        return is_int($timeout) ? $timeout : null;
    }

    public function hasTimeout(): bool
    {
        return array_key_exists('timeout_seconds', $this->validated());
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
