<?php

declare(strict_types=1);

namespace App\Http\Requests\Schedules;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class EmptyScheduleQueryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if ($this->query->all() !== []) {
            throw ValidationException::withMessages([
                'query' => ['The request contains unsupported query parameters.'],
            ]);
        }

        return [];
    }
}
