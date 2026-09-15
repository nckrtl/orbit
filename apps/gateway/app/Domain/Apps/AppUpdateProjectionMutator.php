<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\App as OrbitApp;

interface AppUpdateProjectionMutator
{
    /**
     * @return array<string, mixed>
     */
    public function preflightSlug(OrbitApp $app, string $newSlug): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareSlug(OrbitApp $app, string $newSlug, array $inventory): array;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function publishSlug(OrbitApp $app, string $newSlug, array $prepared): void;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackSlug(OrbitApp $app, array $prepared): void;

    /**
     * @return array<string, mixed>
     */
    public function preflightRoot(OrbitApp $app, string $newRoot): array;

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function prepareRoot(OrbitApp $app, string $newRoot, array $inventory): array;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function publishRoot(OrbitApp $app, string $newRoot, array $prepared): void;

    /**
     * @param  array<string, mixed>  $prepared
     */
    public function rollbackRoot(OrbitApp $app, array $prepared): void;
}
