<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property int $node_id
 * @property-read App $app
 * @property-read Node $node
 */
final class ProjectNodeExclusion extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['app_id', 'node_id'];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function developmentInstanceCount(): int
    {
        return AppInstance::query()
            ->where('app_id', $this->app_id)
            ->where('node_id', $this->node_id)
            ->with('node.roles')
            ->get()
            ->reject(static fn (AppInstance $instance): bool => $instance->placedOnAppProd())
            ->count();
    }
}
