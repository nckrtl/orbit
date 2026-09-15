<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

/** Transient source contents, never an API or persistence payload. */
final readonly class CollectedDependencyFiles
{
    /**
     * @param  array<string, string|null>  $contents
     * @param  array<string, string|null>  $hashes
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public string $projectRoot,
        public ?string $reference,
        public string $identity,
        public array $contents,
        public array $hashes,
        public array $errors,
    ) {}
}
