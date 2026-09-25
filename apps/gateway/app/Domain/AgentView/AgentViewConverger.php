<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

/** Installs, enables, and restarts the agent view subscriber on the Gateway host. */
interface AgentViewConverger
{
    public function converge(): void;
}
