<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * How the turn command treats this turn. A consult or a relay accepts `answered`.
 * A review that follows a direction resolution requires `--cause` on every outcome.
 */
final readonly class TaskTurnMode
{
    public function __construct(
        public bool $consult = false,
        public bool $relay = false,
        public bool $causeRequired = false,
    ) {}
}
