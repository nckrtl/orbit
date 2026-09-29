<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Models\Instance;

final readonly class CheckoutRemovalBoundary
{
    public function __construct(
        private ProtectedPathCatalog $catalog,
    ) {}

    public function instanceRoot(Instance $instance, ManagedUserAccount $account): StoragePath
    {
        $checkout = StoragePath::tryParse($instance->checkout_path);

        if (
            ! $checkout instanceof StoragePath
            || ! $checkout->hasSuffix($instance->project->slug, $instance->name)
        ) {
            $this->unsafeInstance($instance);
        }

        $root = $checkout->stripSuffix($instance->project->slug, $instance->name);

        if ($this->catalog->isProtected($root, $account) || ! $checkout->isInside($root)) {
            $this->unsafeInstance($instance);
        }

        return $root;
    }

    public function instanceGroupingDirectory(Instance $instance, StoragePath $root): StoragePath
    {
        return $root->append($instance->project->slug);
    }

    private function unsafeInstance(Instance $instance): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-path',
            errorCode: 'instance.checkout_path_unsafe',
            message: "Instance [{$instance->name}] has an unsafe checkout path.",
        );
    }
}
