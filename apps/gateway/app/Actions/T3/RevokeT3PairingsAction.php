<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Data\T3\T3PairingData;
use App\Data\T3\T3RevocationData;
use App\Domain\T3\T3ServerClient;
use App\Models\T3Environment;
use App\Models\T3Pairing;
use App\Models\T3Peer;
use Illuminate\Support\Carbon;

/**
 * Ends a peer's access to one T3 server: it revokes every session paired from the peer's pairing
 * links there, and any link not used yet. WireGuard access stays as it is.
 */
final readonly class RevokeT3PairingsAction
{
    public function __construct(private T3ServerClient $server) {}

    public function execute(T3Peer $peer, T3Environment $environment): T3RevocationData
    {
        $pairings = T3Pairing::query()
            ->where('t3_peer_id', $peer->id)
            ->where('t3_environment_id', $environment->id)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
        $revokedSessions = 0;

        if ($pairings->isNotEmpty()) {
            $labels = $pairings->pluck('client_label')->all();

            foreach ($this->server->clientSessions($environment->url, $environment->admin_session) as $session) {
                if (! $session->current && in_array($session->label, $labels, true)
                    && $this->server->revokeClientSession($environment->url, $environment->admin_session, $session->sessionId)) {
                    $revokedSessions++;
                }
            }

            foreach ($pairings as $pairing) {
                $this->server->revokePairingLink($environment->url, $environment->admin_session, $pairing->pairing_link_id);
                $pairing->forceFill(['revoked_at' => Carbon::now()])->save();
            }
        }

        return new T3RevocationData(
            environmentId: $environment->environment_id,
            peerId: $peer->id,
            revokedSessions: $revokedSessions,
            pairings: array_values($pairings->map(static fn (T3Pairing $pairing): T3PairingData => T3PairingData::fromModel($pairing))->all()),
        );
    }
}
