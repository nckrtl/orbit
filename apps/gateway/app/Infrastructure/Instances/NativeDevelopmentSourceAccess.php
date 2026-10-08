<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Models\Instance;
use Illuminate\Support\Collection;

final readonly class NativeDevelopmentSourceAccess implements DevelopmentSourceAccess
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
    ) {}

    public function grant(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instance->loadMissing('node');
        $command = new DevelopmentCaddyAccessCommand;
        $sites = $command->walkedSites($this->sites($instance));

        if ($sites->isEmpty()) {
            return;
        }

        $this->ssh->execute(
            $instance->node,
            $command->command($sites),
            step: 'source-access',
            errorCode: 'app-dev.source_access_failed',
        );
    }

    /**
     * The access walk covers the Instance's checkout and any served checkout nested in it, because
     * the recursive deny on the Instance's tree would otherwise revoke a nested worktree's Web root.
     * Every other checkout on the Node keeps the access its own convergence granted, so the walk
     * does not grow with the Node's other Instances.
     *
     * @return Collection<int, DevelopmentSite>
     */
    private function sites(Instance $instance): Collection
    {
        $checkout = rtrim($instance->checkout_path, '/');

        if ($checkout === '') {
            return collect();
        }

        return new DevelopmentSiteRepository()->forNode($instance->node)
            ->filter(static fn (DevelopmentSite $site): bool => $site->checkoutPath === $checkout
                || str_starts_with($site->checkoutPath, $checkout.'/'))
            ->values();
    }
}
