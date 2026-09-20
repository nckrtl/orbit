<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliSnapshot
{
    /**
     * @param  list<ProxyCliAccount>  $accounts
     * @param  list<ProxyCliProviderPool>  $providers
     */
    public function __construct(
        public array $accounts,
        public array $providers,
        public ?string $collectedAt,
    ) {}

    /**
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     providers: list<array<string, mixed>>,
     *     collected_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'accounts' => array_map(static fn (ProxyCliAccount $account): array => $account->toArray(), $this->accounts),
            'providers' => array_map(static fn (ProxyCliProviderPool $pool): array => $pool->toArray(), $this->providers),
            'collected_at' => $this->collectedAt,
        ];
    }

    public function provider(string $provider): ?ProxyCliProviderPool
    {
        foreach ($this->providers as $pool) {
            if ($pool->provider === $provider) {
                return $pool;
            }
        }

        return null;
    }
}
