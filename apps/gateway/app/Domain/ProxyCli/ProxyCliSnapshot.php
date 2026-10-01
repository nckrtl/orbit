<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliSnapshot
{
    /**
     * @param  list<ProxyCliAccount>  $accounts
     * @param  list<ProxyCliProviderPool>  $providers
     * @param  list<ProxyCliModel>  $models
     */
    public function __construct(
        public array $accounts,
        public array $providers,
        public ?string $collectedAt,
        public array $models = [],
    ) {}

    /**
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     providers: list<array<string, mixed>>,
     *     models: list<array{id: string, provider: string}>,
     *     collected_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'accounts' => array_map(static fn (ProxyCliAccount $account): array => $account->toArray(), $this->accounts),
            'providers' => array_map(static fn (ProxyCliProviderPool $pool): array => $pool->toArray(), $this->providers),
            'models' => array_map(static fn (ProxyCliModel $model): array => $model->toArray(), $this->models),
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
