<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $route_id
 * @property int $node_id
 * @property int|null $process_id
 * @property string $upstream
 * @property-read Route|null $route
 * @property-read Node|null $node
 * @property-read Process|null $process
 */
final class RouteCustomProxy extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'int';

    #[\Override]
    protected $primaryKey = 'route_id';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'route_id',
        'node_id',
        'process_id',
        'upstream',
    ];

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }
}
