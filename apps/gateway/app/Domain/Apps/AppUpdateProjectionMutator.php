<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\Project;

interface AppUpdateProjectionMutator
{
    /**
     * @return array<string, mixed>
     */
    public function preflightSlug(Project $app, string $newSlug): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareSlug(Project $app, string $newSlug, array $inventory): array;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function publishSlug(Project $app, string $newSlug, array $prepared): void;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackSlug(Project $app, array $prepared): void;

    /**
     * @return array<string, mixed>
     */
    public function preflightRoot(Project $app, string $newRoot): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareRoot(Project $app, string $newRoot, array $inventory): array;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function publishRoot(Project $app, string $newRoot, array $prepared): void;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackRoot(Project $app, array $prepared): void;
}
