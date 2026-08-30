<?php

declare(strict_types=1);

namespace App\Support;

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
