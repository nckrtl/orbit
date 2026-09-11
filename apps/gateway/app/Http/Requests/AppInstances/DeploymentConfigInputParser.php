<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class DeploymentConfigInputParser
{
    /** @throws UnexpectedValueException */
    public function parse(#[\SensitiveParameter] string $json): DeploymentConfig
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

        $topLevelKeys = array_keys(get_object_vars($object));

        if (array_diff($topLevelKeys, ['branch', 'steps']) !== []) {
            $this->fail('The request body contains unsupported top-level keys.');
        }

        if (! property_exists($object, 'branch') || ! is_string($object->branch)) {
            $this->fail('The branch field must be a string.');
        }

        if (! property_exists($object, 'steps') || ! is_array($object->steps)) {
            $this->fail('The steps field must be an array.');
        }

        $steps = [];

        foreach ($object->steps as $step) {
            if (! $step instanceof stdClass) {
                $this->fail('Each deployment step must be an object.');
            }

            $keys = array_keys(get_object_vars($step));

            if (array_diff($keys, ['name', 'phase', 'command', 'timeout_seconds']) !== []) {
                $this->fail('A deployment step contains unsupported keys.');
            }

            if (! property_exists($step, 'name') || ! is_string($step->name)) {
                $this->fail('Each deployment step name must be a string.');
            }

            if (! property_exists($step, 'phase') || ! is_string($step->phase)) {
                $this->fail('Each deployment step phase must be a string.');
            }

            if (! property_exists($step, 'command') || ! is_string($step->command)) {
                $this->fail('Each deployment step command must be a string.');
            }

            if (property_exists($step, 'timeout_seconds') && ! is_int($step->timeout_seconds)) {
                $this->fail('Each deployment step timeout must be an integer.');
            }

            $phase = DeploymentPhase::tryFrom($step->phase);

            if (! $phase instanceof DeploymentPhase) {
                $this->fail('Each deployment step phase must be supported.');
            }

            try {
                $steps[] = new DeploymentStep(
                    name: $step->name,
                    phase: $phase,
                    command: $step->command,
                    timeoutSeconds: property_exists($step, 'timeout_seconds')
                        ? $step->timeout_seconds
                        : DeploymentStep::DefaultTimeoutSeconds,
                );
            } catch (InvalidArgumentException) {
                $this->fail('A deployment step is invalid.');
            }
        }

        try {
            return new DeploymentConfig($object->branch, $steps);
        } catch (InvalidArgumentException) {
            $this->fail('The deployment configuration is invalid.');
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

    /** @throws UnexpectedValueException */
    private function fail(string $message): never
    {
        throw new UnexpectedValueException($message);
    }
}
