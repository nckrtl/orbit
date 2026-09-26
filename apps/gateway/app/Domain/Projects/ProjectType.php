<?php

declare(strict_types=1);

namespace App\Domain\Projects;

enum ProjectType: string
{
    case Monorepo = 'monorepo';
    case LaravelApp = 'laravel-app';
    case LaravelPackage = 'laravel-package';
    case NodePackage = 'node-package';

    public function isWebServing(): bool
    {
        return $this === self::LaravelApp;
    }

    public function servesPhpByDefault(): bool
    {
        return $this === self::LaravelApp;
    }

    /**
     * The task check command a new Project of this type gets when the caller sends none (ADR 0125).
     */
    public function defaultTaskCheck(): ?string
    {
        return match ($this) {
            self::LaravelApp, self::LaravelPackage => 'composer check',
            self::Monorepo, self::NodePackage => null,
        };
    }
}
