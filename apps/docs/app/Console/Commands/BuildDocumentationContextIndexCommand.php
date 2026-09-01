<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\DocumentationContextIndexBuilder;
use Illuminate\Console\Command;

final class BuildDocumentationContextIndexCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:docs-index {--check : Fail when the committed context index is stale}';

    #[\Override]
    protected $description = 'Build or verify the committed Orbit documentation context index.';

    public function handle(DocumentationContextIndexBuilder $builder): int
    {
        if ($this->option('check')) {
            if ($builder->isFresh()) {
                $this->info('Documentation context index is current.');

                return self::SUCCESS;
            }

            $this->error('Documentation context index is stale. Run `composer context:build` from apps/docs.');

            return self::FAILURE;
        }

        $this->info("Wrote {$builder->write()}.");

        return self::SUCCESS;
    }
}
