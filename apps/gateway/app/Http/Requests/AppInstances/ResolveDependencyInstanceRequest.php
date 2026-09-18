<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ResolveDependencyInstanceRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['domain' => ['required', 'string', 'max:253']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if ($this->getContent() !== '' || array_diff(array_keys($this->query->all()), ['domain']) !== []) {
            throw ValidationException::withMessages(['request' => ['Only the domain query parameter is supported, without a body.']]);
        }

        return $this->query->all();
    }
}
