<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** The outcome of one orphan network sweep: deleted names and per-network failures. */
final readonly class NetworkSweepResult
{
    /**
     * @param list<string> $reaped
     * @param array<string, string> $failed Network name to failure message.
     */
    public function __construct(
        public array $reaped,
        public array $failed = [],
    ) {}

    /** @return list<string> `name: message` per failed network. */
    public function failures(): array
    {
        $failures = [];
        foreach ($this->failed as $name => $message) {
            $failures[] = $name.': '.$message;
        }

        return $failures;
    }
}
