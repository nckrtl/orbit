<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class ResumeFleetRolloutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'skip' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** The Node name or ID to skip, or null. */
    public function skip(): ?string
    {
        $skip = $this->validated('skip');

        return is_string($skip) && $skip !== '' ? $skip : null;
    }
}
