<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeCliInstallation;
use App\Models\Node;
use App\Models\NodeFootprint as NodeFootprintRecord;

/**
 * Whether a Node's `/usr/local/bin/orbit` is a CLI Orbit did not install. Such a Node leaves the rollout set
 * with the reason `foreign_cli`, never halts a rollout, and Doctor reports `node.cli_foreign`. The catch-up
 * looks again on every run, so the Node rejoins once an operator moved the file aside.
 */
final readonly class NodeCliState
{
    public function isForeign(Node $node): bool
    {
        return NodeFootprintRecord::query()->where('node_id', $node->id)->value('cli_state') === NodeCliInstallation::Foreign;
    }

    public function markForeign(Node $node): void
    {
        NodeFootprintRecord::query()->updateOrCreate(['node_id' => $node->id], ['cli_state' => NodeCliInstallation::Foreign, 'cli_checked_at' => now()]);
    }

    public function clear(Node $node): void
    {
        NodeFootprintRecord::query()->where('node_id', $node->id)->update(['cli_state' => null, 'cli_checked_at' => now()]);
    }
}
