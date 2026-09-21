<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliAccount
{
    /**
     * @param  list<ProxyCliWindow>  $windows
     */
    public function __construct(
        public string $id,
        public string $provider,
        public string $label,
        public bool $disabled,
        public ?string $status,
        public array $windows,
        public ?string $error = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     provider: string,
     *     label: string,
     *     disabled: bool,
     *     status: string|null,
     *     windows: list<array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}>,
     *     error: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'disabled' => $this->disabled,
            'status' => $this->status,
            'windows' => array_map(static fn (ProxyCliWindow $window): array => $window->toArray(), $this->windows),
            'error' => $this->error,
        ];
    }
}
