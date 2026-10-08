<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Routes\RouteRemovalStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The projections an offline Route removal left on a Node it could not reach.
 *
 * @property int $id
 * @property int $node_id
 * @property int $route_id
 * @property string $domain
 * @property list<string> $steps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
final class RouteRemovalResidue extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'route_id',
        'domain',
        'steps',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'route_id' => 'integer',
            'steps' => 'array',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return list<RouteRemovalStep> */
    public function removalSteps(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $step): ?RouteRemovalStep => RouteRemovalStep::tryFrom((string) $step),
            $this->steps,
        )));
    }
}
