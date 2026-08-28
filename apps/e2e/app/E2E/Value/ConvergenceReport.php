<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class ConvergenceReport
{
    /** @param array<string, bool> $steps */
    public function __construct(
        public bool $converged,
        public array $steps,
    ) {
        /** @mago-expect analysis:impossible-type-comparison Runtime callers can violate the declared array shape. */
        if ($steps === [] || array_is_list($steps)) {
            throw new InvalidArgumentException('Convergence steps must be named.');
        }
        foreach ($steps as $name => $passed) {
            /** @mago-expect analysis:redundant-type-comparison Runtime callers can violate the declared array shape. */
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $name) !== 1 || ! is_bool($passed)) {
                throw new InvalidArgumentException('A convergence step is invalid.');
            }
        }
        if ($converged !== ! in_array(false, haystack: $steps, strict: true)) {
            throw new InvalidArgumentException('Convergence result does not match steps.');
        }
    }

    /** @param array<string, bool> $steps */
    public static function successful(array $steps): self
    {
        return new self(true, $steps);
    }

    /** @return array{converged:bool,steps:array<string,bool>} */
    public function toArray(): array
    {
        return ['converged' => $this->converged, 'steps' => $this->steps];
    }
}
