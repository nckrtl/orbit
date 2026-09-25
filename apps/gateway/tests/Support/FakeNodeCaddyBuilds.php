<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;
use Closure;

/**
 * Records Node Caddy build requests instead of pushing to a Node. Feature tests get it by default, so no
 * test runs the push script through local `sudo` on the machine that runs the suite.
 */
final class FakeNodeCaddyBuilds implements NodeCaddyBuilds
{
    /** @var list<string> Node names in build order. */
    public array $built = [];

    /** @var list<string> Node names whose listen addresses were checked. */
    public array $checked = [];

    /** @var array<string, NodeCaddyBuildException> A failure for the next build of the named Node. */
    public array $failures = [];

    /** @var (Closure(Node): void)|null Runs at each build, for example to record the stored state it reads. */
    public ?Closure $onBuild = null;

    public function build(Node $node): NodeCaddyBuildResult
    {
        $this->built[] = $node->name;

        if (array_key_exists($node->name, $this->failures)) {
            $failure = $this->failures[$node->name];
            unset($this->failures[$node->name]);

            throw $failure;
        }

        if ($this->onBuild instanceof Closure) {
            ($this->onBuild)($node);
        }

        return NodeCaddyBuildResult::Published;
    }

    public function checkListenAddresses(Node $node): void
    {
        $this->checked[] = $node->name;
    }

    public function failNext(string $nodeName, string $stage = 'validate', string $message = 'Error: test failure'): void
    {
        $this->failures[$nodeName] = new NodeCaddyBuildException($nodeName, $stage, $message);
    }
}
