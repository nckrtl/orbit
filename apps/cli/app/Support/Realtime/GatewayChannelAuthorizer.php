<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Authorizes a realtime channel subscription the same way the rest of the CLI talks to the
 * gateway: HTTPS to the active profile's URL, pinned to its trusted root CA when one is stored.
 *
 * The gateway's `/broadcasting/auth` route is Laravel's own broadcasting auth endpoint, not a
 * versioned Gateway API route, so this goes through the plain HTTP client rather than the SDK's
 * Saloon connector. The CLI does not otherwise carry a bearer token for gateway requests; today
 * that TLS trust is the whole of "the CLI's usual" authorization, and this call reuses exactly it.
 */
final readonly class GatewayChannelAuthorizer implements RealtimeChannelAuthorizer
{
    public function __construct(
        private string $gatewayUrl,
        private ?string $caPath,
        private int $timeoutSeconds = 10,
    ) {}

    #[\Override]
    public function authorize(string $socketId, string $channelName): string
    {
        $request = Http::baseUrl($this->gatewayUrl)->acceptJson()->timeout($this->timeoutSeconds);

        if ($this->caPath !== null && is_file($this->caPath)) {
            $request = $request->withOptions(['verify' => $this->caPath]);
        }

        try {
            $response = $request->post('/broadcasting/auth', [
                'socket_id' => $socketId,
                'channel_name' => $channelName,
            ]);
        } catch (ConnectionException $exception) {
            throw new RealtimeConnectionException(
                'Could not reach the gateway to authorize the realtime channel.',
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new RealtimeConnectionException(
                "Realtime channel authorization failed with HTTP status {$response->status()}.",
            );
        }

        $auth = $response->json('auth');

        if (! is_string($auth) || $auth === '') {
            throw new RealtimeProtocolException('Realtime channel authorization response omitted the auth signature.');
        }

        return $auth;
    }
}
