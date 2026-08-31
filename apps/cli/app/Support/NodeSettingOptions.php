<?php

declare(strict_types=1);

namespace App\Support;

use Orbit\Sdk\Responses\Nodes\InstanceSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Orbit\Sdk\Responses\Nodes\WorktreeSettings;

/** @mago-expect lint:cyclomatic-complexity Closed setting-path parsing stays beside the typed DTO mapping. */
final readonly class NodeSettingOptions
{
    /** @var list<string> */
    private const array KNOWN = ['instance.path', 'worktree.path'];

    /**
     * @return array{ok: true, provided: bool, body: array{instance?: array{path: string}|null, worktree?: array{path: string}|null}}|array{ok: false, code: string, message: string}
     */
    public static function parse(mixed $options): array
    {
        if (! is_array($options) || $options === []) {
            return ['ok' => true, 'provided' => false, 'body' => []];
        }

        $body = [];
        $seen = [];

        foreach ($options as $option) {
            if (! is_string($option)) {
                return self::invalid();
            }

            $separator = strpos($option, ':');

            if ($separator === false || $separator === 0) {
                return self::invalid();
            }

            $key = substr($option, 0, $separator);
            $value = substr($option, $separator + 1);

            if (! in_array($key, self::KNOWN, true)) {
                return [
                    'ok' => false,
                    'code' => 'node.setting_unknown',
                    'message' => "Unknown setting [{$key}].",
                ];
            }

            if (array_key_exists($key, $seen)) {
                return [
                    'ok' => false,
                    'code' => 'node.setting_duplicate',
                    'message' => "Setting [{$key}] was supplied more than once.",
                ];
            }

            $seen[$key] = true;
            $nested = $value === '' ? null : ['path' => $value];

            if ($key === 'instance.path') {
                $body['instance'] = $nested;

                continue;
            }

            $body['worktree'] = $nested;
        }

        return ['ok' => true, 'provided' => true, 'body' => $body];
    }

    /** @param array{instance?: array{path: string}|null, worktree?: array{path: string}|null} $body */
    public static function settings(array $body): NodeSettings
    {
        return new NodeSettings(
            instance: array_key_exists('instance', $body) ? self::instance($body['instance']) : null,
            worktree: array_key_exists('worktree', $body) ? self::worktree($body['worktree']) : null,
        );
    }

    public static function instance(mixed $value): ?InstanceSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return new InstanceSettings(is_string($path) ? $path : null);
    }

    public static function worktree(mixed $value): ?WorktreeSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return new WorktreeSettings(is_string($path) ? $path : null);
    }

    /** @return array{ok: false, code: string, message: string} */
    private static function invalid(): array
    {
        return [
            'ok' => false,
            'code' => 'node.setting_invalid',
            'message' => 'Each --setting option must be <setting-path>:<value>.',
        ];
    }
}
