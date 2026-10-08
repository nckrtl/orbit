<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Projects\ProjectType;
use JsonException;

final readonly class ComposerSourceClassifier
{
    public function __construct(
        private InstancePhpVersionCatalog $php,
    ) {}

    /**
     * @param  string  $entryPointKind  The kind of the type's framework entry point (`artisan`, or `bin/console` for a Symfony app): regular, absent, or unsafe.
     */
    public function classify(string $json, ProjectType $projectType, string $entryPointKind): DevelopmentSourceProfile
    {
        try {
            $composer = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->invalid('app-dev.php_version_unsupported', $exception);
        }

        if (! is_array($composer)) {
            throw $this->invalid('app-dev.php_version_unsupported');
        }

        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $requireDev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];
        $constraint = $require['php'] ?? null;

        if ($constraint !== null && ! is_string($constraint)) {
            throw $this->invalid('app-dev.php_version_unsupported');
        }

        try {
            $version = $this->php->select($constraint);
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalid('app-dev.php_version_unsupported', $exception);
        }

        if ($projectType === ProjectType::SymfonyApp) {
            $symfony = array_key_exists('symfony/framework-bundle', $require);

            if ($entryPointKind === 'unsafe' || ($entryPointKind === 'regular') !== $symfony) {
                throw $this->invalid('app-dev.symfony_source_invalid');
            }

            if ($symfony && ! is_string($require['symfony/framework-bundle'])) {
                throw $this->invalid('app-dev.symfony_source_invalid');
            }

            return new DevelopmentSourceProfile($version, false);
        }

        $laravelDeclarations = array_values(array_filter(
            [
                array_key_exists('laravel/framework', $require) ? $require['laravel/framework'] : null,
                array_key_exists('laravel/framework', $requireDev) ? $requireDev['laravel/framework'] : null,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));

        if ($projectType !== ProjectType::LaravelPackage) {
            if ($entryPointKind === 'unsafe' || ($entryPointKind === 'regular') !== (count($laravelDeclarations) === 1)) {
                throw $this->invalid('app-dev.laravel_source_invalid');
            }
        }

        if ($laravelDeclarations !== [] && ! is_string($laravelDeclarations[0])) {
            throw $this->invalid('app-dev.laravel_source_invalid');
        }

        return new DevelopmentSourceProfile(
            $version,
            $projectType !== ProjectType::LaravelPackage && $entryPointKind === 'regular',
        );
    }

    private function invalid(string $errorCode, ?\Throwable $previous = null): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: 'source-classification',
            errorCode: $errorCode,
            message: 'The development source metadata is invalid or unsupported.',
            previous: $previous,
        );
    }
}
