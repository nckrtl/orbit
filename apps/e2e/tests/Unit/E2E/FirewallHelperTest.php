<?php

declare(strict_types=1);

use Symfony\Component\Process\Process as NativeProcess;

/**
 * @param list<list<string>> $prefix
 * @param list<list<string>> $suffix
 * @return array{current: bool, desired: list<list<string>>, owned: list<list<string>>, transaction: string}
 */
function probeFirewallHelper(array $prefix = [], array $suffix = []): array
{
    $code = <<<'PYTHON'
        import importlib.util
        import json
        import sys

        spec = importlib.util.spec_from_file_location("orbit_firewall", sys.argv[1])
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        payload = json.load(sys.stdin)
        desired = module.desired_rules(payload["network"])
        rules = payload["prefix"] + desired + payload["suffix"]
        json.dump({
            "current": module.current(rules, desired),
            "desired": desired,
            "owned": module.owned_rules(rules, payload["network"]),
            "transaction": module.restore_transaction([desired[0]], desired),
        }, sys.stdout)
        PYTHON;
    $helper = dirname(__DIR__, 3).'/resources/host/reconcile-firewall.py';
    $process = new NativeProcess(['python3', '-c', $code, $helper]);
    $process->setInput(json_encode([
        'network' => 'oe-tst-123',
        'prefix' => $prefix,
        'suffix' => $suffix,
    ], JSON_THROW_ON_ERROR));
    $process->setTimeout(10);
    $process->mustRun();

    /** @var array{current: bool, desired: list<list<string>>, owned: list<list<string>>} $result */
    $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);

    return $result;
}

describe('host firewall helper', function (): void {
    it('marks every forwarding rule with exact Orbit ownership', function (): void {
        $result = probeFirewallHelper();

        expect($result['current'])
            ->toBeTrue()
            ->and($result['owned'])
            ->toBe($result['desired'])
            ->and(array_map(
                static fn (array $rule): string|false => $rule[array_search('--comment', $rule, true) + 1] ?? false,
                $result['desired'],
            ))
            ->toBe([
                'orbit-e2e:oe-tst-123:intra',
                'orbit-e2e:oe-tst-123:isolate',
                'orbit-e2e:oe-tst-123:egress',
                'orbit-e2e:oe-tst-123:return',
            ]);
    });

    it('requires managed isolation rules to precede a broad administrator accept', function (): void {
        $result = probeFirewallHelper(prefix: [['-j', 'ACCEPT']]);

        expect($result['current'])->toBeFalse();
    });

    it('accepts another Orbit topology rule before the current topology group', function (): void {
        $otherTopology = [
            '-i',
            'oe-other',
            '-m',
            'comment',
            '--comment',
            'orbit-e2e:oe-other:egress',
            '-j',
            'ACCEPT',
        ];

        $result = probeFirewallHelper(prefix: [$otherTopology]);

        expect($result['current'])->toBeTrue();
    });

    it('does not claim an unmarked administrator rule with identical traffic matches', function (): void {
        $administratorRule = ['-i', 'oe-tst-123', '-o', 'oe-tst-123', '-j', 'ACCEPT'];

        $result = probeFirewallHelper(suffix: [$administratorRule]);

        expect($result['current'])
            ->toBeTrue()
            ->and($result['owned'])
            ->toBe($result['desired'])
            ->and($result['owned'])
            ->not->toContain($administratorRule);
    });

    it('reconciles all owned rules in one no-flush restore transaction', function (): void {
        $result = probeFirewallHelper();

        expect($result['transaction'])
            ->toBe(implode("\n", [
                '*filter',
                '-D FORWARD -i oe-tst-123 -o oe-tst-123 -m comment --comment orbit-e2e:oe-tst-123:intra -j ACCEPT',
                '-I FORWARD 1 -i oe-tst-123 -o oe-tst-123 -m comment --comment orbit-e2e:oe-tst-123:intra -j ACCEPT',
                '-I FORWARD 2 -i oe-tst-123 -o oe+ -m comment --comment orbit-e2e:oe-tst-123:isolate -j DROP',
                '-I FORWARD 3 -i oe-tst-123 -m conntrack --ctstate NEW,RELATED,ESTABLISHED -m comment --comment orbit-e2e:oe-tst-123:egress -j ACCEPT',
                '-I FORWARD 4 -o oe-tst-123 -m conntrack --ctstate RELATED,ESTABLISHED -m comment --comment orbit-e2e:oe-tst-123:return -j ACCEPT',
                'COMMIT',
                '',
            ]));
    });
});
