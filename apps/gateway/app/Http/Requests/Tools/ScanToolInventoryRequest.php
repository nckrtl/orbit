<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ScanToolInventoryRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'min:1', 'exists:nodes,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if ($this->getContent() !== '') {
            throw ValidationException::withMessages([
                'body' => ['The request body must be empty.'],
            ]);
        }

        $queryString = $this->server->getString('QUERY_STRING');
        $keys = $this->queryKeys($queryString);

        if ($keys !== ['node_id']) {
            throw ValidationException::withMessages([
                'query' => ['Only the node_id query parameter is supported.'],
            ]);
        }

        parse_str($queryString, $query);
        $nodeId = $query['node_id'] ?? null;

        if (! is_string($nodeId) || preg_match('/\A[1-9][0-9]*\z/D', $nodeId) !== 1) {
            throw ValidationException::withMessages([
                'node_id' => ['The node_id field must be an integer.'],
            ]);
        }

        $integer = filter_var($nodeId, FILTER_VALIDATE_INT);

        if (! is_int($integer)) {
            throw ValidationException::withMessages([
                'node_id' => ['The node_id field must be an integer.'],
            ]);
        }

        return ['node_id' => $integer];
    }

    public function nodeId(): int
    {
        $nodeId = $this->validated('node_id');
        assert(is_int($nodeId));

        return $nodeId;
    }

    /** @return list<string> */
    private function queryKeys(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }

        $keys = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = rawurldecode(explode('=', $pair, 2)[0]);

            if (in_array($name, $keys, true)) {
                return ['node_id', 'node_id'];
            }

            $keys[] = $name;
        }

        return $keys;
    }
}
