<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $app_id
 * @property int|null $node_id
 * @property int|null $cluster_id
 * @property int|null $generation_basis_node_id
 * @property string $hostname
 * @property RouteProvenance $provenance
 * @property RoutePublication $publication
 * @property RouteStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property-read App $app
 * @property-read Node|null $node
 * @property-read Cluster|null $cluster
 * @property-read Node|null $generationBasisNode
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RouteTarget> $targets
 */
final class Route extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @var array<int, string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'hostname',
        'provenance',
        'publication',
        'status',
        'failed_step',
        'error_code',
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

    /** @return BelongsTo<Node, $this> */
    public function generationBasisNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'generation_basis_node_id');
    }

    /** @return HasMany<RouteTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(RouteTarget::class)->orderBy('position')->orderBy('id');
    }

    /** @return array<string, class-string> */
    protected function casts(): array
    {
        return [
            'provenance' => RouteProvenance::class,
            'publication' => RoutePublication::class,
            'status' => RouteStatus::class,
        ];
    }
}
