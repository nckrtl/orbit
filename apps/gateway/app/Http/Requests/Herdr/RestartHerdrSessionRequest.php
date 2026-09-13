<?php

declare(strict_types=1);

namespace App\Http\Requests\Herdr;

use Illuminate\Foundation\Http\FormRequest;

final class RestartHerdrSessionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'handoff' => ['sometimes', 'boolean'],
        ];
    }

    public function handoff(): bool
    {
        return $this->boolean('handoff');
    }
}
