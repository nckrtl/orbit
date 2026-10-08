<?php

declare(strict_types=1);

namespace App\Http\Requests\ProjectDocuments;

final class DocumentRevisionRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
