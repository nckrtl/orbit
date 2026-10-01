<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Data\DatabaseConnections\AddDatabaseConnectionData;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreDatabaseConnectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->createsOnServer()
            ? DatabaseConnectionFieldRules::onServer()
            : DatabaseConnectionFieldRules::register();
    }

    /** A body with `server` creates the database on that Database server instead of registering one. */
    public function createsOnServer(): bool
    {
        return array_key_exists('server', $this->validationData());
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                DatabaseConnectionFieldRules::storeKeys(),
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function serverPayload(): CreateServerDatabaseData
    {
        $validated = $this->validated();

        return new CreateServerDatabaseData(
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : '',
            server: is_string($validated['server'] ?? null) ? $validated['server'] : '',
            instanceId: self::nullableInt($validated, 'instance_id'),
        );
    }

    public function payload(): AddDatabaseConnectionData
    {
        $validated = $this->validated();

        return new AddDatabaseConnectionData(
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : '',
            driver: DatabaseDriver::from(is_string($validated['driver'] ?? null) ? $validated['driver'] : ''),
            nodeId: self::nullableInt($validated, 'node_id'),
            host: self::nullableString($validated, 'host'),
            port: self::nullableInt($validated, 'port'),
            database: self::nullableString($validated, 'database'),
            path: self::nullableString($validated, 'path'),
            username: self::nullableString($validated, 'username'),
            password: self::nullableString($validated, 'password'),
        );
    }

    /** @param array<string, mixed> $validated */
    private static function nullableString(array $validated, string $key): ?string
    {
        return is_string($validated[$key] ?? null) ? $validated[$key] : null;
    }

    /** @param array<string, mixed> $validated */
    private static function nullableInt(array $validated, string $key): ?int
    {
        return is_int($validated[$key] ?? null) ? $validated[$key] : null;
    }
}
