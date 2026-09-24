<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** The label `--record` gives one entry of `<worktree>/.e2e/evidence.log`. */
final readonly class EvidenceLabel
{
    private const string PATTERN = '/\A(?=[A-Za-z0-9 ._-]*[^ ])[A-Za-z0-9 ._-]{1,80}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'The --record label must be 1 to 80 letters, digits, spaces, dots, dashes, or underscores.',
            );
        }
    }
}
