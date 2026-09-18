<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use DateTimeInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;

/**
 * The single envelope broadcast for every record change on the `orbit`
 * channel: `{ type, id, at, data }`. `data` is the same Spatie Data object
 * shape the record's list/show endpoint returns.
 */
final readonly class RecordBroadcast implements ShouldBroadcastNow
{
    public string $at;

    /** @param array<string, mixed> $data */
    public function __construct(
        public RecordEventType $type,
        public int|string $id,
        public array $data,
    ) {
        $this->at = Carbon::now()->format(DateTimeInterface::ATOM);
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orbit')];
    }

    public function broadcastAs(): string
    {
        return $this->type->value;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'at' => $this->at,
            'data' => $this->data,
        ];
    }
}
