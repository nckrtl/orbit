<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @param list<array<string, mixed>> $nodes
 */
function runNck73ProofHelper(string $helper, array $nodes): Process
{
    $root = temporaryPath('orbit-nck-73-proof-', 5);
    mkdir("{$root}/bin", 0o700, true);
    file_put_contents("{$root}/bin/orbit", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        case "$*" in
          'node:list --json') printf '%s' "$NCK73_NODE_LIST_JSON" ;;
          'metrics:status --json') printf '%s' "$NCK73_METRICS_STATUS_JSON" ;;
          *) exit 64 ;;
        esac
        BASH);
    chmod("{$root}/bin/orbit", 0o700);

    $fixture = dirname(__DIR__, 5).'/proofs/NCK-73/lib.sh';
    $process = new Process(
        [
            'bash',
            '-c',
            'source "$1"; if [[ "$2" == metrics_address ]]; then json_get() { cat >/dev/null; printf "%s\n" "$NCK73_ASSIGNMENT_NODE_ID"; }; fi; "$2"',
            'nck-73-proof',
            $fixture,
            $helper,
        ],
        env: [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'NCK73_ASSIGNMENT_NODE_ID' => '20',
            'NCK73_NODE_LIST_JSON' => json_encode(['nodes' => $nodes], JSON_THROW_ON_ERROR),
            'NCK73_METRICS_STATUS_JSON' => json_encode(
                ['assignment' => ['node_id' => 20]],
                JSON_THROW_ON_ERROR,
            ),
        ],
    );
    $process->setTimeout(10);
    $process->run();

    return $process;
}

it('keeps the local interface lookup separate from canonical Node JSON access', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 5).'/proofs/NCK-73/lib.sh');

    expect($source)
        ->toContain(<<<'BASH'
            wireguard_address() {
              local addresses
              addresses=$(ip -4 -o addr show dev orbit)
              awk 'NR == 1 { split($4, address, "/"); print address[1] }' <<<"$addresses"
            }
            BASH)
        ->toContain('orbit metrics:status --json | json_get assignment.node_id | {')
        ->toContain('$node["wireguard_ip"]')
        ->not->toContain('$node["wireguard_address"]');
});

it('resolves the intended Node from its canonical WireGuard IP', function (string $helper, string $expected): void {
    $process = runNck73ProofHelper($helper, [
        [
            'id' => 10,
            'name' => 'gateway',
            'roles' => ['gateway'],
            'wireguard_ip' => '10.44.0.1',
            'wireguard_address' => '192.0.2.1',
        ],
        [
            'id' => 20,
            'name' => 'app-dev',
            'roles' => ['app-dev'],
            'wireguard_ip' => '10.44.0.2',
            'wireguard_address' => '192.0.2.2',
        ],
        [
            'id' => 30,
            'name' => 'app-prod',
            'roles' => ['app-prod'],
            'wireguard_ip' => '10.44.0.3',
            'wireguard_address' => '192.0.2.3',
        ],
    ]);

    expect($process->getExitCode())
        ->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())
        ->toBe($expected)
        ->and($process->getErrorOutput())
        ->toBe('');
})->with([
    'Gateway role' => ['gateway_address', '10.44.0.1'],
    'Metrics assignment' => ['metrics_address', '10.44.0.2'],
]);

it('fails closed when the selected Node has no valid canonical WireGuard IP', function (
    string $helper,
    array $canonical,
): void {
    $selected = [
        'id' => $helper === 'gateway_address' ? 10 : 20,
        'name' => $helper === 'gateway_address' ? 'gateway' : 'app-dev',
        'roles' => $helper === 'gateway_address' ? ['gateway'] : ['app-dev'],
        'wireguard_address' => '192.0.2.20',
        ...$canonical,
    ];
    $process = runNck73ProofHelper($helper, [$selected]);

    expect($process->getExitCode())
        ->not
        ->toBe(0)
        ->and($process->getOutput())
        ->toBe('');
})->with([
    'Gateway missing value' => ['gateway_address', []],
    'Gateway empty value' => ['gateway_address', ['wireguard_ip' => '']],
    'Gateway non-string value' => ['gateway_address', ['wireguard_ip' => ['10.44.0.1']]],
    'Gateway invalid value' => ['gateway_address', ['wireguard_ip' => '999.44.0.1']],
    'Metrics missing value' => ['metrics_address', []],
    'Metrics empty value' => ['metrics_address', ['wireguard_ip' => '']],
    'Metrics non-string value' => ['metrics_address', ['wireguard_ip' => ['10.44.0.2']]],
    'Metrics invalid value' => ['metrics_address', ['wireguard_ip' => '999.44.0.2']],
]);
