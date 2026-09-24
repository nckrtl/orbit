<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use Carbon\CarbonImmutable;
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
 * @property RouteStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property int|null $replaces_route_id
 * @property int|null $replaced_by_route_id
 * @property RouteReplacementStep|null $replacement_step
 * @property array<string, mixed>|null $target_set_intent
 * @property string|null $target_set_step
 * @property bool $sites_published
 * @property int|null $transition_node_id
 * @property int|null $transition_cluster_id
 * @property CarbonImmutable|null $transition_dns_moved_at
 * @property-read App|null $app
 * @property-read Node|null $node
 * @property-read Cluster|null $cluster
 * @property-read Node|null $generationBasisNode
 * @property-read Route|null $replaces
 * @property-read Route|null $replacedBy
 * @property-read Node|null $transitionNode
 * @property-read Cluster|null $transitionCluster
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
        'sites_published' => false,
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
        'status',
        'failed_step',
        'error_code',
        'replaces_route_id',
        'replaced_by_route_id',
        'replacement_step',
        'target_set_intent',
        'target_set_step',
        'sites_published',
        'transition_node_id',
        'transition_cluster_id',
        'transition_dns_moved_at',
    ];

    /**
     * An authoritative Route always serves its sites, so it always keeps its publication record.
     * Creation sets the record before its first build; removal clears it together with leaving the
     * authoritative states.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $route): void {
            if ($route->status->isAuthoritative()) {
                $route->sites_published = true;
            }
        });
    }

    public function isCustomProxy(): bool
    {
        return $this->kind === RouteKind::CustomProxy;
    }

    public function isAnalyticsTracking(): bool
    {
        return $this->kind === RouteKind::AnalyticsTracking;
    }

    /** A Project Route is the only kind that owns Instance targets and an operator-chosen domain or publication. */
    public function isApp(): bool
    {
        return $this->kind === RouteKind::App;
    }

    public function isAuthoritative(): bool
    {
        return $this->status->isAuthoritative();
    }

    /**
     * Sets the publication record before the first build that renders the Route's sites, once
     * the certificates those sites name exist. It writes only that column, so it never races other
     * Route state. Removal and a failed creation clear the record together with their status.
     */
    public function publishSites(): void
    {
        self::query()
            ->whereKey($this->id)
            ->where('sites_published', false)
            ->update(['sites_published' => true]);
        $this->setAttribute('sites_published', true);
        $this->syncOriginalAttribute('sites_published');
    }

    /** A placement change stores its second placement until its cleanup finishes. */
    public function hasPlacementTransition(): bool
    {
        return $this->transition_node_id !== null || $this->transition_cluster_id !== null;
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

    /** @return BelongsTo<Node, $this> */
    public function transitionNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'transition_node_id');
    }

    /** @return BelongsTo<Cluster, $this> */
    public function transitionCluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'transition_cluster_id');
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
            'status' => RouteStatus::class,
            'replacement_step' => RouteReplacementStep::class,
            'target_set_intent' => 'array',
            'sites_published' => 'boolean',
            'transition_dns_moved_at' => 'immutable_datetime',
        ];
    }
}
