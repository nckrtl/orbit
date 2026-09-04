<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class EmptyRouteRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '[]') {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
