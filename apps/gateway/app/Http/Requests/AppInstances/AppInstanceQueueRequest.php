<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AppInstanceQueueRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::in(['pending', 'completed', 'failed'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return 'pending'|'completed'|'failed' */
    public function state(): string
    {
        return match ($this->string('state', 'pending')->toString()) {
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    public function limit(): int
    {
        return $this->integer('limit', 50);
    }
}
