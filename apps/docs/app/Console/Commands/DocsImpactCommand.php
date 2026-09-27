<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\DocsImpact;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class DocsImpactCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:docs-impact {--base=} {--paths=*}';

    #[\Override]
    protected $description = 'Report documentation pages and generators affected by repository changes.';

    /** @throws JsonException */
    public function handle(): int
    {
        $docsPath = config('librarian.path');
        if (! is_string($docsPath)) {
            $this->error('The documentation repository path is not configured.');

            return self::FAILURE;
        }
        $root = dirname($docsPath);
        $base = $this->option('base');
        $paths = $this->option('paths');
        if ($base !== null && trim($base) === '') {
            $this->error('The --base commit must not be empty.');

            return self::FAILURE;
        }
        if (array_filter($paths, static fn (?string $path): bool => $path === null || trim($path) === '') !== []) {
            $this->error('Every --paths value must be a non-empty repository-relative path.');

            return self::FAILURE;
        }
        try {
            $report = new DocsImpact($root)->report($base, array_values(array_map(static fn (?string $path): string => $path ?? '', $paths)));
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
