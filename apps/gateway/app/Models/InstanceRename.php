<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int|null $instance_id
 * @property string|null $app
 * @property string|null $domain
 * @property bool $branch_supplied
 * @property string|null $branch
 * @property int|null $source_route_id
 * @property string $phase
 */
final class InstanceRename extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    #[\Override]
    protected $fillable = ['id', 'instance_id', 'app', 'domain', 'branch_supplied', 'branch', 'source_route_id', 'phase'];

    /**
     * Call while holding the Instance environment owner locks.
     *
     * @param  list<int>  $instanceIds
     */
    public static function assertAvailable(array $instanceIds, ?self $owner = null, ?Route $route = null, ?string $domain = null): void
    {
        InstanceAppProjection::assertAvailable($instanceIds);
        foreach (self::query()->whereIn('instance_id', $instanceIds)->where('phase', '!=', 'complete')->get() as $journal) {
            if ($owner !== null && $journal->id === $owner->id && $journal->phase === 'requested'
                && count($instanceIds) === 1 && $route !== null && $route->app === $journal->app
                && ($route->id === $journal->source_route_id || $route->replaces_route_id === $journal->source_route_id)
                && $domain === $journal->domain) {
                continue;
            }
            throw new ResourceOperationException('instance.lifecycle_busy', 'Finish the Instance rename before changing its Routes or runtime.', 409);
        }
    }

    /** Call while holding the Project's Instance environment owner locks. */
    public static function assertProjectAvailable(Project $project): void
    {
        self::assertAvailable(array_values($project->instances()->pluck('id')->map(static fn (mixed $id): int => StoredInteger::from($id))->all()));
    }

    #[\Override]
    protected function casts(): array
    {
        return ['branch_supplied' => 'boolean', 'source_route_id' => 'integer'];
    }
}
