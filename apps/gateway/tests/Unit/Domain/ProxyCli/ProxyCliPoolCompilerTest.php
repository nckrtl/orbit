<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliAccount;
use App\Domain\ProxyCli\ProxyCliPoolCompiler;
use App\Domain\ProxyCli\ProxyCliWindow;

describe('proxycli pooling', function (): void {
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
});
