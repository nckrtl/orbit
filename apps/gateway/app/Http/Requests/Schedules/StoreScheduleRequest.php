<?php

declare(strict_types=1);

namespace App\Http\Requests\Schedules;

use App\Data\Schedules\AddScheduleData;
use App\Domain\Schedules\ScheduleTargetType;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreScheduleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::enum(ScheduleTargetType::class)],
            'target_id' => ['required', 'integer', 'min:1', $this->strictInteger(...)],
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',
            ],
            'calendar' => ['required', 'string', 'max:255', 'regex:/\A[\x20-\x7E]+\z/D'],
            'command' => ['required', 'string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:86400', $this->strictInteger(...)],
            'start' => ['sometimes', 'boolean', $this->strictBoolean(...)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'target_type',
                'target_id',
                'name',
                'calendar',
                'command',
                'timeout_seconds',
                'start',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): AddScheduleData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new AddScheduleData(
            targetType: ScheduleTargetType::from((string) $validated['target_type']),
            targetId: (int) $validated['target_id'],
            name: (string) $validated['name'],
            calendar: (string) $validated['calendar'],
            command: (string) $validated['command'],
            timeoutSeconds: (int) ($validated['timeout_seconds'] ?? 3600),
            start: ($validated['start'] ?? true) === true,
        );
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }

    private function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
