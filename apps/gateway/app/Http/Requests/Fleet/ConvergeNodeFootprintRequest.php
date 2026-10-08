<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class ConvergeNodeFootprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /** Whether to re-apply every artifact, not only the ones whose digest changed. */
    public function force(): bool
    {
        return $this->boolean('force');
    }
}
