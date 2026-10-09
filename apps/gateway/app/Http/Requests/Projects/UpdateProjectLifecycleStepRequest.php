<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
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
            'rebalance' => ['sometimes', 'list', 'max:32'],
            'rebalance.*' => ['array:name,timeout_seconds'],
            'rebalance.*.name' => ['required', 'string'],
            'rebalance.*.timeout_seconds' => ['required', 'integer'],
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
                'rebalance',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function withValidator(Validator $validator): void
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

    /**
     * Other steps of the list and their new timeouts, set in the same write.
     *
     * @return list<array{name: string, timeout_seconds: int}>
     */
    public function rebalance(): array
    {
        $rebalance = [];

        foreach ((array) $this->validated('rebalance', []) as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && is_int($entry['timeout_seconds'] ?? null)) {
                $rebalance[] = ['name' => $entry['name'], 'timeout_seconds' => $entry['timeout_seconds']];
            }
        }

        return $rebalance;
    }
}
