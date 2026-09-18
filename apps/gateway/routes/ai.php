<?php

declare(strict_types=1);

use App\Http\Mcp\OrbitSearchServer;
use App\Http\Mcp\OrbitServer;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use Laravel\Mcp\Facades\Mcp;

// Both servers list or search the same catalogue. The peer check here keeps the catalogue itself private
// to the fleet; each tool call repeats it, with directed node access, inside the API request it runs.
Mcp::web('/mcp', OrbitServer::class)
    ->middleware([EnsureRequestId::class, RequireActiveWireGuardPeer::class])
    ->name('mcp:tools');

Mcp::web('/mcp/search', OrbitSearchServer::class)
    ->middleware([EnsureRequestId::class, RequireActiveWireGuardPeer::class])
    ->name('mcp:search');
