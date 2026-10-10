<?php

declare(strict_types=1);

namespace App\Http\Requests\Conn;

use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Names the Node whose sessions to revoke; without `node_id` it is the calling Node. */
final class RevokeConnPairingsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['sometimes', 'integer', Rule::exists('nodes', 'id')],
        ];
    }

    public function node(): Node
    {
        if ($this->has('node_id')) {
            return Node::query()->findOrFail($this->integer('node_id'));
        }

        $caller = $this->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return $caller;
    }
}
