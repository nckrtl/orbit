<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliWindow
{
    public function __construct(
        public string $label,
        public float $usedPercent,
        public ?string $resetsAt = null,
    ) {}

    public function remainingPercent(): float
    {
        return max(0.0, 100.0 - $this->usedPercent);
    }

    /**
     * @return array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'used_percent' => $this->usedPercent,
            'remaining_percent' => $this->remainingPercent(),
            'resets_at' => $this->resetsAt,
        ];
    }
}
