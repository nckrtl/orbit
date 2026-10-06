<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class CreateDocumentRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:folder,file'],
            'name' => ['required', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'content_text' => ['sometimes', 'string'],
            'content_base64' => ['sometimes', 'string'],
            'media_type' => ['sometimes', 'required', 'string', 'max:127'],
        ];
    }
}
