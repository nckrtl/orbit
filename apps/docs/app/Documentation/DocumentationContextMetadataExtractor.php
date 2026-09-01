<?php

declare(strict_types=1);

namespace App\Documentation;

final readonly class DocumentationContextMetadataExtractor
{
    public function __construct(
        private DocumentationRepository $repository,
    ) {}

    /** @param list<string> $canonicalConcepts */
    public function entry(string $path, string $contents, array $canonicalConcepts): DocumentationContextEntry
    {
        return new DocumentationContextEntry([
            'path' => $path,
            'title' => $this->title($path, $contents),
            'kind' => $this->kind($path),
            'components' => $this->components($contents),
            'concepts' => $this->concepts($contents, $canonicalConcepts),
            'governing_adrs' => $this->governingAdrs($path, $contents),
        ]);
    }

    /** @return list<string> */
    public function canonicalConcepts(string $contents): array
    {
        preg_match_all('/^- \*\*([^*]+)\*\*/m', $contents, $matches);
        /** @var list<string> $concepts */
        $concepts = $matches[1] ?? [];

        $normalized = [];
        foreach ($concepts as $concept) {
            if ($concept !== '') {
                $normalized[] = $concept;
            }
        }

        $concepts = array_values(array_unique($normalized));
        sort($concepts);

        return $concepts;
    }

    private function title(string $path, string $contents): string
    {
        if (preg_match('/^#\s+(?<title>.+?)\s*$/m', $contents, $matches) === 1) {
            return trim($matches['title']);
        }

        return pathinfo($path, PATHINFO_FILENAME);
    }

    private function kind(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'docs/decisions/') => 'decision',
            str_starts_with($path, 'docs/domains/') => 'domain',
            str_starts_with($path, 'docs/reference/') => 'reference',
            str_starts_with($path, 'docs/solutions/') => 'solution',
            default => 'product',
        };
    }

    /** @return list<string> */
    private function components(string $contents): array
    {
        $components = [];
        foreach ($this->repository->components as $component) {
            if (str_contains($contents, $component)) {
                $components[] = $component;
            }
        }

        sort($components);

        return $components;
    }

    /**
     * @param  list<string>  $canonicalConcepts
     * @return list<string>
     */
    private function concepts(string $contents, array $canonicalConcepts): array
    {
        $concepts = [];
        foreach ($canonicalConcepts as $concept) {
            $pattern = '/(?<![\pL\pN])'.preg_quote($concept, '/').'(?![\pL\pN])/iu';
            if (preg_match($pattern, $contents) === 1) {
                $concepts[] = $concept;
            }
        }

        sort($concepts);

        return $concepts;
    }

    /** @return list<string> */
    private function governingAdrs(string $path, string $contents): array
    {
        preg_match_all('/(?<![0-9])([0-9]{4})-[a-z0-9-]+\.md/', $contents, $matches);
        /** @var list<string> $adrs */
        $adrs = $matches[1] ?? [];

        if (preg_match('#^docs/decisions/(?<adr>[0-9]{4})-#', $path, $ownMatch) === 1) {
            $adrs[] = $ownMatch['adr'];
        }

        $adrs = array_values(array_unique(array_filter($adrs, is_string(...))));
        sort($adrs);

        return $adrs;
    }
}
