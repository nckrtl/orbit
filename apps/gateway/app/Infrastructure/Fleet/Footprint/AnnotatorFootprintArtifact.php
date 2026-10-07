<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Processes\AnnotatorServerInstallation;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

/**
 * The annotator server files in `/opt/orbit/annotator` on a Node that runs an annotator Process. The
 * release directory is named after the digest, so a matching release is reused. Running annotators
 * belong to Instances and keep their code until their Process restarts; the footprint never restarts
 * them.
 */
final readonly class AnnotatorFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private NodeShell $shell,
        private AnnotatorServerInstallation $installation = new AnnotatorServerInstallation,
    ) {}

    public function name(): string
    {
        return 'annotator';
    }

    public function applies(Node $node): bool
    {
        return Process::query()
            ->where('owner_type', Instance::class)
            ->whereIn('owner_id', Instance::query()->where('node_id', $node->id)->select('id'))
            ->get()
            ->contains(static fn (Process $process): bool => $process->isAnnotator());
    }

    public function digest(Node $node): string
    {
        return hash('sha256', (string) $this->installation->command()->input);
    }

    public function apply(Node $node): ?bool
    {
        $result = $this->shell->run($node, $this->installation->command());

        if (! $result->succeeded()) {
            throw new ResourceOperationException('process.annotator_install_failed', 'The annotator server files could not be published.', 502);
        }

        return null;
    }
}
