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

        $this->send('phase', $event);
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
    private function send(string $type, array $fields): void
    {
        $line = json_encode([
            'type' => $type,
            'sequence' => ++$this->sequence,
            'request_id' => $this->requestId,
            ...$fields,
        ], JSON_THROW_ON_ERROR)."\n";

        if (strlen($line) > self::MaximumLineBytes) {
            throw new RuntimeException('Deployment event exceeded the stream line limit.');
        }

        $this->connection->send($line);
    }
}
