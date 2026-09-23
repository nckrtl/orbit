<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTaskCommentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['assistance_requested', 'resolution'])],
            'body' => ['required', 'string', 'max:100000'],
            'author' => ['required', 'string', 'max:255'],
            'agent_thread_id' => ['nullable', 'integer', 'exists:agent_threads,id'],
        ];
    }
}
