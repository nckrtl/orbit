<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStatus;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int|null $app_instance_id
 * @property int $source_node_id
 * @property int|null $source_router_node_id
 * @property int $destination_node_id
 * @property string|null $requested_name
 * @property string $destination_name
 * @property string $destination_path
 * @property string $destination_domain
 * @property string|null $sqlite_source_path
 * @property AppInstanceSourceLayout $source_layout
 * @property string $source_path
 * @property string|null $common_repository_path
 * @property int $source_route_id
 * @property int|null $destination_route_id
 * @property AppInstanceTransferStatus $status
 * @property AppInstanceTransferStep $current_step
 * @property AppInstanceTransferStep|null $failed_step
 * @property string|null $error_code
 * @property array<string, mixed>|null $recovery_evidence
 * @property array<string, mixed>|null $archive_attempt
 * @property Carbon|null $cutover_at
 * @property Carbon|null $completed_at
 * @property-read AppInstance $appInstance
 * @property-read Node $sourceNode
 * @property-read Node $destinationNode
 */
final class AppInstanceTransfer extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'id',
        'app_instance_id',
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
        'archive_attempt',
        'cutover_at',
        'completed_at',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['archive_attempt'];

    protected static function booted(): void
    {
        self::creating(static function (self $transfer): void {
            $id = $transfer->getAttribute('id');
            $transfer->id = is_string($id) && $id !== '' ? $id : (string) Str::uuid();
        });
    }

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
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
            'source_layout' => AppInstanceSourceLayout::class,
            'status' => AppInstanceTransferStatus::class,
            'current_step' => AppInstanceTransferStep::class,
            'failed_step' => AppInstanceTransferStep::class,
            'recovery_evidence' => 'array',
            'archive_attempt' => 'array',
            'cutover_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
