<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AgentRealtimeAuthRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string'],
            'version' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9A-Za-z.+-]{1,32}$/'],
        ];
    }

    public function socketId(): string
    {
        return $this->validated('socket_id');
    }

    public function channelName(): string
    {
        return $this->validated('channel_name');
    }

    public function version(): ?string
    {
        return $this->validated('version');
    }
}
