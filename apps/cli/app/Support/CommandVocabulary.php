<?php

declare(strict_types=1);

namespace App\Support;

final readonly class CommandVocabulary
{
    /** @var list<string> */
    public const array VERBS = [
        'add',
        'create',
        'destroy',
        'disable',
        'enable',
        'install',
        'list',
        'remove',
        'set',
        'show',
        'unset',
        'update',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const array FAMILY_ACTIONS = [
        'dns' => ['resolve'],
        'doctor' => ['doctor'],
        'env' => ['import', 'sync'],
        'firewall' => ['allow', 'deny'],
        'gateway' => ['status', 'trust', 'use'],
        'herdr' => ['adopt', 'observe', 'restart'],
        'instance' => [
            'clone',
            'deploy',
            'deployment-config',
            'prepare-deployment',
            'register',
            'rollback',
        ],
        'metrics' => ['status'],
        'process' => ['logs', 'restart', 'start', 'stop'],
        'schedule' => ['logs', 'run'],
        'workspace' => ['new'],
    ];

    /** @var list<string> */
    public const array NOUN_ENDING_COMMANDS = [
        'metrics:credentials',
        'node:settings',
        'workspace:php',
    ];

    public static function lastSegment(string $name): string
    {
        $position = strrpos($name, ':');

        if ($position === false) {
            return $name;
        }

        return substr($name, $position + 1);
    }

    public static function family(string $name): string
    {
        $position = strpos($name, ':');

        if ($position === false) {
            return $name;
        }

        return substr($name, 0, $position);
    }

    public static function prefix(string $name): ?string
    {
        $position = strrpos($name, ':');

        if ($position === false) {
            return null;
        }

        return substr($name, 0, $position);
    }

    public static function allowsCommand(string $name): bool
    {
        if (in_array($name, self::NOUN_ENDING_COMMANDS, true)) {
            return true;
        }

        $segment = self::lastSegment($name);

        if (in_array($segment, self::VERBS, true)) {
            return true;
        }

        return in_array($segment, self::FAMILY_ACTIONS[self::family($name)] ?? [], true);
    }

    /**
     * @param  list<string>  $commandNames
     */
    public static function routeRequiresMatchingCommand(string $routeName, array $commandNames): bool
    {
        $prefix = self::prefix($routeName);

        if ($prefix === null) {
            return in_array($routeName, self::families($commandNames), true);
        }

        if (! in_array($prefix, self::commandPrefixes($commandNames), true)) {
            return false;
        }

        return self::allowsCommand($routeName);
    }

    /**
     * @return list<string>
     */
    public static function namedRoutesFromApiFile(string $contents): array
    {
        preg_match_all("/->name\(\s*'([^']+)'\s*\)/", $contents, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $commandNames
     * @return list<string>
     */
    public static function families(array $commandNames): array
    {
        $families = array_values(array_unique(array_map(self::family(...), $commandNames)));
        sort($families);

        return $families;
    }

    /**
     * @param  list<string>  $commandNames
     * @return list<string>
     */
    public static function commandPrefixes(array $commandNames): array
    {
        $prefixes = [];

        foreach ($commandNames as $commandName) {
            $prefix = self::prefix($commandName);

            if ($prefix !== null) {
                $prefixes[] = $prefix;
            }
        }

        $prefixes = array_values(array_unique($prefixes));
        sort($prefixes);

        return $prefixes;
    }
}
