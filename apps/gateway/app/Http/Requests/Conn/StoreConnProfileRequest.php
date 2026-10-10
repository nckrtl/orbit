<?php

declare(strict_types=1);

namespace App\Http\Requests\Conn;

use App\Data\Conn\CreateConnProfileData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreConnProfileRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('conn_profiles', 'name')],
        ];
    }

    public function payload(): CreateConnProfileData
    {
        return new CreateConnProfileData(name: $this->string('name')->toString());
    }
}
