<?php

declare(strict_types=1);

namespace App\Http\Requests\T3;

use App\Data\T3\CreateT3ProfileData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreT3ProfileRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('t3_profiles', 'name')],
        ];
    }

    public function payload(): CreateT3ProfileData
    {
        return new CreateT3ProfileData(name: $this->string('name')->toString());
    }
}
