<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class UpdateDatabaseConnectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return DatabaseConnectionFieldRules::update();
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                DatabaseConnectionFieldRules::updateKeys(),
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->validated() === []) {
                $validator->errors()->add('body', 'Provide at least one database connection field.');
            }
        });
    }

    public function payload(): UpdateDatabaseConnectionData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new UpdateDatabaseConnectionData(
            driverProvided: array_key_exists('driver', $validated),
            driver: is_string($validated['driver'] ?? null) ? DatabaseDriver::from($validated['driver']) : null,
            nodeIdProvided: array_key_exists('node_id', $validated),
            nodeId: is_int($validated['node_id'] ?? null) ? $validated['node_id'] : null,
            hostProvided: array_key_exists('host', $validated),
            host: is_string($validated['host'] ?? null) ? $validated['host'] : null,
            portProvided: array_key_exists('port', $validated),
            port: is_int($validated['port'] ?? null) ? $validated['port'] : null,
            databaseProvided: array_key_exists('database', $validated),
            database: is_string($validated['database'] ?? null) ? $validated['database'] : null,
            pathProvided: array_key_exists('path', $validated),
            path: is_string($validated['path'] ?? null) ? $validated['path'] : null,
            usernameProvided: array_key_exists('username', $validated),
            username: is_string($validated['username'] ?? null) ? $validated['username'] : null,
            passwordProvided: array_key_exists('password', $validated),
            password: is_string($validated['password'] ?? null) ? $validated['password'] : null,
        );
    }
}
