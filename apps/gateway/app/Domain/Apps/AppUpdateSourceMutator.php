<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\Instance;

interface AppUpdateSourceMutator
{
    /**
     * @param  list<Instance>  $checkouts
     */
    public function preflightRepository(array $checkouts, string $currentUrl, string $proposedUrl): void;

    /**
     * @param  list<Instance>  $checkouts
     * @param  list<array{path: string, previous_url: string, current_url: string, mutated: bool}>  $evidence
     * @return list<array{path: string, previous_url: string, current_url: string, mutated: bool}>
     */
    public function changeOrigins(array $checkouts, string $previousUrl, string $newUrl, array $evidence): array;

    /**
     * @param  list<array{path: string, previous_url: string, current_url: string, mutated: bool}>  $mutations
     */
    public function restoreOrigins(array $mutations): void;

    public function preflightDefaultBranch(Instance $instance, string $newBranch): void;

    public function switchDefaultBranch(Instance $instance, string $newBranch): void;

    public function restoreDefaultBranch(Instance $instance, string $previousBranch): void;
}
