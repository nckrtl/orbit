<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Models\AppInstance;

final readonly class CheckoutRemovalBoundary
{
    public function __construct(
        private ProtectedPathCatalog $catalog,
    ) {}

    public function appInstanceRoot(AppInstance $appInstance, ManagedUserAccount $account): StoragePath
    {
        $checkout = StoragePath::tryParse($appInstance->checkout_path);

        if (
            ! $checkout instanceof StoragePath
            || ! $checkout->hasSuffix($appInstance->app->slug, $appInstance->name)
        ) {
            $this->unsafeAppInstance($appInstance);
        }

        $root = $checkout->stripSuffix($appInstance->app->slug, $appInstance->name);

        if ($this->catalog->isProtected($root, $account, 'apps') || ! $checkout->isInside($root)) {
            $this->unsafeAppInstance($appInstance);
        }

        return $root;
    }

    public function appInstanceGroupingDirectory(AppInstance $appInstance, StoragePath $root): StoragePath
    {
        return $root->append($appInstance->app->slug);
    }

    private function unsafeAppInstance(AppInstance $appInstance): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-path',
            errorCode: 'instance.checkout_path_unsafe',
            message: "AppInstance [{$appInstance->name}] has an unsafe checkout path.",
        );
    }
}
