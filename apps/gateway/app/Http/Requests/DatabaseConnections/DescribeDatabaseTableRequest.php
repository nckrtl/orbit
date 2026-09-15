<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseTableName;
use Illuminate\Foundation\Http\FormRequest;

final class DescribeDatabaseTableRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'table' => ['required', 'string', 'max:'.DatabaseTableName::MAX_LENGTH, 'regex:'.DatabaseTableName::PATTERN],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [
            'table' => $this->route('table'),
        ];
    }

    public function table(): string
    {
        return (string) $this->validated('table');
    }
}
