<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class IncusNetwork
{
    /**
     * @param array<string, string> $metadata
     * @param array<string, string> $config
     */
    public function __construct(
        public string $remote,
        public string $project,
        public string $name,
        public array $metadata = [],
        public array $config = [],
    ) {
        foreach ([$remote, $project, $name] as $identity) {
            if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $identity) !== 1) {
                throw new InvalidArgumentException('Invalid Incus network identity.');
            }
        }
    }
}
