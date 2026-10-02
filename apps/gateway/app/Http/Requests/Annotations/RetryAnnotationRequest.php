<?php

declare(strict_types=1);

namespace App\Http\Requests\Annotations;

use Illuminate\Foundation\Http\FormRequest;

final class RetryAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['threadId' => ['sometimes', 'nullable', 'string', 'max:128']];
    }
}
