<?php

declare(strict_types=1);

namespace App\Http\Requests\Processes;

use App\Domain\Processes\ProcessTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListProcessesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Omit both to list every Process in the fleet. A screen that draws the whole fleet
            // asks once rather than once per Node and AppInstance, which is dozens of requests
            // for data the Gateway holds in one table.
            'target_type' => ['required_with:target_id', 'nullable', Rule::enum(ProcessTargetType::class)],
            'target_id' => ['required_with:target_type', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function hasTarget(): bool
    {
        return $this->validated('target_type') !== null;
    }

    public function targetType(): ProcessTargetType
    {
        return ProcessTargetType::from((string) $this->validated('target_type'));
    }

    public function targetId(): int
    {
        return (int) $this->validated('target_id');
    }
}
