<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property RouteKind $kind
 * @property int|null $app_id
 * @property int|null $node_id
 * @property int|null $cluster_id
 * @property int|null $generation_basis_node_id
 * @property string $domain
 * @property RouteProvenance $provenance
 * @property RoutePublication $publication
 * @property RoutePublicPublication $public_publication
 * @property RouteStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property int|null $replaces_route_id
 * @property int|null $replaced_by_route_id
 * @property RouteReplacementStep|null $replacement_step
 * @property array<string, mixed>|null $target_set_intent
 * @property string|null $target_set_step
 * @property-read App|null $app
 * @property-read Node|null $node
 * @property-read Cluster|null $cluster
 * @property-read Node|null $generationBasisNode
 * @property-read Route|null $replaces
 * @property-read Route|null $replacedBy
 * @property-read Collection<int, RouteTarget> $targets
 * @property-read RouteCustomProxy|null $customProxy
 * @property-read RouteAnalyticsTracking|null $analyticsTracking
 */
final class Route extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'kind' => 'app',
        'status' => 'pending',
        'public_publication' => 'inactive',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'kind',
        'app_id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'domain',
        'provenance',
        'publication',
        'public_publication',
        'status',
        'failed_step',
        'error_code',
        'replaces_route_id',
        'replaced_by_route_id',
        'replacement_step',
        'target_set_intent',
        'target_set_step',
    ];

    public function isCustomProxy(): bool
    {
        return $this->kind === RouteKind::CustomProxy;
    }

    public function isAnalyticsTracking(): bool
    {
        return $this->kind === RouteKind::AnalyticsTracking;
    }

    /** An App Route is the only kind that owns App instance targets and an operator-chosen domain or publication. */
    public function isApp(): bool
    {
        return $this->kind === RouteKind::App;
    }

    public function isAuthoritative(): bool
    {
        return $this->status->isAuthoritative();
    }

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

    /** @return BelongsTo<Route, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_route_id');
    }

    /** @return BelongsTo<Route, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_route_id');
    }

    /** @return HasMany<RouteTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(RouteTarget::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasOne<RouteCustomProxy, $this> */
    public function customProxy(): HasOne
    {
        return $this->hasOne(RouteCustomProxy::class);
    }

    /** @return HasOne<RouteAnalyticsTracking, $this> */
    public function analyticsTracking(): HasOne
    {
        return $this->hasOne(RouteAnalyticsTracking::class);
    }

    /** @return array<string, class-string|string> */
    protected function casts(): array
    {
        return [
            'kind' => RouteKind::class,
            'provenance' => RouteProvenance::class,
            'publication' => RoutePublication::class,
            'public_publication' => RoutePublicPublication::class,
            'status' => RouteStatus::class,
            'replacement_step' => RouteReplacementStep::class,
            'target_set_intent' => 'array',
        ];
    }
}
