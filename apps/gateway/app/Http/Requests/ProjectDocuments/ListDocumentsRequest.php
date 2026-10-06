<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class ListDocumentsRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'state' => ['sometimes', 'required', 'string', 'in:active,archived,all'],
            'kind' => ['sometimes', 'required', 'string', 'in:folder,file'],
            'cursor' => ['sometimes', 'required', 'string', 'max:4096'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
