<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

final class TaskAgentStreamRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['after_sequence' => ['nullable', 'integer', 'min:0', 'max:9007199254740991']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return ['after_sequence' => $this->header('Last-Event-ID', $this->query('after_sequence'))];
    }

    public function afterSequence(): ?int
    {
        $sequence = $this->validated('after_sequence');

        return $sequence === null ? null : (int) $sequence;
    }
}
