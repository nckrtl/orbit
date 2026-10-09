<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CloseTaskQuestionAction;
use App\Actions\Tasks\ListTaskQuestionsAction;
use App\Data\Tasks\TaskQuestionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\CloseTaskQuestionRequest;
use App\Http\Requests\Tasks\ListTaskQuestionsRequest;
use App\Models\TaskQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskQuestionsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListTaskQuestionsRequest $request, ListTaskQuestionsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($request->projectId(), $request->cause(), $request->status(), $request->since())
                ->map(static fn (TaskQuestion $question): array => TaskQuestionData::fromModel($question)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function close(CloseTaskQuestionRequest $request, TaskQuestion $question, CloseTaskQuestionAction $action): JsonResponse
    {
        $closed = $action->execute($question, $request->status(), $request->reason(), $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => TaskQuestionData::fromModel($closed)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
