<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity Exact external migration input is validated at one boundary. */
final readonly class MigrationPlan
{
    /** @param list<array{role:string,argv:list<string>,stdin:string}> $steps */
    public function __construct(
        public string $fingerprint,
        public array $steps,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1 || $steps === []) {
            throw new InvalidArgumentException('The migration plan is invalid.');
        }

        foreach ($steps as $step) {
            if (
                array_keys($step) !== ['role', 'argv', 'stdin']
                || ! in_array($step['role'], TopologyProfile::ROLES, true)
                || $step['argv'] === []
                || ! is_string($step['stdin'])
            ) {
                throw new InvalidArgumentException('A migration step is invalid.');
            }

            foreach ($step['argv'] as $argument) {
                if (! is_string($argument) || str_contains($argument, "\0")) {
                    throw new InvalidArgumentException('A migration argument is invalid.');
                }
            }
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['fingerprint', 'steps']
            || ! is_string($value['fingerprint'])
            || ! is_array($value['steps'])
        ) {
            throw new InvalidArgumentException('The migration plan schema is invalid.');
        }

        $steps = [];
        foreach ($value['steps'] as $step) {
            if (! is_array($step) || array_is_list($step)) {
                throw new InvalidArgumentException('The migration plan schema is invalid.');
            }
            $role = $step['role'] ?? null;
            $arguments = $step['argv'] ?? null;
            $stdin = $step['stdin'] ?? null;
            if (! is_string($role) || ! is_array($arguments) || ! array_is_list($arguments) || ! is_string($stdin)) {
                throw new InvalidArgumentException('The migration plan schema is invalid.');
            }
            $argv = [];
            foreach ($arguments as $argument) {
                if (! is_string($argument)) {
                    throw new InvalidArgumentException('The migration plan schema is invalid.');
                }
                $argv[] = $argument;
            }
            $steps[] = ['role' => $role, 'argv' => $argv, 'stdin' => $stdin];
        }

        return new self($value['fingerprint'], $steps);
    }
}
