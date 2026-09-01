<?php

declare(strict_types=1);

namespace App\Providers;

use App\Documentation\DocumentationRepository;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(DocumentationRepository::class, function (): DocumentationRepository {
            $docsPath = config('librarian.path');
            $indexPath = config('orbit-docs.index_path');
            $configuredComponents = config('orbit-docs.components', []);

            if (! is_string($docsPath) || ! is_string($indexPath) || ! is_array($configuredComponents)) {
                throw new LogicException('Orbit documentation paths and components must be configured.');
            }

            $components = [];
            foreach ($configuredComponents as $component) {
                if (! is_string($component) || $component === '') {
                    throw new LogicException('Orbit documentation components must be non-empty strings.');
                }

                $components[] = $component;
            }

            return new DocumentationRepository($docsPath, $indexPath, $components);
        });
    }
}
