<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class DocumentContentRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
