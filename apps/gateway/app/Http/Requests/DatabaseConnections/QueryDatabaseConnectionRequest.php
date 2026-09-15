<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseSqlClassifier;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class QueryDatabaseConnectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sql' => ['required', 'string', 'min:1', 'max:'.DatabaseSqlClassifier::SQL_MAX_LENGTH],
            'write' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['sql', 'write']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function sql(): string
    {
        return (string) $this->validated('sql');
    }

    public function write(): bool
    {
        return (bool) ($this->validated('write') ?? false);
    }
}
