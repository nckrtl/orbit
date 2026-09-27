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
        return $this->string('socket_id')->toString();
    }

    public function channelName(): string
    {
        return $this->string('channel_name')->toString();
    }

    public function version(): ?string
    {
        $version = $this->validated('version');

        return is_string($version) ? $version : null;
    }
}
