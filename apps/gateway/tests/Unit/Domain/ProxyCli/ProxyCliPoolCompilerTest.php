<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliAccount;
use App\Domain\ProxyCli\ProxyCliPoolCompiler;
use App\Domain\ProxyCli\ProxyCliQuotaParser;
use App\Domain\ProxyCli\ProxyCliQuotaStats;
use App\Domain\ProxyCli\ProxyCliWindow;

describe('proxycli pooling', function (): void {
    it('orders windows longer first and never labels them Primary or Secondary', function (): void {
        $parser = new ProxyCliQuotaParser;
        $windows = $parser->parse('codex', [
            'rate_limit' => [
                'primary_window' => ['used_percent' => 10, 'limit_window_seconds' => 18_000],
                'secondary_window' => ['used_percent' => 40, 'limit_window_seconds' => 604_800],
            ],
        ]);

        expect(array_map(static fn (ProxyCliWindow $window): string => $window->label, $windows))
            ->toBe(['7d', '5h'])
            ->and($windows[0]->usedPercent)->toBe(40.0)
            ->and($windows[1]->usedPercent)->toBe(10.0);
    });

    it('omits a window the provider did not return instead of showing zero', function (): void {
        $parser = new ProxyCliQuotaParser;
        $windows = $parser->parse('claude', [
            'seven_day' => ['utilization' => 22.5, 'resets_at' => '2026-09-21T00:00:00Z'],
        ]);

        expect($windows)->toHaveCount(1)
            ->and($windows[0]->label)->toBe('7d')
            ->and($windows[0]->usedPercent)->toBe(22.5);
    });

    it('recompiles pools from cache after an account toggle without inventing windows', function (): void {
        $compiler = new ProxyCliPoolCompiler;
        $accounts = [
            new ProxyCliAccount('plus.json', 'codex', 'plus', false, 'enabled', [
                new ProxyCliWindow('7d', 20),
                new ProxyCliWindow('5h', 5),
            ]),
            new ProxyCliAccount('pro.json', 'codex', 'pro', false, 'enabled', [
                new ProxyCliWindow('7d', 80),
            ]),
        ];

        $before = $compiler->compile($accounts, '2026-09-20T12:00:00+00:00');
        $after = $compiler->compile($compiler->withDisabled($accounts, 'pro.json', true), '2026-09-20T12:01:00+00:00');

        expect($before->provider('codex')?->windows)->toHaveCount(2)
            ->and($before->provider('codex')?->windows[0]->label)->toBe('7d')
            ->and($before->provider('codex')?->windows[0]->usedPercent)->toBe(50.0)
            ->and($after->provider('codex')?->windows)->toHaveCount(2)
            ->and($after->accounts[1]->disabled)->toBeTrue()
            ->and($after->provider('codex')?->windows[0]->usedPercent)->toBe(20.0);
    });

    it('exposes CodexBar quota_groups as duration names', function (): void {
        $snapshot = new ProxyCliPoolCompiler()->compile([
            new ProxyCliAccount('plus.json', 'codex', 'plus', false, 'enabled', [
                new ProxyCliWindow('7d', 25, '2026-09-27T00:00:00+00:00'),
                new ProxyCliWindow('5h', 10, '2026-09-20T17:00:00+00:00'),
            ]),
        ], '2026-09-20T12:00:00+00:00');

        $stats = new ProxyCliQuotaStats()->from($snapshot);

        expect($stats['quota_groups'][0]['name'])->toBe('7d')
            ->and($stats['quota_groups'][1]['name'])->toBe('5h')
            ->and($stats['providers']['codex']['remaining_percent'])->toBe(75.0)
            ->and(json_encode($stats))->not->toContain('Primary')
            ->and(json_encode($stats))->not->toContain('Secondary');
    });
});
