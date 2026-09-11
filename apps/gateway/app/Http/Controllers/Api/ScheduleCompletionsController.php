<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\CompleteScheduleAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\CompleteScheduleRequest;
use App\Models\Node;
use Illuminate\Http\Response;

#[RequiresNodeAccess(ServingNode::ScheduleHost)]
final class ScheduleCompletionsController extends Controller
{
    public function store(
        CompleteScheduleRequest $request,
        string $schedule,
        CompleteScheduleAction $complete,
    ): Response {
        $caller = $request->user();
        abort_unless($caller instanceof Node, 403);
        $complete->execute($schedule, $request->status(), $caller);

        return response()->noContent();
    }
}
