<?php

declare(strict_types=1);

namespace App\Documentation;

use JsonException;
use UnexpectedValueException;

final readonly class DocumentationContextIndex
{
    public const int SCHEMA_VERSION = 1;

    /** @param list<DocumentationContextEntry> $documents */
    public function __construct(
        public array $documents,
    ) {}

    /** @return array{schema_version:int,documents:list<array{path:string,title:string,kind:string,components:list<string>,concepts:list<string>,governing_adrs:list<string>}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'documents' => array_map(
                static fn (DocumentationContextEntry $document): array => $document->toArray(),
                $this->documents,
            ),
        ];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return (
            json_encode(
                $this->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )."\n"
        );
    }

    /** @throws JsonException */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new UnexpectedValueException('Documentation context index schema is unsupported.');
        }

        $documents = $data['documents'] ?? null;
        if (! is_array($documents)) {
            throw new UnexpectedValueException('Documentation context index must contain a documents array.');
        }

        $entries = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                throw new UnexpectedValueException('Documentation context documents must be objects.');
            }

            $entries[] = DocumentationContextEntry::fromArray($document);
        }

        return new self($entries);
    }

    /** @param list<string> $components @param list<string> $concepts */
    public function filtered(array $components, array $concepts): self
    {
        $documents = array_values(array_filter(
            $this->documents,
            static fn (DocumentationContextEntry $document): bool => $document->matches($components, $concepts),
        ));

        usort(
            $documents,
            static fn (DocumentationContextEntry $left, DocumentationContextEntry $right): int => (
                [$left->priority(), $left->path] <=> [$right->priority(), $right->path]
            ),
        );

        return new self($documents);
    }
}
