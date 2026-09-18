<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

/**
 * Collects the phase and output events emitted during one deployment or
 * rollback run so {@see AppInstanceDeploymentRecorder} can store them beside
 * the run's outcome. Output text is capped so one noisy step cannot grow a
 * stored row without bound; once the cap is reached, later output is dropped
 * and replaced with one truncation marker.
 */
final class DeploymentEventCollector
{
    private const int MAX_OUTPUT_BYTES = 131_072;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $outputBytes = 0;

    private bool $truncated = false;

    public function output(DeploymentEvent $event): void
    {
        if ($this->truncated) {
            return;
        }

        if ($this->outputBytes + strlen($event->value) > self::MAX_OUTPUT_BYTES) {
            $this->truncated = true;
            $this->events[] = ['type' => 'output_truncated'];

            return;
        }

        $this->outputBytes += strlen($event->value);
        $this->events[] = [
            'type' => 'output',
            'step' => $event->step,
            'stream' => $event->stream->value,
            'value_base64' => base64_encode($event->value),
        ];
    }

    public function phase(DeploymentProgressPhase $phase, ?string $stepName): void
    {
        $this->events[] = [
            'type' => 'phase',
            'phase' => $phase->value,
            'step_name' => $stepName,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }
}
