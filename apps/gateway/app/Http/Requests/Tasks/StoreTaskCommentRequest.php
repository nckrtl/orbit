<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTaskCommentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $reviewType = ['changes_requested', 'approved'];

        return [
            'type' => ['required', 'string', Rule::in([
                'ready_for_review', 'changes_requested', 'approved', 'assistance_requested', 'resolution',
            ])],
            'body' => ['required', 'string', 'max:100000'],
            'author' => ['required', 'string', 'max:255'],
            'agent_thread_id' => ['nullable', 'integer', 'exists:agent_threads,id'],
            'review_attempt' => [Rule::requiredIf(fn (): bool => in_array($this->string('type')->toString(), $reviewType, true)), 'nullable', 'integer', 'min:1'],
            'reviewer_thread_id' => [Rule::requiredIf(fn (): bool => in_array($this->string('type')->toString(), $reviewType, true)), 'nullable', 'string', 'max:255'],
            'driver_turn' => [Rule::requiredIf(fn (): bool => in_array($this->string('type')->toString(), $reviewType, true)), 'nullable', 'string', 'max:255'],
            'commit_sha' => ['nullable', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/'],
            'pr_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
