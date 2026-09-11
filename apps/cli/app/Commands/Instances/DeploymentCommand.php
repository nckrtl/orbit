<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use LogicException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Deployments\DeploymentEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentOutputEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentPhaseEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentResultEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;
use Throwable;

abstract class DeploymentCommand extends GatewayCommand
{
    private ?string $requestId = null;

    protected function renderDeploymentStream(DeploymentStream $stream): int
    {
        $this->requestId = null;

        try {
            foreach ($stream as $event) {
                $this->requestId = $event->requestId;

                if ($event instanceof DeploymentPhaseEvent) {
                    $this->renderPhase($event);

                    continue;
                }

                if ($event instanceof DeploymentOutputEvent) {
                    $this->renderOutput($event);

                    continue;
                }

                if ($event instanceof DeploymentResultEvent) {
                    $this->renderResult($event);

                    return $event->succeeded() ? self::SUCCESS : self::FAILURE;
                }
            }
        } catch (GatewayApiException $exception) {
            return $this->renderStreamFailure(
                $exception->errorCode() ?? 'deployment.stream_invalid',
                $exception->getMessage(),
                $exception->requestId() ?? $this->requestId,
            );
        } catch (Throwable) {
            return $this->renderStreamFailure(
                'deployment.stream_failed',
                'Deployment stream failed.',
                $this->requestId,
            );
        } finally {
            $stream->close();
        }

        return $this->renderStreamFailure(
            'deployment.stream_invalid',
            'Gateway deployment stream is invalid.',
            $this->requestId,
        );
    }

    protected function terminalValue(string $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function renderPhase(DeploymentPhaseEvent $event): void
    {
        if ($this->option('json') === true) {
            $payload = $this->eventPayload($event);
            $payload['phase'] = $event->phase;

            if ($event->stepName !== null) {
                $payload['step_name'] = $event->stepName;
            }

            $this->writeJson($payload);

            return;
        }

        $phase = str_replace('_', ' ', $event->phase);
        $label = ucfirst($phase);

        if ($event->stepName !== null) {
            $label .= ' ['.$event->stepName.']';
        }

        $this->line("Phase: {$label}");
    }

    private function renderOutput(DeploymentOutputEvent $event): void
    {
        if ($this->option('json') === true) {
            $this->writeJson([
                ...$this->eventPayload($event),
                'stream' => $event->stream,
                'data_base64' => base64_encode($event->data),
            ]);

            return;
        }

        $this->line("{$event->stream}: ".$this->terminalValue($event->data));
    }

    private function renderResult(DeploymentResultEvent $event): void
    {
        if ($this->option('json') === true) {
            $this->writeJson([
                ...$this->eventPayload($event),
                'status' => $event->status,
                'failed_step' => $event->failedStep,
                'error_code' => $event->errorCode,
                'selected_release' => $event->selectedRelease,
            ]);

            return;
        }

        $this->line('Result: '.$event->status);

        if ($event->failedStep !== null) {
            $this->line('Failed boundary: '.$event->failedStep);
        }

        if ($event->errorCode !== null) {
            $this->line('Error code: '.$event->errorCode);
        }

        if ($event->selectedRelease !== null) {
            $this->line('Selected release: '.$event->selectedRelease);
        }

        $this->line('Request ID: '.$event->requestId);
    }

    private function renderStreamFailure(string $code, string $message, ?string $requestId): int
    {
        return $this->renderGatewayFailure($code, $message, $requestId);
    }

    /** @return array{type: string, sequence: int, request_id: string} */
    private function eventPayload(DeploymentEvent $event): array
    {
        return [
            'type' => match (true) {
                $event instanceof DeploymentPhaseEvent => 'phase',
                $event instanceof DeploymentOutputEvent => 'output',
                $event instanceof DeploymentResultEvent => 'result',
                default => throw new LogicException('Unsupported deployment event.'),
            },
            'sequence' => $event->sequence,
            'request_id' => $event->requestId,
        ];
    }
}
