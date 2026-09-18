<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Broadcasting\RealtimeConnection;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorises a Reverb subscription to the private `orbit` channel. Any active WireGuard
 * peer with Gateway access may subscribe; the channel rules live in routes/channels.php.
 */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class RealtimeAuthController extends Controller
{
    public function authenticate(Request $request, RealtimeConnection $realtime): mixed
    {
        if (! $realtime->configureBroadcasting()) {
            return new JsonResponse(['message' => 'Realtime is not configured.'], 404);
        }

        $realtime->registerChannelAuthorizers();

        return Broadcast::auth($request);
    }
}
