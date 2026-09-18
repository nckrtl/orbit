<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Throwable;

/** A scripted RealtimeChannelAuthorizer double for tests, paired with FakeWebSocketTransport. */
final class FakeRealtimeChannelAuthorizer implements RealtimeChannelAuthorizer
{
    /** @var list<array{socket_id: string, channel_name: string}> */
    public array $calls = [];

    public function __construct(
        private readonly string $auth = 'fake-key:fake-signature',
        private readonly ?Throwable $failure = null,
    ) {}

    #[\Override]
    public function authorize(string $socketId, string $channelName): string
    {
        $this->calls[] = ['socket_id' => $socketId, 'channel_name' => $channelName];

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->auth;
    }
}
