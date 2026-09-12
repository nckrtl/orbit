<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\ActivateScheduleAction;
use App\Actions\Schedules\AddScheduleAction;
use App\Actions\Schedules\ListSchedulesAction;
use App\Actions\Schedules\RemoveScheduleAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Actions\Schedules\ShowScheduleLogsAction;
use App\Data\Schedules\ScheduleData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedules\EmptyScheduleQueryRequest;
use App\Http\Requests\Schedules\EmptyScheduleRequest;
use App\Http\Requests\Schedules\ScheduleLogsRequest;
use App\Http\Requests\Schedules\StoreScheduleRequest;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

final class SchedulesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(EmptyScheduleQueryRequest $request, ListSchedulesAction $action): JsonResponse
    {
        $caller = $request->user();
        abort_unless($caller instanceof Node, 403);

        return response()->json([
            'data' => $action->execute($caller)->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function store(
        #[SensitiveParameter]
        StoreScheduleRequest $request,
        AddScheduleAction $action,
    ): JsonResponse {
        $result = $action->execute($request->payload());
        $this->rememberForActivity($request, $result['schedule']);

        return response()->json(
            [
                'data' => ScheduleData::fromModel($result['schedule'])->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function show(
        EmptyScheduleQueryRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
    ): JsonResponse {
        return $this->scheduleResponse($request, $schedule);
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function run(
        EmptyScheduleRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        RunScheduleAction $action,
    ): JsonResponse {
        return $this->scheduleResponse($request, $action->execute($schedule));
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function logs(
        ScheduleLogsRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        ShowScheduleLogsAction $action,
    ): JsonResponse {
        $this->rememberForActivity($request, $schedule);
        $lines = $request->lines();
        $logs = $action->execute($schedule, $lines);

        return response()->json([
            'data' => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'lines' => $lines,
                'output' => $logs->output,
                'truncated' => $logs->truncated,
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function destroy(
        EmptyScheduleRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        RemoveScheduleAction $action,
    ): JsonResponse {
        return $this->scheduleResponse($request, $action->execute($schedule));
    }

    #[RequiresNodeAccess(ServingNode::ScheduleOwning)]
    public function activate(
        EmptyScheduleRequest $request,
        #[SensitiveParameter]
        Schedule $schedule,
        ActivateScheduleAction $action,
    ): JsonResponse {
        return $this->scheduleResponse($request, $action->execute($schedule));
    }

    private function scheduleResponse(Request $request, #[SensitiveParameter] Schedule $schedule): JsonResponse
    {
        $this->rememberForActivity($request, $schedule);

        return response()->json([
            'data' => ScheduleData::fromModel($schedule)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    private function rememberForActivity(Request $request, #[SensitiveParameter] Schedule $schedule): void
    {
        $request->attributes->set('orbit.schedule_activity', $schedule);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
