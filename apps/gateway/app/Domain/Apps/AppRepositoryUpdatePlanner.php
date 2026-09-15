<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use Illuminate\Support\Collection;

final readonly class AppRepositoryUpdatePlanner
{
    /**
     * @param  Collection<int, AppInstance>  $instances
     * @return array{
     *     checkouts: list<AppInstance>,
     *     worktrees: list<AppInstance>,
     *     production: list<AppInstance>
     * }
     */
    public function inventory(Collection $instances): array
    {
        $checkouts = [];
        $worktrees = [];
        $production = [];

        foreach ($instances as $instance) {
            if ($instance->environment === 'production') {
                $production[] = $instance;

                continue;
            }

            if ($instance->source_layout === AppInstanceSourceLayout::Worktree->value) {
                $worktrees[] = $instance;

                continue;
            }

            if ($instance->source_layout === AppInstanceSourceLayout::Checkout->value) {
                $checkouts[] = $instance;
            }
        }

        return [
            'checkouts' => $checkouts,
            'worktrees' => $worktrees,
            'production' => $production,
        ];
    }

    /**
     * @param  list<AppInstance>  $checkouts
     * @param  list<AppInstance>  $worktrees
     */
    public function assertWorktreesOwned(array $checkouts, array $worktrees): void
    {
        foreach ($worktrees as $worktree) {
            if ($this->ownedCheckout($checkouts, $worktree) instanceof AppInstance) {
                continue;
            }

            throw new ResourceOperationException(
                errorCode: 'app.repository_unowned_common',
                message: 'A worktree uses a common repository that no Orbit-owned checkout owns.',
                status: 409,
            );
        }
    }

    /**
     * @param  list<AppInstance>  $checkouts
     * @return list<string>
     */
    public function uniqueCheckoutPaths(array $checkouts): array
    {
        $paths = [];

        foreach ($checkouts as $checkout) {
            $paths[rtrim($checkout->checkout_path, '/')] = true;
        }

        return array_keys($paths);
    }

    /**
     * @param  list<AppInstance>  $checkouts
     */
    public function ownedCheckout(array $checkouts, AppInstance $worktree): ?AppInstance
    {
        foreach ($checkouts as $checkout) {
            if ($this->ownsCommonRepository($checkout, $worktree)) {
                return $checkout;
            }
        }

        return null;
    }

    private function ownsCommonRepository(AppInstance $checkout, AppInstance $worktree): bool
    {
        if ($checkout->node_id !== $worktree->node_id) {
            return false;
        }

        $common = $worktree->registration_common_repository_path;

        if (! is_string($common) || $common === '') {
            return false;
        }

        $checkoutPath = rtrim($checkout->checkout_path, '/');
        $common = rtrim($common, '/');

        if ($checkoutPath === $common) {
            return true;
        }

        if (str_ends_with($common, '/.git') && $checkoutPath === substr($common, 0, -5)) {
            return true;
        }

        $checkoutCommon = $checkout->registration_common_repository_path;

        return is_string($checkoutCommon) && rtrim($checkoutCommon, '/') === $common;
    }
}
