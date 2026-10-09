<?php

declare(strict_types=1);

namespace App\Domain\T3;

use SensitiveParameter;

/**
 * The calls the Gateway makes to a T3 Code server. `$baseUrl` is the URL that registration stored,
 * and `$adminSession` is the bearer token the Gateway got from the server's admin pairing link.
 * Every method throws T3ServerException when the server is unreachable or refuses the call.
 */
interface T3ServerClient
{
    /** GET /.well-known/t3/environment */
    public function describe(string $baseUrl): T3EnvironmentDescriptor;

    /** POST /oauth/token with a token-exchange grant for the pairing token. */
    public function exchangePairingToken(string $baseUrl, #[SensitiveParameter] string $pairingToken, string $clientLabel): T3AdminSession;

    /** POST /api/auth/pairing-token */
    public function issuePairingCredential(string $baseUrl, #[SensitiveParameter] string $adminSession, string $label): T3PairingCredential;

    /**
     * GET /api/auth/clients
     *
     * @return list<T3ClientSession>
     */
    public function clientSessions(string $baseUrl, #[SensitiveParameter] string $adminSession): array;

    /** POST /api/auth/clients/revoke */
    public function revokeClientSession(string $baseUrl, #[SensitiveParameter] string $adminSession, string $sessionId): bool;

    /** POST /api/auth/pairing-links/revoke */
    public function revokePairingLink(string $baseUrl, #[SensitiveParameter] string $adminSession, string $pairingLinkId): bool;
}
