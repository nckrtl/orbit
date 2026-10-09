<?php

declare(strict_types=1);

namespace App\Infrastructure\T3;

use App\Domain\T3\T3AdminSession;
use App\Domain\T3\T3ClientSession;
use App\Domain\T3\T3EnvironmentDescriptor;
use App\Domain\T3\T3PairingCredential;
use App\Domain\T3\T3ServerClient;
use App\Domain\T3\T3ServerException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Speaks T3 Code's environment HTTP API (`packages/contracts/src/environmentHttp.ts` in T3 Code).
 */
final readonly class HttpT3ServerClient implements T3ServerClient
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int TIMEOUT_SECONDS = 10;

    public function describe(string $baseUrl): T3EnvironmentDescriptor
    {
        $body = $this->json($baseUrl, 'describe', fn (): Response => $this->request()->get($this->endpoint($baseUrl, '/.well-known/t3/environment')));

        $environmentId = $body['environmentId'] ?? null;
        $label = $body['label'] ?? null;
        $serverVersion = $body['serverVersion'] ?? null;

        if (! is_string($environmentId) || ! is_string($label) || ! is_string($serverVersion)) {
            throw T3ServerException::malformed($baseUrl, 'describe');
        }

        return new T3EnvironmentDescriptor($environmentId, $label, $serverVersion);
    }

    public function exchangePairingToken(string $baseUrl, #[SensitiveParameter] string $pairingToken, string $clientLabel): T3AdminSession
    {
        $body = $this->json($baseUrl, 'token exchange', fn (): Response => $this->request()->asForm()->post($this->endpoint($baseUrl, '/oauth/token'), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $pairingToken,
            'subject_token_type' => 'urn:t3:params:oauth:token-type:environment-bootstrap',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'client_label' => $clientLabel,
            'client_device_type' => 'bot',
        ]));

        $token = $body['access_token'] ?? null;
        $tokenType = $body['token_type'] ?? null;
        $expiresIn = $body['expires_in'] ?? null;
        $scope = $body['scope'] ?? null;

        if (! is_string($token) || $token === '' || $tokenType !== 'Bearer' || ! is_numeric($expiresIn) || ! is_string($scope)) {
            throw T3ServerException::malformed($baseUrl, 'token exchange');
        }

        return new T3AdminSession(
            token: $token,
            expiresAt: CarbonImmutable::now()->addSeconds((int) $expiresIn),
            scopes: array_values(array_filter(explode(' ', $scope), static fn (string $value): bool => $value !== '')),
        );
    }

    public function issuePairingCredential(string $baseUrl, #[SensitiveParameter] string $adminSession, string $label): T3PairingCredential
    {
        $body = $this->json($baseUrl, 'pairing-token', fn (): Response => $this->request($adminSession)
            ->post($this->endpoint($baseUrl, '/api/auth/pairing-token'), ['label' => $label]));

        $id = $body['id'] ?? null;
        $credential = $body['credential'] ?? null;
        $expiresAt = $body['expiresAt'] ?? null;

        if (! is_string($id) || ! is_string($credential) || $credential === '' || ! is_string($expiresAt)) {
            throw T3ServerException::malformed($baseUrl, 'pairing-token');
        }

        return new T3PairingCredential($id, $credential, $this->timestamp($baseUrl, 'pairing-token', $expiresAt));
    }

    public function clientSessions(string $baseUrl, #[SensitiveParameter] string $adminSession): array
    {
        $body = $this->json($baseUrl, 'clients', fn (): Response => $this->request($adminSession)
            ->get($this->endpoint($baseUrl, '/api/auth/clients')));

        if (! array_is_list($body)) {
            throw T3ServerException::malformed($baseUrl, 'clients');
        }

        $sessions = [];

        foreach ($body as $session) {
            $sessionId = is_array($session) ? ($session['sessionId'] ?? null) : null;

            if (! is_array($session) || ! is_string($sessionId)) {
                throw T3ServerException::malformed($baseUrl, 'clients');
            }

            $client = is_array($session['client'] ?? null) ? $session['client'] : [];
            $label = $client['label'] ?? null;

            $sessions[] = new T3ClientSession(
                sessionId: $sessionId,
                label: is_string($label) ? $label : null,
                current: ($session['current'] ?? false) === true,
            );
        }

        return $sessions;
    }

    public function revokeClientSession(string $baseUrl, #[SensitiveParameter] string $adminSession, string $sessionId): bool
    {
        $body = $this->json($baseUrl, 'clients/revoke', fn (): Response => $this->request($adminSession)
            ->post($this->endpoint($baseUrl, '/api/auth/clients/revoke'), ['sessionId' => $sessionId]));

        return ($body['revoked'] ?? false) === true;
    }

    public function revokePairingLink(string $baseUrl, #[SensitiveParameter] string $adminSession, string $pairingLinkId): bool
    {
        $body = $this->json($baseUrl, 'pairing-links/revoke', fn (): Response => $this->request($adminSession)
            ->post($this->endpoint($baseUrl, '/api/auth/pairing-links/revoke'), ['id' => $pairingLinkId]));

        return ($body['revoked'] ?? false) === true;
    }

    private function request(#[SensitiveParameter] ?string $adminSession = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);

        return $adminSession === null ? $request : $request->withToken($adminSession);
    }

    private function endpoint(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').$path;
    }

    /**
     * @param  \Closure(): Response  $send
     * @return array<array-key, mixed>
     */
    private function json(string $baseUrl, string $operation, \Closure $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            throw T3ServerException::unreachable($baseUrl, $exception);
        }

        if (! $response->successful()) {
            $reason = $response->json('reason');

            throw T3ServerException::refused($baseUrl, $operation, $response->status(), is_string($reason) ? $reason : 'unknown');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw T3ServerException::malformed($baseUrl, $operation);
        }

        return $body;
    }

    private function timestamp(string $baseUrl, string $operation, string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw T3ServerException::malformed($baseUrl, $operation);
        }
    }
}
