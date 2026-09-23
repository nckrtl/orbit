<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\NodeAgentRuntime;
use App\Models\Node;
use Closure;

final class FakeNodeAgentRuntime implements NodeAgentRuntime
{
    /** @var list<int> */
    public array $nodeIds = [];

    public ?Closure $failure = null;

    public function converge(Node $node): void
    {
        $this->nodeIds[] = (int) $node->id;

        if ($this->failure instanceof Closure) {
            ($this->failure)($node);
        }
    }
}
