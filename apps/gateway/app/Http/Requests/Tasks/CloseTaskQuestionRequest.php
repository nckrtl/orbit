<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\QuestionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CloseTaskQuestionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([QuestionStatus::Answered->value, QuestionStatus::Superseded->value])],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function status(): QuestionStatus
    {
        $value = $this->validated('status');

        return QuestionStatus::from(is_string($value) ? $value : '');
    }

    public function reason(): string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : '';
    }
}
