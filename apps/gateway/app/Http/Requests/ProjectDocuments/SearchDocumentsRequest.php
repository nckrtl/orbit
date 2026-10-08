<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class SearchDocumentsRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'state' => ['sometimes', 'required', 'string', 'in:active,archived,all'],
            'kind' => ['sometimes', 'required', 'string', 'in:folder,file'],
            'cursor' => ['sometimes', 'required', 'string', 'max:4096'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
