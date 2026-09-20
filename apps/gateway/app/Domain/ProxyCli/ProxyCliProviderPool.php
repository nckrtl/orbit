<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliProviderPool
{
    /**
     * @param  list<ProxyCliWindow>  $windows
     * @param  list<ProxyCliAccount>  $accounts
     */
    public function __construct(
        public string $provider,
        public array $windows,
        public array $accounts,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     windows: list<array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}>,
     *     accounts: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'windows' => array_map(static fn (ProxyCliWindow $window): array => $window->toArray(), $this->windows),
            'accounts' => array_map(static fn (ProxyCliAccount $account): array => $account->toArray(), $this->accounts),
        ];
    }
}
