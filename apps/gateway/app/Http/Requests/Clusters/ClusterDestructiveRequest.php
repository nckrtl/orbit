<?php

declare(strict_types=1);

namespace App\Http\Requests\Clusters;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class ClusterDestructiveRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'force' => ['required', $this->strictTrue(...)],
        ];
    }

    private function strictTrue(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== true) {
            $fail("The {$attribute} field must be true.");
        }
    }
}
