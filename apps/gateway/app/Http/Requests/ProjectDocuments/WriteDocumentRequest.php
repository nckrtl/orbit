<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class WriteDocumentRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'content_text' => ['sometimes', 'string'],
            'content_base64' => ['sometimes', 'string'],
            'media_type' => ['sometimes', 'required', 'string', 'max:127'],
        ];
    }
}
