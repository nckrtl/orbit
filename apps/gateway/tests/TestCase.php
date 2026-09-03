<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function fakeRepositoryBranches(string $defaultBranch = 'main'): void
    {
        $this->app->instance(
            RepositoryDefaultBranchResolver::class,
            new class($defaultBranch) implements RepositoryDefaultBranchResolver {
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
