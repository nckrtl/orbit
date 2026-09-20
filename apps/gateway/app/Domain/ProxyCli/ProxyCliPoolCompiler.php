<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliPoolCompiler
{
    public function __construct(
        private ProxyCliWindowOrder $order = new ProxyCliWindowOrder,
    ) {}

    /**
     * @param  list<ProxyCliAccount>  $accounts
     */
    public function compile(array $accounts, ?string $collectedAt): ProxyCliSnapshot
    {
        $grouped = [];

        foreach ($accounts as $account) {
            $grouped[$account->provider][] = $account;
        }

        ksort($grouped);
        $providers = [];

        foreach ($grouped as $provider => $members) {
            $providers[] = new ProxyCliProviderPool(
                $provider,
                $this->poolWindows($members),
                array_values($members),
            );
        }

        return new ProxyCliSnapshot($accounts, $providers, $collectedAt);
    }

    /**
     * Toggle only changes the disabled flag on the cached account. It never invents windows.
     *
     * @param  list<ProxyCliAccount>  $accounts
     * @return list<ProxyCliAccount>
     */
    public function withDisabled(array $accounts, string $accountId, bool $disabled): array
    {
        return array_values(array_map(
            static function (ProxyCliAccount $account) use ($accountId, $disabled): ProxyCliAccount {
                if ($account->id !== $accountId) {
                    return $account;
                }

                return new ProxyCliAccount(
                    $account->id,
                    $account->provider,
                    $account->label,
                    $disabled,
                    $disabled ? 'disabled' : 'enabled',
                    $account->windows,
                    $account->error,
                );
            },
            $accounts,
        ));
    }

    /**
     * @param  list<ProxyCliAccount>  $accounts
     * @return list<ProxyCliWindow>
     */
    private function poolWindows(array $accounts): array
    {
        $used = [];
        $count = [];
        $resets = [];

        foreach ($accounts as $account) {
            if ($account->disabled) {
                continue;
            }

            foreach ($account->windows as $window) {
                $used[$window->label] = ($used[$window->label] ?? 0.0) + $window->usedPercent;
                $count[$window->label] = ($count[$window->label] ?? 0) + 1;
                $resets[$window->label] = $this->earliest($resets[$window->label] ?? null, $window->resetsAt);
            }
        }

        $windows = [];

        foreach ($used as $label => $total) {
            $windows[] = new ProxyCliWindow(
                $label,
                $total / $count[$label],
                $resets[$label],
            );
        }

        return $this->order->sort($windows);
    }

    private function earliest(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return strtotime($left) <= strtotime($right) ? $left : $right;
    }
}
