<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Logs\CloseLogStreamAction;
use App\Actions\Logs\OpenLogStreamAction;
use App\Actions\Logs\RenewLogStreamAction;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamTargetResolver;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\OpenLogStreamRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Live log streams of an Instance's application log, with the access rule of `instance:logs` (ADR 0153). */
#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class InstanceLogStreamsController extends Controller
{
    use RespondsWithLogStreams;

    public function store(OpenLogStreamRequest $request, AppInstance $instance, LogStreamTargetResolver $targets, OpenLogStreamAction $action): JsonResponse
    {
        return $this->opened($request, $action->execute($targets->forInstance($instance), $this->viewer($request), $request->socketId(), $request->lines()));
    }

    public function update(Request $request, AppInstance $instance, string $stream, RenewLogStreamAction $action): JsonResponse
    {
        return $this->renewed($request, $action->execute(LogStreamRecordType::Instance, (int) $instance->id, $stream, $this->viewer($request)));
    }

    public function destroy(Request $request, AppInstance $instance, string $stream, CloseLogStreamAction $action): JsonResponse
    {
        return $this->closed($request, $action->execute(LogStreamRecordType::Instance, (int) $instance->id, $stream, $this->viewer($request)));
    }
}
