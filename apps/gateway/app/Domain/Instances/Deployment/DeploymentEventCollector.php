<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

/**
 * Collects the phase and output events emitted during one deployment or
 * rollback run so {@see InstanceDeploymentRecorder} can store them beside
 * the run's outcome. Output text is capped so one noisy step cannot grow a
 * stored row without bound; once the cap is reached, later ordinary output is
 * dropped and replaced with one truncation marker. Important failure warnings
 * can replace older ordinary output without exceeding the same cap.
 */
final class DeploymentEventCollector
{
    private const int MAX_OUTPUT_BYTES = 131_072;

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @var array<int, true> */
    private array $importantOutputs = [];

    private int $outputBytes = 0;

    private bool $truncated = false;

    public function output(DeploymentEvent $event): void
    {
        if ($event->important) {
            $this->important($event);

            return;
        }
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
        return array_values($this->events);
    }

    /** Keep bounded failure warnings even when a noisy command filled the output budget. */
    private function important(DeploymentEvent $event): void
    {
        $bytes = strlen($event->value);
        if ($bytes > self::MAX_OUTPUT_BYTES) {
            return;
        }
        foreach (array_reverse($this->events, preserve_keys: true) as $key => $record) {
            if ($this->outputBytes + $bytes <= self::MAX_OUTPUT_BYTES) {
                break;
            }
            if (($record['type'] ?? null) !== 'output' || isset($this->importantOutputs[$key])) {
                continue;
            }
            $encoded = $record['value_base64'];
            assert(is_string($encoded));
            $decoded = base64_decode($encoded, strict: true);
            assert(is_string($decoded));
            $this->outputBytes -= strlen($decoded);
            unset($this->events[$key]);
            if (! $this->truncated) {
                $this->events[] = ['type' => 'output_truncated'];
                $this->truncated = true;
            }
        }
        if ($this->outputBytes + $bytes > self::MAX_OUTPUT_BYTES) {
            return;
        }
        $this->outputBytes += $bytes;
        $this->events[] = [
            'type' => 'output',
            'step' => $event->step,
            'stream' => $event->stream->value,
            'value_base64' => base64_encode($event->value),
        ];
        $this->importantOutputs[array_key_last($this->events)] = true;
    }
}
