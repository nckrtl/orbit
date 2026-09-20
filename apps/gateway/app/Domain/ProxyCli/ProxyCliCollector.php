<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliCollector
{
    /** @var array<string, string> */
    private const array UsageUrls = [
        'claude' => 'https://api.anthropic.com/api/oauth/usage',
        'codex' => 'https://chatgpt.com/backend-api/wham/usage',
        'grok' => 'https://cli-chat-proxy.grok.com/v1/billing?format=credits',
        'kimi' => 'https://api.kimi.com/coding/v1/usages',
    ];

    public function __construct(
        private ProxyCliManagementClient $client,
        private ProxyCliSnapshotStore $snapshots,
        private ProxyCliCache $cache,
        private ProxyCliQuotaParser $parser = new ProxyCliQuotaParser,
        private string $holder = 'proxycli',
    ) {}

    /**
     * @return list<ProxyCliAccount>
     */
    public function collect(string $baseUrl, string $managementKey): array
    {
        if (! $this->cache->acquire(ProxyCliKeys::Lock, $this->holder, 120)) {
            return $this->snapshots->accounts();
        }

        try {
            $accounts = [];

            foreach ($this->client->authFiles($baseUrl, $managementKey) as $file) {
                $account = $this->account($baseUrl, $managementKey, $file);

                if ($account instanceof ProxyCliAccount) {
                    $accounts[] = $account;
                }
            }

            $this->snapshots->write($accounts, date(DATE_ATOM));

            return $accounts;
        } finally {
            $this->cache->release(ProxyCliKeys::Lock, $this->holder);
        }
    }

    /**
     * @param  array<array-key, mixed>  $file
     */
    private function account(string $baseUrl, string $managementKey, array $file): ?ProxyCliAccount
    {
        $id = is_string($file['auth_index'] ?? null) ? $file['auth_index'] : (is_string($file['name'] ?? null) ? $file['name'] : null);
        $provider = $this->provider($file);

        if ($id === null || $provider === null) {
            return null;
        }

        $disabled = $file['disabled'] === true;
        $label = $this->label($file);
        $status = is_string($file['status'] ?? null) ? $file['status'] : ($disabled ? 'disabled' : 'enabled');

        if ($disabled) {
            return new ProxyCliAccount($id, $provider, $label, true, $status, []);
        }

        if ($this->snapshots->backoffUntil($id) !== null) {
            return new ProxyCliAccount($id, $provider, $label, false, 'backoff', []);
        }

        try {
            $response = $this->client->apiCall(
                $baseUrl,
                $managementKey,
                is_string($file['auth_index'] ?? null) ? $file['auth_index'] : $id,
                self::UsageUrls[$provider],
                $this->headers($provider, is_string($file['account_id'] ?? null) ? $file['account_id'] : null),
            );
        } catch (\Throwable $exception) {
            return new ProxyCliAccount($id, $provider, $label, false, 'error', [], $exception->getMessage());
        }

        if ($response->status === 429) {
            $this->snapshots->backOff($id, $response->retryAfterSeconds ?? 60);

            return new ProxyCliAccount($id, $provider, $label, false, 'backoff', []);
        }

        if ($response->status < 200 || $response->status >= 300) {
            return new ProxyCliAccount($id, $provider, $label, false, 'error', [], 'HTTP '.$response->status);
        }

        return new ProxyCliAccount(
            $id,
            $provider,
            $label,
            false,
            $status,
            $this->parser->parse($provider, $response->body),
        );
    }

    /**
     * @param  array<array-key, mixed>  $file
     */
    private function provider(array $file): ?string
    {
        foreach ([$file['provider'] ?? null, $file['account_type'] ?? null, $file['label'] ?? null, $file['name'] ?? null] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = strtolower($value);

            if (in_array($normalized, ['claude', 'anthropic'], true)) {
                return 'claude';
            }

            if (in_array($normalized, ['codex', 'openai'], true) || str_contains($normalized, 'codex') || str_contains($normalized, 'gpt')) {
                return 'codex';
            }

            if (in_array($normalized, ['grok', 'xai'], true) || str_contains($normalized, 'grok')) {
                return 'grok';
            }

            if (in_array($normalized, ['kimi', 'moonshot'], true) || str_contains($normalized, 'kimi')) {
                return 'kimi';
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $file
     */
    private function label(array $file): string
    {
        foreach ([$file['email'] ?? null, $file['label'] ?? null, $file['name'] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return basename($value, '.json');
            }
        }

        return 'account';
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $provider, ?string $accountId): array
    {
        return match ($provider) {
            'claude' => [
                'anthropic-beta' => 'oauth-2025-04-20',
                'Content-Type' => 'application/json',
            ],
            'codex' => $accountId === null ? [] : ['ChatGPT-Account-Id' => $accountId],
            'grok' => ['X-XAI-Token-Auth' => 'xai-grok-cli'],
            default => [],
        };
    }
}
