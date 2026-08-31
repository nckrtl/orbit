<?php

declare(strict_types=1);

namespace App\Support;

use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettings;

final readonly class NodeSettingOptions
{
    /** @var list<string> */
    private const array KNOWN = ['apps.path'];

    /**
     * @return array{ok: true, provided: bool, body: array{apps?: array{path: string}|null}}|array{ok: false, code: string, message: string}
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

            $separator = strpos(haystack: $option, needle: ':');

            if ($separator === false || $separator === 0) {
                return self::invalid();
            }

            $key = substr(string: $option, offset: 0, length: $separator);
            $value = substr(string: $option, offset: $separator + 1);

            if (! in_array($key, self::KNOWN, strict: true)) {
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

            $body['apps'] = $nested;
        }

        return ['ok' => true, 'provided' => true, 'body' => $body];
    }

    /** @param array{apps?: array{path: string}|null} $body */
    public static function settings(array $body): NodeSettings
    {
        return new NodeSettings(
            apps: array_key_exists('apps', $body) ? self::apps($body['apps']) : null,
        );
    }

    public static function apps(mixed $value): ?AppsSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return new AppsSettings(path: is_string($path) ? $path : null);
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
