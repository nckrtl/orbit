<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // Tests read the tracked `.env.example`, as CI does, and never the untracked `.env`. A checkout without
        // `.env` would otherwise give every test a PHP warning from the environment loader, and a developer's
        // `.env` would make local results differ from CI.
        $app->loadEnvironmentFrom('.env.example');

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn (): mixed => $this->markRoutesCached($app));
        }

        TestDatabaseGuard::register($app);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function fakeRepositoryBranches(string $defaultBranch = 'main'): void
    {
        $this->app->instance(
            RepositoryDefaultBranchResolver::class,
            new class($defaultBranch) implements RepositoryDefaultBranchResolver
            {
                public function __construct(
                    private readonly string $defaultBranch,
                ) {}

                public function resolve(string $repository): string
                {
                    return $this->defaultBranch;
                }

                public function verify(string $repository, string $branch): void {}
            },
        );
    }

    protected function markAsGateway(Node $node): Node
    {
        NodeRole::query()->updateOrCreate(
            ['node_id' => $node->id, 'role' => RoleName::Gateway],
            ['status' => LifecycleStatus::Active],
        );

        return $node->refresh();
    }
}
