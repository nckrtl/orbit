<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\AgentationSiteProjection;
use App\Models\AppInstance;

final class FakeAgentationSiteProjection implements AgentationSiteProjection
{
    /** @var list<int> */
    public array $projected = [];

    public function project(AppInstance $instance): void
    {
        $this->projected[] = $instance->id;
    }
}
