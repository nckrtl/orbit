<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Gateway-rendered footprint a Node last received through `node:converge` or the fleet rollout:
 * the digest of each artifact and of the whole footprint, and whether the Node's CLI is one Orbit installed.
 *
 * @property int $id
 * @property int $node_id
 * @property string|null $digest
 * @property array<string, string>|null $artifacts
 * @property Carbon|null $converged_at
 * @property string|null $cli_state
 * @property Carbon|null $cli_checked_at
 * @property-read Node $node
 */
final class NodeFootprint extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'digest', 'artifacts', 'converged_at', 'cli_state', 'cli_checked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['artifacts' => 'array', 'converged_at' => 'datetime', 'cli_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
