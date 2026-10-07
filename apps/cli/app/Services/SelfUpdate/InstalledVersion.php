<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

/** A binary as found on disk: the version it is known to be, when known, and its SHA-256. */
final readonly class InstalledVersion
{
    public function __construct(
        public ?string $version,
        public ?string $sha256,
    ) {}

    /** @return array{version: ?string, sha256: ?string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'sha256' => $this->sha256];
    }
}
