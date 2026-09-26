<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListActivitiesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'request_id' => ['sometimes', 'uuid'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(['running', 'succeeded', 'failed'])],
            'command' => ['sometimes', 'string', 'min:1', 'max:255'],
            'caller_node_id' => ['sometimes', 'integer', 'min:1'],
            'target_node_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }

    public function requestId(): ?string
    {
        $requestId = $this->validated('request_id');

        return is_string($requestId) ? $requestId : null;
    }

    public function beforeId(): ?int
    {
        return $this->positiveInt('before_id');
    }

    public function status(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : null;
    }

    public function command(): ?string
    {
        $command = $this->validated('command');

        return is_string($command) ? $command : null;
    }

    public function callerNodeId(): ?int
    {
        return $this->positiveInt('caller_node_id');
    }

    public function targetNodeId(): ?int
    {
        return $this->positiveInt('target_node_id');
    }

    private function positiveInt(string $key): ?int
    {
        $value = $this->validated($key);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
