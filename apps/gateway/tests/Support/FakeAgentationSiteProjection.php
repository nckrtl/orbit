<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\AgentationSiteProjection;
use App\Models\Instance;

final class FakeAgentationSiteProjection implements AgentationSiteProjection
{
    /** @var list<int> */
    public array $projected = [];

    public ?\Closure $onProject = null;

    public function project(Instance $instance): void
    {
        $this->projected[] = $instance->id;
        ($this->onProject)?->__invoke($instance);
    }
}
