<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Models\Node;
use App\Models\Route;

final class RecordingProxyCliPublicationManager implements ProxyCliPublicationManager
{
    public bool $converged = false;

    public bool $removed = false;

    public ?int $nodeId = null;

    public ?int $takeoverRouteId = null;

    public function converge(Node $node, int $port = ProxyCliProcess::PORT, ?Route $takeover = null): void
    {
        $this->converged = true;
        $this->nodeId = $node->id;
        $this->takeoverRouteId = $takeover?->id;
    }

    public function remove(Node $node): void
    {
        $this->removed = true;
        $this->nodeId = $node->id;
    }
}
