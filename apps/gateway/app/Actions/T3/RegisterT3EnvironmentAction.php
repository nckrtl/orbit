<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Data\T3\RegisterT3EnvironmentData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\T3\T3ServerClient;
use App\Domain\T3\T3ServerException;
use App\Models\T3Environment;
use App\Models\T3Peer;
use Illuminate\Support\Carbon;

/**
 * Registers a T3 server. The Gateway checks that the URL serves the named environment, exchanges the
 * server's admin pairing link for an admin session the way a T3 client pairs, and stores that session
 * encrypted. Registering again replaces the session and revokes the Gateway's earlier sessions there.
 */
final readonly class RegisterT3EnvironmentAction
{
    /** The client label of the Gateway's own admin session on every T3 server. */
    public const string GATEWAY_CLIENT_LABEL = 'Orbit Gateway';

    /** The scopes the Gateway needs to mint and revoke device sessions. */
    private const array REQUIRED_SCOPES = ['access:read', 'access:write'];

    public function __construct(private T3ServerClient $server) {}

    public function execute(T3Peer $peer, RegisterT3EnvironmentData $data): T3Environment
    {
        $descriptor = $this->server->describe($data->url);

        if ($descriptor->environmentId !== $data->environmentId) {
            throw new ResourceOperationException(
                't3.environment_mismatch',
                "The T3 server at {$data->url} is environment {$descriptor->environmentId}, not {$data->environmentId}.",
                details: ['served_environment_id' => $descriptor->environmentId],
            );
        }

        $session = $this->server->exchangePairingToken($data->url, $data->pairingToken, self::GATEWAY_CLIENT_LABEL);
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $session->scopes));

        if ($missing !== []) {
            throw new ResourceOperationException(
                't3.admin_scope_missing',
                'The pairing link is not an admin link. It lacks '.implode(', ', $missing).'.',
                details: ['missing_scopes' => implode(' ', $missing)],
            );
        }

        $now = Carbon::now();
        $environment = T3Environment::query()->updateOrCreate(
            ['environment_id' => $data->environmentId],
            [
                'label' => $data->label,
                'url' => $data->url,
                't3_peer_id' => $peer->id,
                'server_version' => $descriptor->serverVersion,
                'admin_session' => $session->token,
                'admin_session_expires_at' => $session->expiresAt,
                'registered_at' => $now,
            ],
        );

        $this->revokeEarlierGatewaySessions($environment);

        return $environment;
    }

    /** Each restart registers again; earlier admin sessions would otherwise stay valid for 30 days. */
    private function revokeEarlierGatewaySessions(T3Environment $environment): void
    {
        try {
            foreach ($this->server->clientSessions($environment->url, $environment->admin_session) as $session) {
                if (! $session->current && $session->label === self::GATEWAY_CLIENT_LABEL) {
                    $this->server->revokeClientSession($environment->url, $environment->admin_session, $session->sessionId);
                }
            }
        } catch (T3ServerException) {
            // The new session is stored; an earlier one that stays valid expires on its own.
        }
    }
}
