<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\NullToolManagerMaterializer;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ToolManagerMaterializer::class, new NullToolManagerMaterializer);
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
