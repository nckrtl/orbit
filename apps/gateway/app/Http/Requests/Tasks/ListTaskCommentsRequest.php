<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskCommentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListTaskCommentsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::enum(TaskCommentType::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function type(): ?TaskCommentType
    {
        $value = $this->validated('type');

        return is_string($value) ? TaskCommentType::from($value) : null;
    }

    public function limit(): ?int
    {
        $value = $this->validated('limit');

        return is_numeric($value) ? (int) $value : null;
    }
}
