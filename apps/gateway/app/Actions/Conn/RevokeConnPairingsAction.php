<?php

declare(strict_types=1);

namespace App\Actions\Conn;

use App\Data\Conn\ConnPairingData;
use App\Data\Conn\ConnRevocationData;
use App\Domain\Conn\T3ServerClient;
use App\Models\ConnEnvironment;
use App\Models\ConnPairing;
use App\Models\Node;
use Illuminate\Support\Carbon;

/**
 * Ends a Node's access to one T3 server: it revokes every session paired from the Node's pairing
 * links there, and any link not used yet. WireGuard access stays as it is.
 */
final readonly class RevokeConnPairingsAction
{
    public function __construct(private T3ServerClient $server) {}

    public function execute(Node $node, ConnEnvironment $environment): ConnRevocationData
    {
        $pairings = ConnPairing::query()
            ->where('node_id', $node->id)
            ->where('conn_environment_id', $environment->id)
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

        return new ConnRevocationData(
            environmentId: $environment->environment_id,
            nodeId: $node->id,
            revokedSessions: $revokedSessions,
            pairings: array_values($pairings->map(static fn (ConnPairing $pairing): ConnPairingData => ConnPairingData::fromModel($pairing))->all()),
        );
    }
}
