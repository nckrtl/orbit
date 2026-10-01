<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseServers;

use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Http\Requests\DatabaseConnections\DatabaseConnectionFieldRules;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreDatabaseServerRequest extends FormRequest
{
    public const string DEFAULT_TAG = '8.4';

    public const int DEFAULT_PORT = 3306;

    public const string TAG_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/D';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:63', 'regex:'.DatabaseConnectionFieldRules::SLUG_PATTERN],
            'node_id' => ['required', 'integer', 'min:1', 'exists:nodes,id', self::strictInteger(...)],
            'tag' => ['sometimes', 'string', 'max:128', 'regex:'.self::TAG_PATTERN],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535', self::strictInteger(...)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['slug', 'node_id', 'tag', 'port']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateDatabaseServerData
    {
        $validated = $this->validated();

        return new CreateDatabaseServerData(
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : '',
            nodeId: is_int($validated['node_id'] ?? null) ? $validated['node_id'] : 0,
            tag: is_string($validated['tag'] ?? null) ? $validated['tag'] : self::DEFAULT_TAG,
            port: is_int($validated['port'] ?? null) ? $validated['port'] : self::DEFAULT_PORT,
        );
    }

    private static function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
