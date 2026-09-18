<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\Node;

interface WebSocketCredentialManager
{
    /**
     * Returns the node's stored Reverb credentials, generating and persisting
     * them on first use. Stable across every later converge.
     */
    public function ensure(Node $node): WebSocketCredentials;

    /**
     * Reads the currently active websocket role assignment's credentials
     * without touching the node. Null when no role is assigned or nothing
     * has been generated yet.
     */
    public function current(): ?WebSocketCredentials;

    public function purge(Node $node): void;
}
