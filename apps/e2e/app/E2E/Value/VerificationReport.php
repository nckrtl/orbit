<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity Probe validation keeps the serialized evidence contract explicit. */
final readonly class VerificationReport
{
    /**
     * @param array<array-key, mixed> $probes
     */
    public function __construct(
        public bool $passed,
        public array $probes,
    ) {
        if ($probes === [] || array_is_list($probes)) {
            throw new InvalidArgumentException('The verification report requires named probes.');
        }

        foreach ($probes as $name => $result) {
            if (
                ! is_string($name)
                || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $name) !== 1
                || ! is_array($result)
                || array_keys($result) !== ['passed', 'checked_at', 'expected', 'observed', 'evidence_ref']
                || ! is_bool($result['passed'] ?? null)
                || ! is_string($result['checked_at'] ?? null)
                || preg_match(
                    '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D',
                    $result['checked_at'],
                ) !== 1
                || ! is_string($result['expected'] ?? null)
                || $result['expected'] === ''
                || ! is_string($result['observed'] ?? null)
                || $result['observed'] === ''
                || ! is_string($result['evidence_ref'] ?? null)
                || preg_match(
                    '/\Aincus:\/\/[a-z0-9][a-z0-9.-]{0,127}\/[a-z][a-z0-9_.-]{0,63}\z/D',
                    $result['evidence_ref'],
                ) !== 1
            ) {
                throw new InvalidArgumentException('A verification probe is invalid.');
            }
        }

        $failed = array_filter($probes, static fn (mixed $probe): bool => ($probe['passed'] ?? false) !== true);
        if ($passed !== ($failed === [])) {
            throw new InvalidArgumentException('The verification result does not match its probes.');
        }
    }

    /**
     * @return array{
     *     passed:bool,
     *     probes:array<string,array{passed:bool,checked_at:string,expected:string,observed:string,evidence_ref:string}>
     * }
     */
    public function toArray(): array
    {
        /** @var array<string, array{passed:bool,checked_at:string,expected:string,observed:string,evidence_ref:string}> $probes */
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
