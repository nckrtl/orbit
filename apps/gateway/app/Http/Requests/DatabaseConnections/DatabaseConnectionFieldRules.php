<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\ValkeyAclUser;
use Closure;
use Illuminate\Validation\Rule;

final readonly class DatabaseConnectionFieldRules
{
    public const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public const string HOST_PATTERN = '/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,253}[A-Za-z0-9])?\z/D';

    public const string DATABASE_PATTERN = '/\A(?:[A-Za-z_][A-Za-z0-9_$]{0,63}|[0-9]{1,3})\z/D';

    public const string USERNAME_PATTERN = '/\A[\x21-\x7E]{1,128}\z/D';

    public const string PATH_PATTERN = '/\A\/[^\x00]{1,1023}\z/D';

    /** A Valkey key pattern: printable ASCII without spaces. Orbit adds the `~`. */
    public const string ACL_KEY_PATTERN = '/\A[\x21-\x7E]+\z/D';

    /** A Valkey key pattern never starts with the prefix of another rule, such as `%R~` or a channel's `&`. */
    public const string ACL_KEY_PREFIX_PATTERN = '/\A[~%&]/';

    /** A command category such as `@list`, or a command such as `eval` or `client|setname`. Orbit adds the `+`. */
    public const string ACL_RULE_PATTERN = '/\A(?:@[a-z]+|[a-z][a-z0-9_.-]*(?:\|[a-z][a-z0-9_.-]*)?)\z/D';

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

    /**
     * Registering an existing database never names a server or an Instance.
     *
     * @return array<string, list<mixed>>
     */
    public static function register(): array
    {
        return [
            ...self::store(),
            'server' => ['missing'],
            'instance_id' => ['missing'],
            'patterns' => ['missing'],
            'allow' => ['missing'],
        ];
    }

    /**
     * Creating a database on a MySQL server takes only the slug, the server, and an optional Instance. An ACL user on a
     * Valkey server takes the slug, the server, its key patterns and allowed commands, and an optional database index.
     * The Gateway derives every other connection field from the server. A server that does not exist gets no rules for
     * the driver's fields, so the create reports the missing server.
     *
     * @return array<string, list<mixed>>
     */
    public static function onServer(?DatabaseDriver $serverDriver): array
    {
        $rules = [
            'slug' => ['required', 'string', 'max:63', 'regex:'.self::SLUG_PATTERN],
            'server' => ['required', 'string', 'max:63', 'regex:'.self::SLUG_PATTERN],
            'instance_id' => ['sometimes', 'integer', 'min:1', 'exists:instances,id', self::strictInteger(...)],
        ];

        foreach (self::updateKeys() as $key) {
            $rules[$key] = ['missing'];
        }

        if ($serverDriver === null) {
            unset($rules['database']);

            return $rules;
        }

        return match ($serverDriver) {
            DatabaseDriver::Redis => [
                ...$rules,
                'slug' => [...$rules['slug'], Rule::notIn([ValkeyAclUser::ADMIN])],
                'database' => ['sometimes', 'integer', 'min:0', 'max:999', self::strictInteger(...)],
                'patterns' => ['required', 'array', 'list', 'min:1', 'max:32'],
                'patterns.*' => ['required', 'string', 'max:255', 'regex:'.self::ACL_KEY_PATTERN, 'not_regex:'.self::ACL_KEY_PREFIX_PATTERN],
                'allow' => ['required', 'array', 'list', 'min:1', 'max:64'],
                'allow.*' => ['required', 'string', 'max:128', 'regex:'.self::ACL_RULE_PATTERN],
            ],
            default => [...$rules, 'patterns' => ['missing'], 'allow' => ['missing']],
        };
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
        return ['slug', 'driver', 'node_id', 'host', 'port', 'database', 'path', 'username', 'password', 'server', 'instance_id', 'patterns', 'allow'];
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
