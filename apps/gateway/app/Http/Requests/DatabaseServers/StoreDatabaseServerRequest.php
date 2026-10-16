<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseServers;

use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Http\Requests\DatabaseConnections\DatabaseConnectionFieldRules;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreDatabaseServerRequest extends FormRequest
{
    public const string DEFAULT_TAG = '8.4';

    public const string DEFAULT_VALKEY_TAG = '8.1';

    public const int DEFAULT_PORT = 3306;

    public const string TAG_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/D';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:63', 'regex:'.DatabaseConnectionFieldRules::SLUG_PATTERN],
            'node_id' => ['required', 'integer', 'min:1', 'exists:nodes,id', self::strictInteger(...)],
            'driver' => ['sometimes', 'string', Rule::enum(DatabaseDriver::class)->only([DatabaseDriver::Mysql, DatabaseDriver::Redis])],
            'tag' => ['sometimes', 'string', 'max:128', 'regex:'.self::TAG_PATTERN],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535', self::strictInteger(...), 'prohibits:process_id'],
            // Adoption of an existing Valkey Process (ADR 0221): the Process supplies the port.
            'process_id' => ['required_with:connection', 'integer', 'min:1', self::strictInteger(...)],
            'connection' => ['required_with:process_id', 'string', 'max:63', 'regex:'.DatabaseConnectionFieldRules::SLUG_PATTERN],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['slug', 'node_id', 'driver', 'tag', 'port', 'process_id', 'connection'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateDatabaseServerData
    {
        $validated = $this->validated();
        $driver = DatabaseDriver::tryFrom(is_string($validated['driver'] ?? null) ? $validated['driver'] : '') ?? DatabaseDriver::Mysql;
        $processId = is_int($validated['process_id'] ?? null) ? $validated['process_id'] : null;

        return new CreateDatabaseServerData(
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : '',
            nodeId: is_int($validated['node_id'] ?? null) ? $validated['node_id'] : 0,
            driver: $driver,
            tag: is_string($validated['tag'] ?? null)
                ? $validated['tag']
                : ($driver === DatabaseDriver::Redis ? self::DEFAULT_VALKEY_TAG : self::DEFAULT_TAG),
            port: match (true) {
                $processId !== null => null,
                is_int($validated['port'] ?? null) => $validated['port'],
                default => $driver->defaultPort() ?? self::DEFAULT_PORT,
            },
            processId: $processId,
            connection: is_string($validated['connection'] ?? null) ? $validated['connection'] : null,
        );
    }

    private static function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
