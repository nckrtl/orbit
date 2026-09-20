<?php

declare(strict_types=1);

namespace App\Domain\Projects;

final readonly class ProjectTypeClassifier
{
    public const string OrbitRepositoryIdentity = 'github.com/nckrtl/orbit';

    /**
     * @param  array{
     *     slug: string,
     *     repository_identity: string,
     *     root: string|null,
     *     has_production_php: bool
     * }  $project
     */
    public function classify(array $project): ProjectType
    {
        if ($this->isOrbitMonorepo($project['slug'], $project['repository_identity'])) {
            return ProjectType::Monorepo;
        }

        if ($this->isServingLaravel($project['root'], $project['has_production_php'])) {
            return ProjectType::LaravelApp;
        }

        return ProjectType::LaravelPackage;
    }

    public function isOrbitMonorepo(string $slug, string $repositoryIdentity): bool
    {
        return $repositoryIdentity === self::OrbitRepositoryIdentity
            || ($slug === 'orbit' && str_ends_with($repositoryIdentity, '/orbit'));
    }

    public function isServingLaravel(?string $root, bool $hasProductionPhp): bool
    {
        if ($hasProductionPhp) {
            return true;
        }

        if (! is_string($root) || $root === '') {
            return false;
        }

        return $root === 'public' || str_ends_with($root, '/public');
    }
}
