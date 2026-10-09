<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int|null $instance_id
 * @property int $source_node_id
 * @property int|null $source_router_node_id
 * @property int $destination_node_id
 * @property string|null $requested_name
 * @property string $destination_name
 * @property string $destination_path
 * @property string $destination_domain
 * @property string|null $sqlite_source_path
 * @property InstanceSourceLayout $source_layout
 * @property string $source_path
 * @property string|null $common_repository_path
 * @property int $source_route_id
 * @property int|null $destination_route_id
 * @property InstanceTransferStatus $status
 * @property InstanceTransferStep $current_step
 * @property InstanceTransferStep|null $failed_step
 * @property string|null $error_code
 * @property array<string, mixed>|null $recovery_evidence
 * @property list<string>|null $imported_environment_keys
 * @property list<int>|null $web_root_route_ids
 * @property Carbon|null $cutover_at
 * @property Carbon|null $completed_at
 * @property-read Instance $instance
 * @property-read Node $sourceNode
 * @property-read Node $destinationNode
 */
final class InstanceTransfer extends Model
{
    #[\Override]
    protected $table = 'instance_transfers';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'id',
        'instance_id',
        'source_node_id',
        'source_router_node_id',
        'destination_node_id',
        'requested_name',
        'destination_name',
        'destination_path',
        'destination_domain',
        'sqlite_source_path',
        'source_layout',
        'source_path',
        'common_repository_path',
        'source_route_id',
        'destination_route_id',
        'status',
        'current_step',
        'failed_step',
        'error_code',
        'recovery_evidence',
        'imported_environment_keys',
        'web_root_route_ids',
        'cutover_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        self::creating(static function (self $transfer): void {
            $id = $transfer->getAttribute('id');
            $transfer->id = is_string($id) && $id !== '' ? $id : (string) Str::uuid();
        });
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->where(fn (Builder $closed) => $closed
            ->where('status', InstanceTransferStatus::Completed)
            ->orWhere(fn (Builder $rolledBack) => $rolledBack
                ->where('status', InstanceTransferStatus::Failed)
                ->whereNull('cutover_at')
                ->where('current_step', InstanceTransferStep::Reserved)
                ->whereNull('recovery_evidence')
                ->where(fn (Builder $imports) => $imports
                    ->whereNull('imported_environment_keys')
                    ->orWhere('imported_environment_keys', '[]'))));
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNot(fn (Builder $closed) => $closed->closed());
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'source_node_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function destinationNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'destination_node_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_router_node_id' => 'integer',
            'source_layout' => InstanceSourceLayout::class,
            'status' => InstanceTransferStatus::class,
            'current_step' => InstanceTransferStep::class,
            'failed_step' => InstanceTransferStep::class,
            'recovery_evidence' => 'array',
            'imported_environment_keys' => 'array',
            'web_root_route_ids' => 'array',
            'cutover_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
