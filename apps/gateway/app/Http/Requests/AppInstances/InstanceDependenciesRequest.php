<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class InstanceDependenciesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if ($this->server->get('QUERY_STRING', '') !== '') {
            throw ValidationException::withMessages(['query' => ['Query parameters are not supported.']]);
        }

        $content = $this->getContent();
        if ($this->isMethod('GET') || $this->isMethod('HEAD')) {
            if ($content !== '') {
                throw ValidationException::withMessages(['body' => ['The request body must be empty.']]);
            }

            return [];
        }

        try {
            if (trim($content) === '') {
                throw new UnexpectedValueException;
            }

            return app(TopLevelJsonObjectInspector::class)->inspect($content, []);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be an empty JSON object.']]);
        }
    }
}
