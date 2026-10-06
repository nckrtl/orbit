<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class UpdateDocumentRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
