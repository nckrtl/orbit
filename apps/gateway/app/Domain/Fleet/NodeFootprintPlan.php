<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** The digests of the artifacts the Gateway renders for one Node, and the digest of them all. */
final readonly class NodeFootprintPlan
{
    /** @param array<string, string> $artifacts  Artifact name to digest, sorted by name. */
    public function __construct(public array $artifacts) {}

    /** @param array<string, string> $artifacts */
    public static function of(array $artifacts): self
    {
        ksort($artifacts);

        return new self($artifacts);
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->artifacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
