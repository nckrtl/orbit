<?php

declare(strict_types=1);

namespace App\Http\Requests\Herdr;

use Illuminate\Foundation\Http\FormRequest;

final class RemoveHerdrSessionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'accept_termination' => ['sometimes', 'boolean'],
        ];
    }

    public function acceptTermination(): bool
    {
        return $this->boolean('accept_termination');
    }
}
