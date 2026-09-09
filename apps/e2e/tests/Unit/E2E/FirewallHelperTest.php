<?php

declare(strict_types=1);

use Symfony\Component\Process\Process as NativeProcess;

/**
 * @param list<list<string>> $prefix
 * @param list<list<string>> $suffix
 * @return array{current: bool, desired: list<list<string>>, owned: list<list<string>>, transaction: string}
 */
function probeFirewallHelper(array $prefix = [], array $suffix = [], ?string $mutation = null): array
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
        if payload["mutation"] == "extra":
            rules.insert(1, [
                "-i", payload["network"],
                "-m", "comment", "--comment", f'orbit-e2e:{payload["network"]}:stale',
                "-j", "ACCEPT",
            ])
        elif payload["mutation"] == "altered":
            rules[1] = [*rules[1][:-1], "ACCEPT"]
        elif payload["mutation"] == "duplicate":
            rules.insert(1, desired[0])
        elif payload["mutation"] == "reordered":
            rules[0], rules[1] = rules[1], rules[0]
        json.dump({
            "current": module.current(rules, desired, payload["network"]),
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
        'mutation' => $mutation,
    ], JSON_THROW_ON_ERROR));
    $process->setTimeout(10);
    $process->mustRun();

    /** @var array{current: bool, desired: list<list<string>>, owned: list<list<string>>} $result */
    $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);

    return $result;
}

/** @return array{changed: list<bool>, desired: list<list<string>>, final: list<list<string>>, transactions: list<string>} */
function reconcileFirewallHelper(): array
{
    $code = <<<'PYTHON'
        import importlib.util
        import json
        import sys

        spec = importlib.util.spec_from_file_location("orbit_firewall", sys.argv[1])
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        network = "oe-tst-123"
        desired = module.desired_rules(network)
        preserved = [
            ["-i", "oe-other", "-m", "comment", "--comment", "orbit-e2e:oe-other:egress", "-j", "ACCEPT"],
            ["-i", "oe-tst-123x", "-m", "comment", "--comment", "orbit-e2e:oe-tst-123x:egress", "-j", "ACCEPT"],
            ["-i", "administrator0", "-j", "ACCEPT"],
        ]
        rules = [
            ["-i", network, "-m", "comment", "--comment", f"orbit-e2e:{network}:stale", "-j", "ACCEPT"],
            *desired,
            *preserved,
        ]
        transactions = []

        def apply_transaction(removals, additions):
            transactions.append(module.restore_transaction(removals, additions))
            for rule in removals:
                rules.remove(rule)
            for position, rule in enumerate(additions):
                rules.insert(position, rule)

        module.forward_rules = lambda: [list(rule) for rule in rules]
        module.apply_transaction = apply_transaction
        changed = [module.reconcile("ensure", network), module.reconcile("ensure", network)]
        json.dump({
            "changed": changed,
            "desired": desired,
            "final": rules,
            "transactions": transactions,
        }, sys.stdout)
        PYTHON;
    $helper = dirname(__DIR__, 3).'/resources/host/reconcile-firewall.py';
    $process = new NativeProcess(['python3', '-c', $code, $helper]);
    $process->setTimeout(10);
    $process->mustRun();

    /** @var array{changed: list<bool>, desired: list<list<string>>, final: list<list<string>>, transactions: list<string>} $result */
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

    it('rejects an incomplete match of the exact network owned group', function (string $mutation): void {
        $result = probeFirewallHelper(mutation: $mutation);

        expect($result['current'])->toBeFalse();
    })->with([
        'extra rule' => 'extra',
        'altered rule' => 'altered',
        'duplicate rule' => 'duplicate',
        'reordered rule' => 'reordered',
    ]);

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

    it('does not claim rules for a network whose name shares the exact network prefix', function (): void {
        $sharedPrefixRule = [
            '-i',
            'oe-tst-123x',
            '-m',
            'comment',
            '--comment',
            'orbit-e2e:oe-tst-123x:egress',
            '-j',
            'ACCEPT',
        ];

        $result = probeFirewallHelper(prefix: [$sharedPrefixRule]);

        expect($result['current'])
            ->toBeTrue()
            ->and($result['owned'])
            ->toBe($result['desired'])
            ->and($result['owned'])
            ->not->toContain($sharedPrefixRule);
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

    it('repairs the complete owned group once and preserves every outside rule', function (): void {
        $result = reconcileFirewallHelper();

        expect($result['changed'])
            ->toBe([true, false])
            ->and($result['transactions'])
            ->toHaveCount(1)
            ->and($result['final'])
            ->toBe([
                ...$result['desired'],
                ['-i', 'oe-other', '-m', 'comment', '--comment', 'orbit-e2e:oe-other:egress', '-j', 'ACCEPT'],
                ['-i', 'oe-tst-123x', '-m', 'comment', '--comment', 'orbit-e2e:oe-tst-123x:egress', '-j', 'ACCEPT'],
                ['-i', 'administrator0', '-j', 'ACCEPT'],
            ]);
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
