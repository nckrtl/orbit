<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencySourceResponse
{
    /** @param array<string, string|null> $fileHashes */
    public function __construct(
        public string $projectRoot,
        public ?string $reference,
        public array $fileHashes,
        public ?string $format,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project_root' => $this->projectRoot,
            'reference' => $this->reference,
            'file_hashes' => $this->fileHashes,
            'format' => $this->format,
        ];
    }
}
