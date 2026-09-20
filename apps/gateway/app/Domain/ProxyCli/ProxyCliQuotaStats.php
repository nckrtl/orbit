<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliQuotaStats
{
    /**
     * CodexBar LLM Proxy contract: quota_groups as an array of duration windows.
     *
     * @return array{
     *     quota_groups: list<array{name: string, used_percent: float, remaining_percent: float, reset_at: string|null}>,
     *     total_requests: int,
     *     total_tokens: int,
     *     providers: array<string, array{remaining_percent: float}>
     * }
     */
    public function from(ProxyCliSnapshot $snapshot): array
    {
        $groups = [];
        $providers = [];

        foreach ($snapshot->providers as $pool) {
            $lowest = null;

            foreach ($pool->windows as $window) {
                $groups[] = [
                    'name' => $window->label,
                    'used_percent' => $window->usedPercent,
                    'remaining_percent' => $window->remainingPercent(),
                    'reset_at' => $window->resetsAt,
                ];
                $lowest = $lowest === null ? $window->remainingPercent() : min($lowest, $window->remainingPercent());
            }

            if ($lowest !== null) {
                $providers[$pool->provider] = ['remaining_percent' => $lowest];
            }
        }

        return [
            'quota_groups' => $groups,
            'total_requests' => 0,
            'total_tokens' => 0,
            'providers' => $providers,
        ];
    }
}
