<?php

declare(strict_types=1);

namespace App\Http\Requests\Schedules;

use App\Domain\Schedules\ScheduleRunStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CompleteScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ScheduleRunStatus::class)],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [static function (Validator $validator): void {
            $unknown = array_diff(array_keys($validator->getData()), ['status']);

            if ($unknown !== []) {
                $validator->errors()->add('request', 'The completion request contains unknown fields.');
            }
        }];
    }

    public function status(): ScheduleRunStatus
    {
        return ScheduleRunStatus::from((string) $this->validated('status'));
    }
}
