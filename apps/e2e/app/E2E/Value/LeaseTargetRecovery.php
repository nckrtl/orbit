<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** Exact operator evidence for recovering one legacy lease without a persisted target. */
final readonly class LeaseTargetRecovery
{
    private function __construct(
        public AttemptId $expectedAttempt,
        public ?TopologyExtension $extension,
    ) {}

    public static function fromOptions(mixed $extension, mixed $expectedAttempt): ?self
    {
        $extension = $extension === false ? null : $extension;
        $expectedAttempt = $expectedAttempt === false ? null : $expectedAttempt;
        if ($extension === null && $expectedAttempt === null) {
            return null;
        }
        if (! is_string($extension) || ! is_string($expectedAttempt)) {
            throw new InvalidArgumentException(
                'Use --recover-extension and --expected-attempt together.',
            );
        }
        if (! in_array($extension, ['none', TopologyExtension::AppProd->value], true)) {
            throw new InvalidArgumentException('The recovery extension must be none or app-prod.');
        }

        return new self(
            new AttemptId($expectedAttempt),
            $extension === 'none' ? null : TopologyExtension::AppProd,
        );
    }
}
