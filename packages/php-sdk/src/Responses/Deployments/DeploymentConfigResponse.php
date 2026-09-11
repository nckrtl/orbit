<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DeploymentConfigResponse
{
    /** @param list<DeploymentStepResponse> $steps */
    private function __construct(
        public string $branch,
        public array $steps,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        $validRequestId = GatewayRequestId::fromTransport($requestId);
        $steps = $data['steps'] ?? null;

        if (
            ! self::hasExactFields($data, ['branch', 'steps'])
            || ! is_string($data['branch'])
            || ! is_array($steps)
            || ! array_is_list($steps)
            || count($steps) > 32
            || $validRequestId === null
        ) {
            throw self::invalid($validRequestId);
        }

        $responses = [];

        foreach ($steps as $step) {
            if (
                ! is_array($step)
                || ! self::hasExactFields($step, ['name', 'phase', 'command', 'timeout_seconds'])
                || ! is_string($step['name'])
                || ! is_string($step['phase'])
                || ! is_string($step['command'])
                || ! is_int($step['timeout_seconds'])
            ) {
                throw self::invalid($validRequestId);
            }

            $responses[] = new DeploymentStepResponse(
                name: $step['name'],
                phase: $step['phase'],
                command: $step['command'],
                timeoutSeconds: $step['timeout_seconds'],
            );
        }

        return new self($data['branch'], $responses, $validRequestId);
    }

    /** @return array{branch: string, steps: list<array{name: string, phase: string, command: string, timeout_seconds: int}>, request_id: string} */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'steps' => array_map(
                static fn (DeploymentStepResponse $step): array => $step->toArray(),
                $this->steps,
            ),
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $expected
     */
    private static function hasExactFields(#[SensitiveParameter] array $data, array $expected): bool
    {
        return count($data) === count($expected) && array_all(
            array_keys($data),
            static fn (mixed $key): bool => is_string($key) && in_array($key, $expected, strict: true),
        );
    }

    private static function invalid(?string $requestId): GatewayApiException
    {
        return new GatewayApiException(
            'Gateway response contains invalid deployment configuration data.',
            requestId: $requestId,
        );
    }
}
