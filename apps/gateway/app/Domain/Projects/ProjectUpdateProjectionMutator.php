<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;

interface ProjectUpdateProjectionMutator
{
    /**
     * @return array<string, mixed>
     */
    public function preflightSlug(Project $project, string $newSlug): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareSlug(Project $project, string $newSlug, array $inventory): array;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function publishSlug(Project $project, string $newSlug, array $prepared): void;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackSlug(Project $project, array $prepared): void;
}
