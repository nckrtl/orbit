<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| The Gateway broadcasts every record change on a single private channel,
| `orbit`. Any authenticated Gateway API caller (an active WireGuard peer
| Node, the same identity `RequireActiveWireGuardPeer` resolves for the
| rest of the API) may subscribe; there is no per-node scoping.
|
*/

Broadcast::channel('orbit', fn (Node $node): bool => true);
