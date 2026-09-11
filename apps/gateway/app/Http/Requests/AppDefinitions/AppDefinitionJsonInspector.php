<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDefinitions;

use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class AppDefinitionJsonInspector
{
    /** @return array<string, mixed> */
    public function inspectProcess(#[\SensitiveParameter] string $json): array
    {
        $object = $this->decode($json);
        $this->assertAllowedKeys($object, ['name', 'environments', 'spec'], 'top-level');

        if (property_exists($object, 'spec')) {
            if (! $object->spec instanceof stdClass) {
                $this->fail('The spec field must be a JSON object.');
            }

            $this->assertAllowedKeys($object->spec, [
                'runtime',
                'command',
                'image',
                'working_directory',
                'environment',
                'ports',
                'volumes',
                'restart_policy',
            ], 'process specification');

            if (property_exists($object->spec, 'environment') && ! $object->spec->environment instanceof stdClass) {
                $this->fail('The process environment field must be a JSON object.');
            }

            if (property_exists($object->spec, 'volumes') && is_array($object->spec->volumes)) {
                foreach ($object->spec->volumes as $volume) {
                    if (! $volume instanceof stdClass) {
                        $this->fail('Each process volume must be a JSON object.');
                    }

                    $this->assertAllowedKeys($volume, ['source', 'target', 'read_only'], 'process volume');
                }
            }
        }

        return $this->decodeArray($json);
    }

    /** @return array<string, mixed> */
    public function inspectSchedule(#[\SensitiveParameter] string $json): array
    {
        $object = $this->decode($json);
        $this->assertAllowedKeys($object, ['name', 'environments', 'spec'], 'top-level');

        if (property_exists($object, 'spec')) {
            if (! $object->spec instanceof stdClass) {
                $this->fail('The spec field must be a JSON object.');
            }

            $this->assertAllowedKeys(
                $object->spec,
                ['command', 'calendar', 'timeout_seconds'],
                'Schedule specification',
            );
        }

        return $this->decodeArray($json);
    }

    private function decode(#[\SensitiveParameter] string $json): stdClass
    {
        if (trim($json) === '') {
            $this->fail('The request body must be a valid JSON object.');
        }

        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('The request body must be a valid JSON object.');
        }

        if (! $object instanceof stdClass) {
            $this->fail('The request body must be a valid JSON object.');
        }

        if ($this->hasDuplicateObjectKeys($json)) {
            $this->fail('The request body contains duplicate object keys.');
        }

        return $object;
    }

    /** @return array<string, mixed> */
    private function decodeArray(#[\SensitiveParameter] string $json): array
    {
        try {
            $value = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('The request body must be a valid JSON object.');
        }

        if (! is_array($value)) {
            $this->fail('The request body must be a valid JSON object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param list<string> $allowed */
    private function assertAllowedKeys(stdClass $object, array $allowed, string $boundary): void
    {
        if (array_diff(array_keys(get_object_vars($object)), $allowed) !== []) {
            $this->fail("The {$boundary} object contains unsupported keys.");
        }
    }

    private function hasDuplicateObjectKeys(#[\SensitiveParameter] string $json): bool
    {
        /** @var list<array{type: string, keys?: array<string, true>, expects_key?: bool}> $stack */
        $stack = [];
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];

            if ($character === '"') {
                $end = $this->stringEnd($json, $index + 1);
                $top = count($stack) - 1;

                if ($top >= 0 && $stack[$top]['type'] === 'object' && ($stack[$top]['expects_key'] ?? false)) {
                    $token = substr($json, $index, $end - $index + 1);

                    try {
                        $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $this->fail('The request body must be a valid JSON object.');
                    }

                    if (! is_string($key)) {
                        $this->fail('The request body must be a valid JSON object.');
                    }

                    if (isset($stack[$top]['keys'][$key])) {
                        return true;
                    }

                    $stack[$top]['keys'][$key] = true;
                    $stack[$top]['expects_key'] = false;
                }

                $index = $end;

                continue;
            }

            if ($character === '{') {
                $stack[] = ['type' => 'object', 'keys' => [], 'expects_key' => true];

                continue;
            }

            if ($character === '[') {
                $stack[] = ['type' => 'array'];

                continue;
            }

            if ($character === '}' || $character === ']') {
                array_pop($stack);

                continue;
            }

            if ($character === ',') {
                $top = count($stack) - 1;

                if ($top >= 0 && $stack[$top]['type'] === 'object') {
                    $stack[$top]['expects_key'] = true;
                }
            }
        }

        return false;
    }

    private function stringEnd(#[\SensitiveParameter] string $json, int $index): int
    {
        $escaped = false;
        $length = strlen($json);

        for (; $index < $length; $index++) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($json[$index] === '\\') {
                $escaped = true;

                continue;
            }

            if ($json[$index] === '"') {
                return $index;
            }
        }

        $this->fail('The request body must be a valid JSON object.');
    }

    private function fail(string $message): never
    {
        throw new UnexpectedValueException($message);
    }
}
