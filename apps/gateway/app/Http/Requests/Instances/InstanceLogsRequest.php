<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;

final class InstanceLogsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'follow' => ['prohibited'],
        ];
    }

    public function lines(): int
    {
        return $this->integer('lines', 100);
    }
}
