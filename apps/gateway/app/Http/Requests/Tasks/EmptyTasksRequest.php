<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class EmptyTasksRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = trim($this->getContent());

        if ($content === '' || $content === '[]') {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
