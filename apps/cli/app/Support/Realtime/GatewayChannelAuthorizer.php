<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Authorizes a realtime channel subscription the same way the rest of the CLI talks to the
 * gateway: HTTPS to the active profile's URL, pinned to its trusted root CA when one is stored.
 *
 * The gateway authorizes the private orbit channel at `/api/v1/broadcasting/auth`, the versioned
 * API route next to `GET /api/v1/realtime`. This goes through the plain HTTP client rather than
 * the SDK's Saloon connector because the call carries no bearer token: TLS trust (the pinned
 * Orbit CA) is the authorization, the same model as other CLI-to-gateway HTTPS.
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
        $request = Http::baseUrl($this->gatewayUrl)->acceptJson()->withoutRedirecting()->timeout($this->timeoutSeconds);

        if ($this->caPath !== null) {
            if (! is_file($this->caPath) || ! is_readable($this->caPath)) {
                throw new RealtimeConnectionException('The selected realtime CA certificate is unavailable.');
            }

            $request = $request->withOptions(['verify' => $this->caPath]);
        }

        try {
            $response = $request->post('/api/v1/broadcasting/auth', [
                'socket_id' => $socketId,
                'channel_name' => $channelName,
            ]);
        } catch (ConnectionException $exception) {
            throw new RealtimeConnectionException(
                'Could not reach the gateway to authorize the realtime channel.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
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
