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
use App\Models\Process;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

/** Live log streams of a Process, with the access rule of `process:logs` (ADR 0153). */
#[RequiresNodeAccess(ServingNode::ProcessOwning)]
final class ProcessLogStreamsController extends Controller
{
    use RespondsWithLogStreams;

    public function store(OpenLogStreamRequest $request, #[SensitiveParameter] Process $process, LogStreamTargetResolver $targets, OpenLogStreamAction $action): JsonResponse
    {
        return $this->opened($request, $action->execute($targets->forProcess($process), $this->viewer($request), $request->socketId(), $request->lines()));
    }

    public function update(Request $request, #[SensitiveParameter] Process $process, string $stream, RenewLogStreamAction $action): JsonResponse
    {
        return $this->renewed($request, $action->execute(LogStreamRecordType::Process, (int) $process->id, $stream, $this->viewer($request)));
    }

    public function destroy(Request $request, #[SensitiveParameter] Process $process, string $stream, CloseLogStreamAction $action): JsonResponse
    {
        return $this->closed($request, $action->execute(LogStreamRecordType::Process, (int) $process->id, $stream, $this->viewer($request)));
    }
}
