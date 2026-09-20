<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * The two database Processes the analytics role keeps its data in.
 *
 * The role records Process IDs, never only a Node, so the choice of server
 * is never ambiguous.
 */
final readonly class AnalyticsRoleSettings
{
    public function __construct(
        public int $postgresProcessId,
        public int $clickhouseProcessId,
    ) {}
}
