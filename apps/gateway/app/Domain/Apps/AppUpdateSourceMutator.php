<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\App as OrbitApp;
use App\Models\AppInstance;

interface AppUpdateSourceMutator
{
    /**
     * @param  list<AppInstance>  $checkouts
     */
    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void;

    /**
     * @param  list<AppInstance>  $checkouts
     * @param  list<array{app_id?: int, instance_id?: int, node_id?: int, path: string, previous_url: string, current_url: string, mutated: bool}>  $evidence
     * @return list<array{app_id: int, instance_id: int, node_id: int, path: string, previous_url: string, current_url: string, mutated: bool}>
     */
    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array;

    /**
     * @param  list<array{app_id?: int, instance_id?: int, node_id?: int, path: string, previous_url: string, current_url: string, mutated: bool}>  $mutations
     */
    public function restoreOrigins(OrbitApp $app, array $mutations): void;

    public function preflightDefaultBranch(AppInstance $instance, string $newBranch): void;

    public function switchDefaultBranch(AppInstance $instance, string $newBranch): void;

    public function restoreDefaultBranch(AppInstance $instance, string $previousBranch): void;
}
