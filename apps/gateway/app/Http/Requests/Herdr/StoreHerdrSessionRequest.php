<?php

declare(strict_types=1);

namespace App\Http\Requests\Herdr;

use App\Data\Herdr\AddHerdrSessionData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreHerdrSessionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'min:1'],
            'session' => [
                'required',
                'string',
                'max:48',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D',
            ],
            'user' => [
                'required',
                'string',
                'max:32',
                'regex:/\A[a-z_][a-z0-9_-]*\z/D',
            ],
            'publish_observer' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): AddHerdrSessionData
    {
        return new AddHerdrSessionData(
            nodeId: (int) $this->validated('node_id'),
            session: (string) $this->validated('session'),
            user: (string) $this->validated('user'),
            publishObserver: $this->boolean('publish_observer'),
        );
    }
}
