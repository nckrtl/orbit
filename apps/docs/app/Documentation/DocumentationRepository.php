<?php

declare(strict_types=1);

namespace App\Documentation;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class DocumentationRepository
{
    public string $docsPath;

    public string $indexPath;

    /** @param list<string> $components */
    public function __construct(
        string $docsPath,
        string $indexPath,
        public array $components,
    ) {
        $resolvedDocsPath = realpath($docsPath);
        if ($resolvedDocsPath === false || ! is_dir($resolvedDocsPath)) {
            throw new RuntimeException("Documentation directory [{$docsPath}] does not exist.");
        }

        $this->docsPath = rtrim(str_replace('\\', '/', $resolvedDocsPath), '/');
        $this->indexPath = str_replace('\\', '/', $indexPath);
    }

    /** @return array<string, string> */
    public function markdownDocuments(): array
    {
        $documents = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->docsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relativePath = ltrim(substr($path, strlen($this->docsPath)), '/');

            if (str_starts_with($relativePath, 'generated/')) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException("Documentation file [{$path}] could not be read.");
            }

            $documents['docs/'.$relativePath] = $contents;
        }

        ksort($documents);

        return $documents;
    }

    public function storedIndex(): ?string
    {
        if (! is_file($this->indexPath)) {
            return null;
        }

        $contents = file_get_contents($this->indexPath);
        if ($contents === false) {
            throw new RuntimeException("Documentation context index [{$this->indexPath}] could not be read.");
        }

        return $contents;
    }

    public function writeIndex(string $contents): void
    {
        $directory = dirname($this->indexPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Documentation context directory [{$directory}] could not be created.");
        }

        if (file_put_contents($this->indexPath, $contents) === false) {
            throw new RuntimeException("Documentation context index [{$this->indexPath}] could not be written.");
        }
    }
}
