<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;
use Closure;

/**
 * Appends `build:<node>` and `check:<node>` to a caller's event list, so a test can assert where a
 * publisher requests its Node Caddy build among its other steps.
 */
final class RecordingNodeCaddyBuilds implements NodeCaddyBuilds
{
    /** @param list<string> $events */
    /** @param (Closure(): void)|null $onBuild Runs inside each build, to observe the state a build sees. */
    public function __construct(
        private array &$events,
        private ?NodeCaddyBuildException $failure = null,
        private ?Closure $onBuild = null,
    ) {}

    public function build(Node $node): NodeCaddyBuildResult
    {
        $this->events[] = "build:{$node->name}";

        if ($this->onBuild instanceof Closure) {
            ($this->onBuild)();
        }

        if ($this->failure instanceof NodeCaddyBuildException) {
            throw $this->failure;
        }

        return NodeCaddyBuildResult::Published;
    }

    public function checkListenAddresses(Node $node): void
    {
        $this->events[] = "check:{$node->name}";

        if ($this->failure instanceof NodeCaddyBuildException) {
            throw $this->failure;
        }
    }
}
