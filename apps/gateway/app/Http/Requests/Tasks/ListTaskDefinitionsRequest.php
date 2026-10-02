<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

final class ListTaskDefinitionsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
