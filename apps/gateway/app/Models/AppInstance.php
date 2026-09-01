<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\AppInstanceState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property int $node_id
 * @property int $cluster_id
 * @property string $name
 * @property string $environment
 * @property string $checkout_path
 * @property string|null $root
 * @property string|null $branch
 * @property string|null $starting_commit
 * @property AppInstanceState $status
 * @property-read App $app
 * @property-read Node $node
 * @property-read Cluster $cluster
 */
final class AppInstance extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'environment' => 'development',
        'status' => 'reserved',
    ];

    /** @var array<int, string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'node_id',
        'cluster_id',
        'name',
        'environment',
        'checkout_path',
        'root',
        'branch',
        'starting_commit',
        'status',
    ];

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

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function effectiveRoot(): ?string
    {
        return $this->root ?? $this->app->root;
    }

    /** @return array<string, class-string> */
    protected function casts(): array
    {
        return ['status' => AppInstanceState::class];
    }
}
