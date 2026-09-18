<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

final readonly class AppInstanceDeploymentEvent
{
    public function __construct(
        public string $type,
        public ?string $phase,
        public ?string $stepName,
        public ?string $stream,
        public ?string $value,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $valueBase64 = $data['value_base64'] ?? null;
        $value = is_string($valueBase64) ? base64_decode($valueBase64, true) : null;

        return new self(
            type: $type,
            phase: is_string($data['phase'] ?? null) ? $data['phase'] : null,
            stepName: is_string($data['step_name'] ?? null) ? $data['step_name'] : null,
            stream: is_string($data['stream'] ?? null) ? $data['stream'] : null,
            value: is_string($value) ? $value : null,
        );
    }

    /** @return array{type: string, phase: string|null, step_name: string|null, stream: string|null, value: string|null} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'phase' => $this->phase,
            'step_name' => $this->stepName,
            'stream' => $this->stream,
            'value' => $this->value,
        ];
    }
}
