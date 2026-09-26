<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Firewall\ListFirewallRulesAction;
use App\Data\Firewall\FirewallRuleData;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The firewall rules of every Node the caller can reach, so a fleet view needs one request instead of one per Node. */
#[RequiresNodeAccess(ServingNode::Collection)]
final class FleetFirewallRulesController extends Controller
{
    public function index(
        Request $request,
        ListFirewallRulesAction $action,
        NodeAccessAuthorizer $access,
    ): JsonResponse {
        $caller = $request->user();
        abort_unless($caller instanceof Node, 403);

        return response()->json([
            'data' => $action
                ->executeForNodes($access->accessibleNodeIds($caller))
                ->map(static function (FirewallRule $rule): array {
                    $data = FirewallRuleData::fromModel($rule)->toArray();
                    unset($data['backend_status']);

                    return $data;
                })
                ->values()
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
