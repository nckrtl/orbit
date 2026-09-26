<?php

declare(strict_types=1);

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

final class OpenLogStreamRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'lines' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function socketId(): string
    {
        return $this->validated('socket_id');
    }

    public function lines(): int
    {
        return (int) ($this->validated('lines') ?? 100);
    }
}
