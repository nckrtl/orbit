<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RealtimeAuthRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string'],
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
}
