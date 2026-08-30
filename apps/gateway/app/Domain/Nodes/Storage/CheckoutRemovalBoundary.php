<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Models\Instance;
use App\Models\Workspace;

/** @mago-expect lint:cyclomatic-complexity Origin-specific removal roots stay in one fail-closed boundary. */
final readonly class CheckoutRemovalBoundary
{
    public function __construct(
        private ProtectedPathCatalog $catalog,
    ) {}

    public function instanceRoot(Instance $instance, ManagedUserAccount $account): StoragePath
    {
        $checkout = StoragePath::tryParse($instance->checkout_path);

        if (! $checkout instanceof StoragePath || ! $checkout->hasSuffix($instance->app->slug)) {
            $this->unsafeInstance($instance);
        }

        $root = $checkout->stripSuffix($instance->app->slug);

        if ($this->catalog->isProtected($root, $account) || ! $checkout->isInside($root)) {
            $this->unsafeInstance($instance);
        }

        return $root;
    }

    public function workspaceRoot(Workspace $workspace, ManagedUserAccount $account): StoragePath
    {
        $origin = $workspace->checkout_path_origin;
        $checkout = StoragePath::tryParse($workspace->checkout_path);

        if (! $checkout instanceof StoragePath) {
            $this->unsafeWorkspace($workspace);
        }

        if ($origin === CheckoutPathOrigin::Derived->value) {
            $app = $workspace->instance->app->slug;
            $name = $workspace->name;

            if (! $checkout->hasSuffix($app, $name)) {
                $this->unsafeWorkspace($workspace);
            }

            $root = $checkout->stripSuffix($app, $name);

            if ($this->catalog->isProtected($root, $account) || ! $checkout->isInside($root)) {
                $this->unsafeWorkspace($workspace);
            }

            return $root;
        }

        if ($origin !== CheckoutPathOrigin::Explicit->value && $origin !== null) {
            $this->unsafeWorkspace($workspace);
        }

        $this->assertExplicitPath($checkout, $account, $workspace);

        return StoragePath::parse($account->home);
    }

    public function groupingDirectory(Workspace $workspace, StoragePath $root): ?StoragePath
    {
        if ($workspace->checkout_path_origin !== CheckoutPathOrigin::Derived->value) {
            return null;
        }

        return $root->append($workspace->instance->app->slug);
    }

    private function assertExplicitPath(
        StoragePath $checkout,
        ManagedUserAccount $account,
        Workspace $workspace,
    ): void {
        $home = StoragePath::tryParse($account->home);

        if (! $home instanceof StoragePath || ! $checkout->isInside($home)) {
            $this->unsafeWorkspace($workspace);
        }

        $relative = substr($checkout->value, strlen($home->value) + 1);
        $segments = explode('/', $relative);

        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/\A[A-Za-z0-9._-]+\z/', $segment) !== 1) {
                $this->unsafeWorkspace($workspace);
            }
        }

        if ($this->catalog->isProtected($checkout, $account)) {
            $this->unsafeWorkspace($workspace);
        }

        $apps = $home->append('apps');

        if ($checkout->equals($apps) || $checkout->isInside($apps)) {
            $this->unsafeWorkspace($workspace);
        }
    }

    private function unsafeInstance(Instance $instance): never
    {
        throw new RuntimeConvergenceException(
            step: 'instance-source-path',
            errorCode: 'instance.checkout_path_unsafe',
            message: "Instance [{$instance->name}] has an unsafe checkout path.",
        );
    }

    private function unsafeWorkspace(Workspace $workspace): never
    {
        throw new RuntimeConvergenceException(
            step: 'workspace-source-path',
            errorCode: 'workspace.checkout_path_unsafe',
            message: "Workspace [{$workspace->name}] has an unsafe checkout path.",
        );
    }
}
