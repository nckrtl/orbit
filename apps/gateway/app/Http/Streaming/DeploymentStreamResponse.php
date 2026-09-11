<?php

declare(strict_types=1);

namespace App\Http\Streaming;

use App\Domain\AppInstances\Deployment\DeploymentCancellation;
use App\Domain\AppInstances\Deployment\DeploymentFailureBoundary;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentResult;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class DeploymentStreamResponse
{
    public function __construct(private DeploymentStreamConnection $connection) {}

    /** @param Closure(DeploymentRequest): DeploymentResult $operation */
    public function make(Request $request, Closure $operation): StreamedResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');

        return response()->stream(function () use ($request, $requestId, $operation): void {
            $previousIgnoreUserAbort = ignore_user_abort(true);

            try {
                $stream = new DeploymentNdjsonStream($requestId, $this->connection);
                $deploymentRequest = new DeploymentRequest(
                    output: $stream->output(...),
                    cancellation: new DeploymentCancellation($this->connection->disconnected(...)),
                    phase: $stream->phase(...),
                );

                try {
                    $result = $operation($deploymentRequest);
                } catch (Throwable) {
                    $result = DeploymentResult::failed(
                        null,
                        null,
                        DeploymentFailureBoundary::Operation,
                        'deployment.interrupted',
                    );
                }

                $request->attributes->set('orbit.deployment_result', $result);
                $activeRequest = app('request');
                $activeRequest->attributes->set('orbit.deployment_result', $result);

                if (! $this->connection->disconnected()) {
                    $stream->result($result);
                }
            } finally {
                ignore_user_abort($previousIgnoreUserAbort !== 0);
            }
        }, headers: [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
