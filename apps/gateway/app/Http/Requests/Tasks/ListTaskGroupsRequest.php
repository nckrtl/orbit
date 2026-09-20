<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListTaskGroupsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(TaskGroupStatus::class)],
        ];
    }

    public function appId(): ?int
    {
        $value = $this->validated('app_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function status(): ?TaskGroupStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? TaskGroupStatus::from($value) : null;
    }
}
