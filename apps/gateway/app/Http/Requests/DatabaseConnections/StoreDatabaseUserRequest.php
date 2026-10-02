<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Data\DatabaseConnections\CreateDatabaseUserData;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreDatabaseUserRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:32', 'regex:'.MysqlStatements::USERNAME_PATTERN],
            'password' => ['required', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
            'read_only' => ['sometimes', 'boolean:strict'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['username', 'password', 'read_only']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateDatabaseUserData
    {
        $validated = $this->validated();

        return new CreateDatabaseUserData(
            username: ValidatedData::string($validated['username'] ?? null),
            password: ValidatedData::string($validated['password'] ?? null),
            readOnly: ($validated['read_only'] ?? false) === true,
        );
    }
}
