<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAnalyticsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Three numbers and nothing else: the value becomes part of the image tag.
            'version' => ['required', 'string', 'regex:/\A\d+\.\d+\.\d+\z/'],
        ];
    }

    public function version(): string
    {
        return (string) $this->validated('version');
    }
}
