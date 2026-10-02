<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ListTaskQuestionsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'cause' => ['sometimes', 'string', Rule::enum(QuestionCause::class)],
            'status' => ['sometimes', 'string', Rule::enum(QuestionStatus::class)],
            'since' => ['sometimes', 'date'],
        ];
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function cause(): ?QuestionCause
    {
        $value = $this->validated('cause');

        return is_string($value) ? QuestionCause::from($value) : null;
    }

    public function status(): ?QuestionStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? QuestionStatus::from($value) : null;
    }

    public function since(): ?Carbon
    {
        $value = $this->validated('since');

        return is_string($value) ? Carbon::parse($value) : null;
    }
}
