<?php

declare(strict_types=1);

namespace App\Http\Requests\T3;

use App\Http\Middleware\RequireT3Peer;
use App\Models\T3Peer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Names the peer whose sessions to revoke; without `peer_id` it is the calling peer. */
final class RevokeT3PairingsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'peer_id' => ['sometimes', 'integer', Rule::exists('t3_peers', 'id')],
        ];
    }

    public function peer(): T3Peer
    {
        return $this->has('peer_id')
            ? T3Peer::query()->findOrFail($this->integer('peer_id'))
            : RequireT3Peer::peer($this);
    }
}
