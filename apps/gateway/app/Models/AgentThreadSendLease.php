<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_thread_id
 * @property string $owner_token
 * @property Carbon $expires_at
 */
final class AgentThreadSendLease extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['agent_thread_id', 'owner_token', 'expires_at'];

    /** @return BelongsTo<AgentThread, $this> */
    public function agentThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
