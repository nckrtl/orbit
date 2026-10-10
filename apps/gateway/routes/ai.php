<?php

declare(strict_types=1);

use App\Http\Mcp\OrbitSearchServer;
use App\Http\Mcp\OrbitServer;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\StartRequestDeadline;
use App\Http\Middleware\ValidateMcpPostSize;
use Laravel\Mcp\Facades\Mcp;

// Both servers list or search the same catalogue. The peer check here keeps the catalogue itself private
// to the fleet; each tool call repeats it, with directed node access, inside the API request it runs.
// One command deadline covers the whole MCP request, so a batch of long tool calls cannot outlast PHP-FPM.
Mcp::web('/mcp', OrbitServer::class)
    ->middleware([RequireActiveWireGuardPeer::class, ValidateMcpPostSize::class, StartRequestDeadline::class])
    ->name('mcp:tools');

Mcp::web('/mcp/search', OrbitSearchServer::class)
    ->middleware([RequireActiveWireGuardPeer::class, ValidateMcpPostSize::class, StartRequestDeadline::class])
    ->name('mcp:search');
