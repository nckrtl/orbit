<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SynchronizeAppInstanceEnvironmentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '') {
            throw ValidationException::withMessages(['body' => ['The request body must be an empty JSON object.']]);
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, []);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be an empty JSON object.']]);
        }
    }
}
