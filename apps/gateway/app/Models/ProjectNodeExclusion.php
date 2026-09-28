<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $node_id
 * @property-read Project $project
 * @property-read Node $node
 */
final class ProjectNodeExclusion extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['project_id', 'node_id'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function developmentInstanceCount(): int
    {
        return Instance::query()
            ->where('project_id', $this->project_id)
            ->where('node_id', $this->node_id)
            ->with('node.roles')
            ->get()
            ->reject(static fn (Instance $instance): bool => $instance->placedOnAppProd())
            ->count();
    }
}
