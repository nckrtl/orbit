<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

final readonly class DependencySource
{
    /**
     * @param  array<string, string|null>  $fileHashes  Root-relative input paths mapped to SHA-256 hashes; null means verified absent.
     */
    public function __construct(
        public string $projectRoot,
        /** Selected release or checkout revision, when available. */
        public ?string $reference,
        public array $fileHashes,
        /** Parser family and format version; null when the ecosystem is absent. */
        public ?string $format,
    ) {}
}
