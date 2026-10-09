<?php

declare(strict_types=1);

namespace App\Http\Requests\T3;

use App\Models\T3Profile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BindT3ProfileRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer', Rule::exists('t3_profiles', 'id')],
        ];
    }

    public function profile(): T3Profile
    {
        return T3Profile::query()->findOrFail($this->integer('profile_id'));
    }
}
