<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class VerificationReport
{
    /** @param array<array-key, mixed> $probes */
    public function __construct(
        public bool $passed,
        public array $probes,
    ) {
        if ($probes === [] || array_is_list($probes)) {
            throw new InvalidArgumentException('The verification report requires named probes.');
        }

        foreach ($probes as $name => $result) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $name) !== 1 || ! is_bool($result)) {
                throw new InvalidArgumentException('A verification probe is invalid.');
            }
        }

        if ($passed !== ! in_array(false, $probes, true)) {
            throw new InvalidArgumentException('The verification result does not match its probes.');
        }
    }

    /** @return array{passed:bool,probes:array<string,bool>} */
    public function toArray(): array
    {
        /** @var array<string, bool> $probes */
        $probes = $this->probes;

        return ['passed' => $this->passed, 'probes' => $probes];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['passed', 'probes']
            || ! is_bool($value['passed'])
            || ! is_array($value['probes'])
        ) {
            throw new InvalidArgumentException('The verification report schema is invalid.');
        }

        return new self($value['passed'], $value['probes']);
    }
}
