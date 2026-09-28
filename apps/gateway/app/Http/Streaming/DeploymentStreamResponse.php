<?php

declare(strict_types=1);

namespace App\Http\Streaming;

use App\Domain\Instances\Deployment\DeploymentCancellation;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentEventCollector;
use App\Domain\Instances\Deployment\DeploymentFailureBoundary;
use App\Domain\Instances\Deployment\DeploymentProgressPhase;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class DeploymentStreamResponse
{
    public function __construct(
        private DeploymentStreamConnection $connection,
        private InstanceDeploymentRecorder $recorder,
    ) {}

    /** @param Closure(DeploymentRequest): DeploymentResult $operation */
    public function make(Request $request, Instance $instance, string $triggeredBy, Closure $operation): StreamedResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');

        return response()->stream(function () use ($request, $requestId, $instance, $triggeredBy, $operation): void {
            $previousIgnoreUserAbort = ignore_user_abort(true);

            try {
                $deployment = $this->recorder->start($instance, $triggeredBy);
                $collector = new DeploymentEventCollector;
                $stream = new DeploymentNdjsonStream($requestId, $this->connection);
                $deploymentRequest = new DeploymentRequest(
                    output: function (DeploymentEvent $event) use ($stream, $collector): void {
                        $collector->output($event);
                        $stream->output($event);
                    },
                    cancellation: new DeploymentCancellation($this->connection->disconnected(...)),
                    phase: function (DeploymentProgressPhase $phase, ?string $stepName) use ($stream, $collector): void {
                        $collector->phase($phase, $stepName);
                        $stream->phase($phase, $stepName);
                    },
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

                $this->recorder->finish($deployment, $result, $collector->events());

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
