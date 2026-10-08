<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

final readonly class PreparedGatewayRelease
{
    public function __construct(
        public string $id,
        public string $sha,
        public string $path,
        public bool $reused,
        public int $durationMs,
    ) {}

    /** @return array{release: string, sha: string, path: string, reused: bool, duration_ms: int} */
    public function toArray(): array
    {
        return [
            'release' => $this->id,
            'sha' => $this->sha,
            'path' => $this->path,
            'reused' => $this->reused,
            'duration_ms' => $this->durationMs,
        ];
    }
}
