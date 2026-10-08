<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use App\Models\T3Peer;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Identifies a T3 Code caller by its WireGuard address. WireGuard binds a source address to one
 * peer key, so any address inside the VPN subnet is a known peer. A peer whose address belongs to
 * an active Node is linked to that Node; a phone or laptop that is not a Node is a plain client peer.
 */
final readonly class ResolveT3PeerAction
{
    /** A peer's last_seen_at is written at most this often, so reads do not write on every call. */
    private const int SEEN_RESOLUTION_SECONDS = 60;

    public function __construct(private VpnSettings $vpn) {}

    public function handle(mixed $remoteAddress): ?T3Peer
    {
        if (! is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        try {
            $subnet = Ipv4Subnet::from($this->vpn->subnet());
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! $subnet->containsUsableAddress($remoteAddress)) {
            return null;
        }

        $nodeId = Node::query()
            ->where('wireguard_ip', $remoteAddress)
            ->where('status', LifecycleStatus::Active->value)
            ->value('id');
        $now = Carbon::now();
        $peer = T3Peer::query()->firstOrCreate(
            ['wireguard_ip' => $remoteAddress],
            ['node_id' => $nodeId, 'last_seen_at' => $now],
        );

        if ($peer->node_id !== $nodeId || $peer->last_seen_at->diffInSeconds($now) >= self::SEEN_RESOLUTION_SECONDS) {
            $peer->forceFill(['node_id' => $nodeId, 'last_seen_at' => $now])->save();
        }

        return $peer;
    }
}
