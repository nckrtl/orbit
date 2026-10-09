<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

final class ShowTaskGroupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('compact'), ['true', 'false'], true)) {
            $this->merge(['compact' => $this->input('compact') === 'true']);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['compact' => ['sometimes', 'boolean']];
    }
}
