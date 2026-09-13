<?php

declare(strict_types=1);

namespace App\Http\Requests\Herdr;

use Illuminate\Foundation\Http\FormRequest;

final class ListHerdrSessionsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function nodeId(): int
    {
        return (int) $this->validated('node_id');
    }
}
