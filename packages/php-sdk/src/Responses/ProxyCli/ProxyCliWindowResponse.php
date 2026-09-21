<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProxyCli;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class ProxyCliWindowResponse
{
    public function __construct(
        public string $label,
        public float $usedPercent,
        public float $remainingPercent,
        public ?string $resetsAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $label = $data['label'] ?? null;
        $used = $data['used_percent'] ?? null;
        $remaining = $data['remaining_percent'] ?? null;
        $resetsAt = $data['resets_at'] ?? null;

        if (
            ! is_string($label)
            || $label === ''
            || strlen($label) > 32
            || ! is_numeric($used)
            || ! is_numeric($remaining)
            || ($resetsAt !== null && (! is_string($resetsAt) || $resetsAt === '' || strlen($resetsAt) > 64))
        ) {
            throw new GatewayApiException('Gateway response contains invalid proxycli window.', requestId: $requestId);
        }

        return new self($label, (float) $used, (float) $remaining, $resetsAt);
    }

    /**
     * @return array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'used_percent' => $this->usedPercent,
            'remaining_percent' => $this->remainingPercent,
            'resets_at' => $this->resetsAt,
        ];
    }
}
