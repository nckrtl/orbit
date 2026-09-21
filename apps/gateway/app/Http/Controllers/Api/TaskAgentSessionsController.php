<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ShowTaskAgentSessionsAction;
use App\Actions\Tasks\StreamTaskAgentSessionAction;
use App\Data\Tasks\TaskAgentSessionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskAgentStreamRequest;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class TaskAgentSessionsController extends Controller
{
    public function index(Request $request, TaskGroup $group, ShowTaskAgentSessionsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($group)->map(static fn (TaskAgentSession $session): array => TaskAgentSessionData::fromModel($session)->toArray())->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function stream(TaskAgentStreamRequest $request, TaskGroup $group, TaskAgentSession $session, StreamTaskAgentSessionAction $action): StreamedResponse
    {
        return $action->execute($group, $session, $request->afterSequence());
    }
}
