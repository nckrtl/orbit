<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Data\DatabaseConnections\CreateManagedMysqlUserData;
use App\Domain\DatabaseConnections\ManagedMysqlUserStatements;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CreateManagedMysqlUserRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:63', 'regex:'.DatabaseConnectionFieldRules::SLUG_PATTERN],
            'database' => ['required', 'string', 'max:32', 'regex:'.ManagedMysqlUserStatements::IDENTIFIER_PATTERN],
            'username' => ['required', 'string', 'max:32', 'regex:'.ManagedMysqlUserStatements::IDENTIFIER_PATTERN],
            'password' => ['required', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['slug', 'database', 'username', 'password']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateManagedMysqlUserData
    {
        $validated = $this->validated();

        return new CreateManagedMysqlUserData(
            slug: ValidatedData::string($validated['slug'] ?? null),
            database: ValidatedData::string($validated['database'] ?? null),
            username: ValidatedData::string($validated['username'] ?? null),
            password: ValidatedData::string($validated['password'] ?? null),
        );
    }
}
