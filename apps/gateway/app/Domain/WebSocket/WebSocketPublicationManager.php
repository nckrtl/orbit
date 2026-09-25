<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\Node;

interface WebSocketPublicationManager
{
    public function converge(Node $node): void;

    /** Refuses when the Node lacks an address its Caddy sites would bind. It changes nothing. */
    public function checkListenAddresses(Node $node): void;

    public function remove(Node $node): void;

    /**
     * Removes only the Gateway-side publication, for a websocket node Orbit
     * cannot reach: the private DNS record. The node's own Caddy site,
     * certificate and firewall rule stay on the box, since reaching them
     * would require SSH to a node that is unreachable.
     */
    public function removeUnreachable(Node $node): void;
}
