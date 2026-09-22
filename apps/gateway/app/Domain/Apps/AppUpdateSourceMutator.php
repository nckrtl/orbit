<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use Closure;

interface AppUpdateSourceMutator
{
    /**
     * @param  list<AppInstance>  $checkouts
     */
    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void;

    /**
     * @param  list<AppInstance>  $checkouts
     * @param  list<array{app_id?: int, instance_id?: int, node_id?: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>  $evidence
     * @param  Closure(list<array{app_id: int, instance_id: int, node_id: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>): void  $recordEvidence
     * @return list<array{app_id: int, instance_id: int, node_id: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>
     */
    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence, Closure $recordEvidence): array;

    /**
     * @param  list<array{app_id?: int, instance_id?: int, node_id?: int, path: string, previous_url: string, current_url: string, mutated: bool, attempted?: bool}>  $mutations
     */
    public function restoreOrigins(OrbitApp $app, array $mutations): void;

    public function preflightDefaultBranch(AppInstance $instance, string $newBranch): void;

    public function switchDefaultBranch(AppInstance $instance, string $newBranch): void;

    public function restoreDefaultBranch(AppInstance $instance, string $previousBranch): void;
}
