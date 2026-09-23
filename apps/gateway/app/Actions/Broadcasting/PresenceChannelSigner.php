<?php

declare(strict_types=1);

namespace App\Actions\Broadcasting;

use App\Domain\Broadcasting\RealtimeConnectionData;
use JsonException;

final readonly class PresenceChannelSigner
{
    /**
     * Returns the Pusher presence authorization for one member: `auth` signs the socket, channel, and exact `channel_data`.
     *
     * @param  array{kind: string, node_id: int, version?: ?string}  $userInfo
     * @return array{auth: string, channel_data: string}
     */
    public function sign(string $socketId, string $channel, RealtimeConnectionData $connection, string $member, array $userInfo): array
    {
        try {
            $channelData = json_encode(['user_id' => $member, 'user_info' => $userInfo], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \LogicException('Presence channel data could not be encoded.', previous: $exception);
        }

        $auth = $connection->key.':'.hash_hmac('sha256', "{$socketId}:{$channel}:{$channelData}", $connection->secret);

        return ['auth' => $auth, 'channel_data' => $channelData];
    }
}
