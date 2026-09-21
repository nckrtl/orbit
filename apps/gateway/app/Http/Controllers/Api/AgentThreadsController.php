<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ShowAgentThreadsAction;
use App\Actions\Tasks\StreamAgentThreadAction;
use App\Data\Tasks\AgentThreadData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskAgentStreamRequest;
use App\Models\AgentThread;
use App\Models\TaskGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class AgentThreadsController extends Controller
{
    public function index(Request $request, TaskGroup $group, ShowAgentThreadsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($group)->map(static fn (AgentThread $session): array => AgentThreadData::fromModel($session)->toArray())->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function stream(TaskAgentStreamRequest $request, TaskGroup $group, AgentThread $session, StreamAgentThreadAction $action): StreamedResponse
    {
        return $action->execute($group, $session, $request->afterSequence());
    }
}
