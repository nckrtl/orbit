<?php

declare(strict_types=1);

namespace App\Http\Requests\Annotations;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['status' => ['required', 'in:in_progress,resolved'], 'summary' => ['required_if:status,resolved', 'nullable', 'string', 'max:10000']];
    }
}
