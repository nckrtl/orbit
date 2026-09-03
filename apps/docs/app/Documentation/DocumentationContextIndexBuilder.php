<?php

declare(strict_types=1);

namespace App\Documentation;

use JsonException;
use RuntimeException;

final readonly class DocumentationContextIndexBuilder
{
    public function __construct(
        private DocumentationRepository $repository,
        private DocumentationContextMetadataExtractor $metadata,
    ) {}

    public function build(): DocumentationContextIndex
    {
        $documents = $this->repository->markdownDocuments();
        $concepts = $this->metadata->canonicalConcepts($documents['docs/concepts.md'] ?? '');
        $entries = [];

        foreach ($documents as $path => $contents) {
            $entries[] = $this->metadata->entry($path, $contents, $concepts);
        }

        usort(
            $entries,
            static fn (DocumentationContextEntry $left, DocumentationContextEntry $right): int => (
                $left->path <=> $right->path
            ),
        );

        return new DocumentationContextIndex($entries);
    }

    /** @throws JsonException */
    public function isFresh(): bool
    {
        $stored = $this->repository->storedIndex();

        return $stored !== null && hash_equals($this->build()->toJson(), $stored);
    }

    /** @throws JsonException */
    public function write(): string
    {
        $this->repository->writeIndex($this->build()->toJson());

        return 'docs/generated/context.json';
    }

    /** @throws JsonException */
    public function committed(): DocumentationContextIndex
    {
        $stored = $this->repository->storedIndex();
        if ($stored === null) {
            throw new RuntimeException(
                'Documentation context index is missing. Run `composer docs-build` from the repository root.',
            );
        }

        return DocumentationContextIndex::fromJson($stored);
    }
}
