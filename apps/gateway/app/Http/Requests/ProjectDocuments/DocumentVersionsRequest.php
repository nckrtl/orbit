<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class DocumentVersionsRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'required', 'string', 'max:4096'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
