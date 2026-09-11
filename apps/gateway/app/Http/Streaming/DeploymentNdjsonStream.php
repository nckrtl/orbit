<?php

declare(strict_types=1);

namespace App\Http\Streaming;

use App\Domain\AppInstances\Deployment\DeploymentEvent;
use App\Domain\AppInstances\Deployment\DeploymentProgressPhase;
use App\Domain\AppInstances\Deployment\DeploymentResult;
use RuntimeException;

final class DeploymentNdjsonStream
{
    private const int MaximumLineBytes = 32 * 1024;

    private const int MaximumOutputBytes = 16 * 1024;

    private const int PhaseProbeWindowMicroseconds = 250_000;

    private const int PhaseProbeIntervalMicroseconds = 10_000;

    private const int PhaseProbeWrites = self::PhaseProbeWindowMicroseconds / self::PhaseProbeIntervalMicroseconds;

    private int $sequence = 0;

    public function __construct(
        private readonly string $requestId,
        private readonly DeploymentStreamConnection $connection,
    ) {}

    public function phase(DeploymentProgressPhase $phase, ?string $stepName): void
    {
        $event = ['phase' => $phase->value];

        if ($stepName !== null) {
            $event['step_name'] = $stepName;
        }

        $this->send('phase', $event, probeDisconnect: true);
    }

    public function output(DeploymentEvent $output): void
    {
        if (strlen($output->value) > self::MaximumOutputBytes) {
            throw new RuntimeException('Deployment output exceeded the stream chunk limit.');
        }

        $this->send('output', [
            'stream' => $output->stream->value,
            'data_base64' => base64_encode($output->value),
        ]);
    }

    public function result(DeploymentResult $result): void
    {
        $this->send('result', [
            'status' => $result->succeeded ? 'succeeded' : 'failed',
            'failed_step' => $result->failure?->boundary->value,
            'error_code' => $result->failure?->errorCode,
            'selected_release' => $result->selectedRelease?->name,
        ]);
    }

    /** @param array<string, int|string|null> $fields */
    private function send(string $type, array $fields, bool $probeDisconnect = false): void
    {
        $json = json_encode([
            'type' => $type,
            'sequence' => ++$this->sequence,
            'request_id' => $this->requestId,
            ...$fields,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $probeBytes = $probeDisconnect ? self::PhaseProbeWrites : 0;
        $line = $json."\n";

        if ($probeBytes + strlen($line) > self::MaximumLineBytes) {
            throw new RuntimeException('Deployment event exceeded the stream line limit.');
        }

        if ($probeDisconnect) {
            for ($write = 0; $write < self::PhaseProbeWrites; $write++) {
                $this->connection->send(' ');

                if ($this->connection->disconnected()) {
                    return;
                }

                usleep(self::PhaseProbeIntervalMicroseconds);
            }

            $this->connection->send($line);

            return;
        }

        $this->connection->send($line);
    }
}
