<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ResolveDirectoryInstanceRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['directory' => ['required', 'string', 'max:4096']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        parse_str($this->server->getString('QUERY_STRING'), $query);
        if ($this->getContent() !== '' || array_diff(array_keys($query), ['directory']) !== []) {
            throw ValidationException::withMessages(['request' => ['Only the directory query parameter is supported, without a body.']]);
        }

        return $query;
    }
}
