<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Data\ProxyCli\ProxyCliStatusData;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliState;
use App\Models\Node;

final readonly class DisableProxyCliAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliRuntimeLifecycle $runtime,
        private ProxyCliPublicationManager $publication,
    ) {}

    public function execute(): ProxyCliStatusData
    {
        $nodeId = $this->state->nodeId();
        $node = $nodeId === null ? null : Node::query()->find($nodeId);

        if ($node instanceof Node) {
            $this->runtime->remove($node);
        }

        // Disable before the publication removal: it republishes private DNS, which keeps the collector name
        // while the extension is still enabled.
        $this->state->disable();

        if ($node instanceof Node) {
            $this->publication->remove($node);
        }

        return new ProxyCliStatusData(false, $nodeId, $this->state->cacheConnection(), null, ProxyCliHostname::Value);
    }
}
