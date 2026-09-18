<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use Closure;
use Illuminate\Validation\Rule;

final readonly class DatabaseConnectionFieldRules
{
    public const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public const string HOST_PATTERN = '/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,253}[A-Za-z0-9])?\z/D';

    public const string DATABASE_PATTERN = '/\A(?:[A-Za-z_][A-Za-z0-9_$]{0,63}|[0-9]{1,3})\z/D';

    public const string USERNAME_PATTERN = '/\A[\x21-\x7E]{1,128}\z/D';

    public const string PATH_PATTERN = '/\A\/[^\x00]{1,1023}\z/D';

    /** @return array<string, list<mixed>> */
    public static function store(): array
    {
        return [
            'slug' => ['required', 'string', 'max:63', 'regex:'.self::SLUG_PATTERN],
            'driver' => ['required', Rule::enum(DatabaseDriver::class)],
            'node_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:nodes,id', self::strictInteger(...)],
            'host' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.self::HOST_PATTERN, 'not_regex:/[@:]/'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535', self::strictInteger(...)],
            'database' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:'.self::DATABASE_PATTERN],
            'path' => ['sometimes', 'nullable', 'string', 'max:1024', 'regex:'.self::PATH_PATTERN],
            'username' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:'.self::USERNAME_PATTERN],
            'password' => ['sometimes', 'nullable', 'string', 'max:1024', 'not_regex:/[\x00]/'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function update(): array
    {
        $rules = self::store();
        unset($rules['slug']);
        $rules['driver'] = ['sometimes', Rule::enum(DatabaseDriver::class)];

        return $rules;
    }

    /** @return list<string> */
    public static function storeKeys(): array
    {
        return ['slug', 'driver', 'node_id', 'host', 'port', 'database', 'path', 'username', 'password'];
    }

    /** @return list<string> */
    public static function updateKeys(): array
    {
        return ['driver', 'node_id', 'host', 'port', 'database', 'path', 'username', 'password'];
    }

    private static function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
