<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-type PortOwner array{instance_id:int,node_id:int,app:string,kind:string,old_port:int,new_port:int,retained:bool,operations:list<string>}
 * @phpstan-type StoreMove array{instance_id:int,app:string,old:string,new:string}
 * @phpstan-type MigrationPlan array{owners:list<PortOwner>,stores:list<StoreMove>,vite_files?:list<StoreMove>,processes:list<array{id:int,app:string,fingerprint:string}>}
 *
 * @property string $id
 * @property int $node_id
 * @property string $phase
 * @property MigrationPlan $plan
 * @property array<string,mixed>|null $observations
 * @property CarbonImmutable|null $published_at
 */
final class AppRuntimeMigration extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $fillable = ['id', 'node_id', 'phase', 'plan', 'observations', 'published_at'];

    public static function assertInstanceAvailable(Instance $instance): void
    {
        if (self::query()->where('node_id', $instance->node_id)->where('phase', '!=', 'complete')->exists()) {
            throw new ResourceOperationException('instance.lifecycle_conflict', 'Finish the Node runtime migration before changing this Instance runtime.', 409);
        }
    }

    protected function casts(): array
    {
        return ['plan' => 'array', 'observations' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
