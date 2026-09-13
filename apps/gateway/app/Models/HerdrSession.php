<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property string $session
 * @property string $user
 * @property int|null $process_id
 * @property int $observer_port
 * @property string $observer_hostname
 * @property string|null $observer_url
 * @property string $observer_status
 * @property string|null $observer_error
 * @property LifecycleStatus $status
 * @property string|null $herdr_version
 * @property int|null $protocol
 * @property bool $handoff_supported
 * @property bool $publish_observer
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property-read Node $node
 * @property-read Process|null $process
 */
final class HerdrSession extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'node_id',
        'session',
        'user',
        'process_id',
        'observer_port',
        'observer_hostname',
        'observer_url',
        'observer_status',
        'observer_error',
        'status',
        'herdr_version',
        'protocol',
        'handoff_supported',
        'publish_observer',
        'failed_step',
        'error_code',
    ];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observer_port' => 'integer',
            'protocol' => 'integer',
            'handoff_supported' => 'boolean',
            'publish_observer' => 'boolean',
            'status' => LifecycleStatus::class,
        ];
    }
}
