<?php

declare(strict_types=1);

namespace App\Actions\Conn;

use App\Data\Conn\IssuedConnPairingData;
use App\Domain\Conn\T3PairingUrl;
use App\Domain\Conn\T3ServerClient;
use App\Domain\Shared\ResourceOperationException;
use App\Models\ConnEnvironment;
use App\Models\ConnPairing;
use App\Models\Node;
use Illuminate\Support\Str;

/**
 * Mints a one-time pairing link on a registered T3 server for the calling Node. The link's label is
 * unique, and T3 gives the session paired from it the same client label, so a revoke can find it.
 */
final readonly class IssueConnPairingAction
{
    public function __construct(private T3ServerClient $server) {}

    public function execute(Node $node, ConnEnvironment $environment): IssuedConnPairingData
    {
        if (! $environment->admin_session_expires_at->isFuture()) {
            throw new ResourceOperationException(
                'conn.session_expired',
                "The Gateway's admin session on {$environment->label} expired. Restart that T3 server so it registers again.",
                409,
            );
        }

        $label = $node->name.' via Orbit '.Str::lower(Str::random(8));
        $credential = $this->server->issuePairingCredential($environment->url, $environment->admin_session, $label);

        $pairing = ConnPairing::query()->create([
            'node_id' => $node->id,
            'conn_environment_id' => $environment->id,
            'pairing_link_id' => $credential->id,
            'client_label' => $label,
            'expires_at' => $credential->expiresAt,
        ]);

        return IssuedConnPairingData::fromModel($pairing, T3PairingUrl::build($environment->url, $credential->credential));
    }
}
