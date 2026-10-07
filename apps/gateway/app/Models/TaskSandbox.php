<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Compute\SandboxState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $group_id
 * @property int|null $node_id
 * @property string $provider
 * @property string $name
 * @property SandboxState $state
 * @property string $network_policy
 * @property string $desired_power
 * @property array<string, mixed> $spec
 * @property string|null $credential_fingerprint
 * @property string|null $server_id
 * @property string|null $disk_id
 * @property string|null $public_address
 * @property Carbon|null $create_attempted_at
 * @property Carbon|null $firewall_configured_at
 * @property Carbon|null $destroyed_at
 * @property string|null $error_code
 * @property string|null $pi_token
 */
final class TaskSandbox extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['pi_token'];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'id', 'group_id', 'node_id', 'provider', 'name', 'state', 'desired_power', 'spec',
        'credential_fingerprint', 'network_policy', 'server_id', 'disk_id', 'public_address', 'create_attempted_at', 'firewall_configured_at', 'destroyed_at', 'error_code',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'state' => SandboxState::class, 'spec' => 'array', 'pi_token' => 'encrypted',
            'create_attempted_at' => 'datetime', 'firewall_configured_at' => 'datetime', 'destroyed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'group_id')->withoutGlobalScope('subtask');
    }
}
