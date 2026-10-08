<?php

declare(strict_types=1);

namespace App\Domain\Projects;

enum ProjectType: string
{
    case Monorepo = 'monorepo';
    case LaravelApp = 'laravel-app';
    case SymfonyApp = 'symfony-app';
    case LaravelPackage = 'laravel-package';
    case NodePackage = 'node-package';

    public function isWebServing(): bool
    {
        return $this === self::LaravelApp || $this === self::SymfonyApp;
    }

    public function servesPhpByDefault(): bool
    {
        return $this->isWebServing();
    }

    /**
     * The checkout-relative framework entry point the source classifier inspects.
     */
    public function frameworkEntryPoint(): string
    {
        return $this === self::SymfonyApp ? 'bin/console' : 'artisan';
    }
}
