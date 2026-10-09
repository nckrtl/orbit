<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListTaskGroupsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('compact'), ['true', 'false'], true)) {
            $this->merge(['compact' => $this->input('compact') === 'true']);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'compact' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(TaskGroupStatus::class)],
        ];
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function status(): ?TaskGroupStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? TaskGroupStatus::from($value) : null;
    }
}
