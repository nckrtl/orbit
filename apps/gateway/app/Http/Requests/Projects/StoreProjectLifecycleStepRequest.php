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
                'name',
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

    public function step(): LifecycleStep
    {
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
