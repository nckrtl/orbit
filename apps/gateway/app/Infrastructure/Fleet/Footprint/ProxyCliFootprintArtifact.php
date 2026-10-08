<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\ProxyCli\ProxyCliSourcePublisher;
use App\Models\Node;
use App\Models\Process;

/**
 * The ProxyCli collector script on the collector's Node. A changed script restarts the collector,
 * a Node-owned Process; it is not an Instance Process.
 */
final readonly class ProxyCliFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSourcePublisher $publisher,
        private NodeShell $shell,
        private RestartProcessAction $restart,
        private ?string $scriptPath = null,
    ) {}

    public function name(): string
    {
        return 'proxycli';
    }

    public function applies(Node $node): bool
    {
        return $this->state->enabled() && $this->state->nodeId() === $node->id;
    }

    public function digest(Node $node): string
    {
        return hash('sha256', $this->script());
    }

    public function apply(Node $node): bool
    {
        $result = $this->shell->run($node, $this->publisher->command($this->script()));

        if (! $result->succeeded()) {
            throw new ResourceOperationException('proxycli.source_publication_failed', "Could not install the proxycli collector on node [{$node->name}].", 502);
        }

        if (! str_contains($result->stdout, ProxyCliSourcePublisher::Published)) {
            return false;
        }

        $collector = Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', ProxyCliProcess::NAME)
            ->first();

        if ($collector instanceof Process) {
            $this->restart->execute($collector);
        }

        return true;
    }

    private function script(): string
    {
        $script = @file_get_contents($this->scriptPath ?? resource_path('proxycli/server.py'));

        if (! is_string($script) || $script === '') {
            throw new ResourceOperationException('proxycli.source_missing', 'The proxycli collector script is missing from the Gateway.', 500);
        }

        return $script;
    }
}
