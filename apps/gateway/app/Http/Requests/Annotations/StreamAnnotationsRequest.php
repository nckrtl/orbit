<?php

declare(strict_types=1);

namespace App\Http\Requests\Annotations;

use Illuminate\Foundation\Http\FormRequest;

final class StreamAnnotationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['after' => ['nullable', 'integer', 'min:0']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return ['after' => $this->header('Last-Event-ID', $this->query('after'))];
    }
}
