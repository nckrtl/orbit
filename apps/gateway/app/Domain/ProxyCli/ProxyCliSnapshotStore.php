<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use JsonException;

final readonly class ProxyCliSnapshotStore
{
    public function __construct(
        private ProxyCliCache $cache,
        private ProxyCliPoolCompiler $compiler = new ProxyCliPoolCompiler,
    ) {}

    public function snapshot(): ?ProxyCliSnapshot
    {
        $encoded = $this->cache->get(ProxyCliKeys::Snapshot);

        if (! is_string($encoded) || $encoded === '') {
            $accounts = $this->accounts();

            return $accounts === [] ? null : $this->compiler->compile($accounts, $this->collectedAt($accounts));
        }

        try {
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $this->hydrateSnapshot($decoded) : null;
    }

    /**
     * @return list<ProxyCliAccount>
     */
    public function accounts(): array
    {
        $encoded = $this->cache->get(ProxyCliKeys::Raw);

        if (! is_string($encoded) || $encoded === '') {
            return [];
        }

        try {
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $rows = is_array($decoded['accounts'] ?? null) ? $decoded['accounts'] : $decoded;
        $accounts = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $account = $this->hydrateAccount($row);

                if ($account instanceof ProxyCliAccount) {
                    $accounts[] = $account;
                }
            }
        }

        return $accounts;
    }

    /**
     * @param  list<ProxyCliAccount>  $accounts
     * @param  list<ProxyCliModel>|null  $models  Null keeps the models already stored in the snapshot.
     */
    public function write(array $accounts, string $collectedAt, ?array $models = null): ProxyCliSnapshot
    {
        if ($models === null) {
            $stored = $this->snapshot();
            $models = $stored === null ? [] : $stored->models;
        }

        $snapshot = $this->compiler->compile($accounts, $collectedAt, $models);
        $this->cache->put(ProxyCliKeys::Raw, json_encode([
            'accounts' => array_map(static fn (ProxyCliAccount $account): array => $account->toArray(), $accounts),
            'collected_at' => $collectedAt,
        ], JSON_THROW_ON_ERROR));
        $this->cache->put(ProxyCliKeys::Snapshot, json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));

        return $snapshot;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function hydrateSnapshot(array $data): ProxyCliSnapshot
    {
        $accounts = [];

        foreach (is_array($data['accounts'] ?? null) ? $data['accounts'] : [] as $row) {
            if (is_array($row)) {
                $account = $this->hydrateAccount($row);

                if ($account instanceof ProxyCliAccount) {
                    $accounts[] = $account;
                }
            }
        }

        $collectedAt = is_string($data['collected_at'] ?? null) ? $data['collected_at'] : null;

        return $this->compiler->compile($accounts, $collectedAt, $this->hydrateModels($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<ProxyCliModel>
     */
    private function hydrateModels(array $data): array
    {
        $models = [];

        foreach (is_array($data['models'] ?? null) ? $data['models'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '' || ! is_string($row['provider'] ?? null) || $row['provider'] === '') {
                continue;
            }

            $models[] = new ProxyCliModel($row['id'], $row['provider']);
        }

        return $models;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function hydrateAccount(array $row): ?ProxyCliAccount
    {
        if (! is_string($row['id'] ?? null) || ! is_string($row['provider'] ?? null) || ! is_string($row['label'] ?? null)) {
            return null;
        }

        $windows = [];

        foreach (is_array($row['windows'] ?? null) ? $row['windows'] : [] as $window) {
            if (! is_array($window) || ! is_string($window['label'] ?? null)) {
                continue;
            }

            $used = $window['used_percent'] ?? null;

            if (! is_int($used) && ! is_float($used)) {
                continue;
            }

            $windows[] = new ProxyCliWindow(
                $window['label'],
                (float) $used,
                is_string($window['resets_at'] ?? null) ? $window['resets_at'] : null,
            );
        }

        return new ProxyCliAccount(
            $row['id'],
            $row['provider'],
            $row['label'],
            $row['disabled'] === true,
            is_string($row['status'] ?? null) ? $row['status'] : null,
            $windows,
            is_string($row['error'] ?? null) ? $row['error'] : null,
        );
    }

    /**
     * @param  list<ProxyCliAccount>  $accounts
     */
    private function collectedAt(array $accounts): ?string
    {
        return $accounts === [] ? null : date(DATE_ATOM);
    }
}
