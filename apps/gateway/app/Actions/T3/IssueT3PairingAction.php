<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Data\T3\IssuedT3PairingData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\T3\T3PairingUrl;
use App\Domain\T3\T3ServerClient;
use App\Models\T3Environment;
use App\Models\T3Pairing;
use App\Models\T3Peer;
use Illuminate\Support\Str;

/**
 * Mints a one-time pairing link on a registered T3 server for the calling peer. The link's label is
 * unique, and T3 gives the session paired from it the same client label, so a revoke can find it.
 */
final readonly class IssueT3PairingAction
{
    public function __construct(private T3ServerClient $server) {}

    public function execute(T3Peer $peer, T3Environment $environment): IssuedT3PairingData
    {
        if (! $environment->admin_session_expires_at->isFuture()) {
            throw new ResourceOperationException(
                't3.session_expired',
                "The Gateway's admin session on {$environment->label} expired. Restart that T3 server so it registers again.",
                409,
            );
        }

        $label = $peer->displayName().' via Orbit '.Str::lower(Str::random(8));
        $credential = $this->server->issuePairingCredential($environment->url, $environment->admin_session, $label);

        $pairing = T3Pairing::query()->create([
            't3_peer_id' => $peer->id,
            't3_environment_id' => $environment->id,
            'pairing_link_id' => $credential->id,
            'client_label' => $label,
            'expires_at' => $credential->expiresAt,
        ]);

        return IssuedT3PairingData::fromModel($pairing, T3PairingUrl::build($environment->url, $credential->credential));
    }
}
