<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyVerifier;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/** @return array<string, string> */
function topologyVerifierProbeRoles(): array
{
    return [
        'vm.gateway.running' => 'gateway',
        'vm.app-dev.running' => 'app-dev',
        'vm.app-prod.running' => 'app-prod',
        'role.gateway' => 'gateway',
        'role.app-dev' => 'app-dev',
        'role.app-prod' => 'app-prod',
        'role.assignments' => 'gateway',
        'metrics.publication' => 'gateway',
        'service.gateway' => 'gateway',
        'service.vpn' => 'gateway',
        'wireguard.reachability' => 'gateway',
        'operator.app-dev' => 'app-dev',
        'https.gateway-internal' => 'app-dev',
        'php-fpm.app-dev' => 'app-dev',
        'caddy.app-dev' => 'app-dev',
        'laravel.dev' => 'app-dev',
        'workspace.app-dev' => 'app-dev',
        'php-fpm.app-prod' => 'app-prod',
        'caddy.app-prod' => 'app-prod',
        'laravel.prod' => 'app-prod',
        'source.gateway' => 'gateway',
        'source.app-dev' => 'app-dev',
        'source.manifest' => 'gateway',
    ];
}

function setUpTopologyVerifierProcessFacade(): void
{
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
}

function isTopologyVerifierHelper(PendingProcess $process): bool
{
    return (
        is_array($process->command)
        && count($process->command) === 2
        && ($process->command[0] ?? null) === 'python3'
        && is_string($process->command[1] ?? null)
        && str_ends_with($process->command[1], '/resources/host/exec-all.py')
    );
}

function isDirectTopologyVerifierProbe(PendingProcess $process): bool
{
    return (
        is_array($process->command)
        && ($process->command[0] ?? null) === 'incus'
        && in_array('exec', $process->command, true)
    );
}

function isGlobalIpv4TopologyVerifierProbe(array $argv): bool
{
    return $argv === [
        'sh',
        '-c',
        'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ] && ip -4 -o addr show dev "$interface" scope global',
    ];
}

function topologyVerifierInventory(PendingProcess $process): ?ProcessResult
{
    $command = $process->command;
    if (! is_array($command) || ($command[array_key_last($command)] ?? null) !== '--format=json') {
        return null;
    }

    if (in_array('network', $command, true)) {
        return Process::result(json_encode([[
            'name' => 'oe-standby',
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'ipv4.address' => '10.232.1.1/24',
            ],
        ]], JSON_THROW_ON_ERROR));
    }

    $target = $command[array_key_last($command) - 1];
    $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;
    $roles = $name === ''
        ? ['gateway', 'app-dev', 'app-prod']
        : [
            str_ends_with($name, '-gateway')
                ? 'gateway'
                : (str_ends_with($name, '-app-dev') ? 'app-dev' : 'app-prod'),
        ];

    return Process::result(json_encode(array_map(static function (string $role): array {
        $name = 'orbit-e2e-standby-'.$role;
        $mac = implode(':', str_split(substr(sha1('oe-standby:'.$role), 0, 6), 2));

        $ipv4 = ['gateway' => '10.232.1.10', 'app-dev' => '10.232.1.11', 'app-prod' => '10.232.1.12'][$role];

        return [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => 'Running',
            'status_code' => 103,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => [
                'root' => ['pool' => 'orbit-e2e'],
                'eth0' => [
                    'network' => 'oe-standby',
                    'ipv4.address' => $ipv4,
                    'hwaddr' => '00:16:3e:'.$mac,
                ],
            ],
        ];
    }, $roles), JSON_THROW_ON_ERROR));
}

/** @param array{label:string,instance:string,argv:list<string>} $request */
function topologyVerifierEvidence(array $request, string $sha): string
{
    $probe = $request['label'];
    $instance = preg_replace('/^[^:]+:/', '', $request['instance']);

    return json_encode([
        'probe' => $probe,
        'passed' => true,
        'identity' => $sha,
        'checked_at' => '2026-08-29T12:34:56+00:00',
        'expected' => 'healthy',
        'observed' => 'healthy',
        'evidence_ref' => "incus://{$instance}/{$probe}",
    ], JSON_THROW_ON_ERROR);
}

/**
 * @param array{label:string,project:string,instance:string,argv:list<string>,timeout:int,stdin:?string} $request
 * @param array<string, string> $probeRoles
 */
function assertTopologyVerifierRequest(array $request, array $probeRoles, string $sha): void
{
    $probe = $request['label'];
    $role = $probeRoles[$probe] ?? null;
    expect($role)->not->toBeNull();

    $arguments = [
        '/usr/local/bin/verify-topology.sh',
        $probe,
        'readiness',
        $sha,
        'orbit-e2e-standby-'.$role,
    ];
    if ($probe === 'wireguard.reachability') {
        $arguments[] = 'app-dev';
        $arguments[] = 'app-prod';
    } elseif (in_array($probe, ['role.assignments', 'metrics.publication'], true)) {
        $arguments[] = base64_encode(json_encode(TopologyProfile::ASSIGNMENTS, JSON_THROW_ON_ERROR));
    } elseif ($probe === 'source.manifest') {
        $arguments[] = '-';
        $arguments[] = '';
    }

    expect($request)->toBe([
        'label' => $probe,
        'project' => 'default',
        'instance' => 'local:orbit-e2e-standby-'.$role,
        'argv' => $arguments,
        'timeout' => 30,
        'stdin' => null,
    ]);
}

describe('TopologyVerifier mounted source', function () {
    it('passes the expected git pointer hash only to the source probes of a mounted source', function () {
        setUpTopologyVerifierProcessFacade();
        $sha = str_repeat('a', 40);
        $pointer = str_repeat('f', 64);
        $argv = [];

        Process::fake(function (PendingProcess $process) use ($sha, &$argv) {
            $inventory = topologyVerifierInventory($process);
            if ($inventory instanceof ProcessResult) {
                return $inventory;
            }
            $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
            $results = [];
            foreach ($payload['requests'] as $request) {
                $argv[$request['label']] = $request['argv'];
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => isGlobalIpv4TopologyVerifierProbe($request['argv'] ?? [])
                        ? '2: enp5s0    inet 192.0.2.1/24 scope global'
                        : topologyVerifierEvidence($request, $sha),
                    'stderr' => '',
                    'exit_code' => 0,
                ];
            }

            return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
        });

        $target = TopologyTarget::standby();
        new TopologyVerifier(
            new IncusHost(pool: 'orbit-e2e'),
            readinessTimeoutSeconds: 60,
            readinessPollIntervalMicroseconds: 0,
        )->verify(
            $target,
            VerificationMode::Readiness,
            new SourceState($sha, $sha, mounted: true, pointerHash: $pointer),
        );

        $script = '/usr/local/bin/verify-topology.sh';
        expect($argv['source.gateway'] ?? null)
            ->toBe([$script, 'source.gateway', 'readiness', $sha, $target->instance('gateway'), $pointer])
            ->and($argv['source.app-dev'] ?? null)
            ->toBe([$script, 'source.app-dev', 'readiness', $sha, $target->instance('app-dev'), $pointer])
            ->and($argv['source.manifest'] ?? null)
            ->toBe([$script, 'source.manifest', 'readiness', $sha, $target->instance('gateway'), '-', '', $pointer])
            ->and($argv['role.gateway'] ?? null)
            ->toBe([$script, 'role.gateway', 'readiness', $sha, $target->instance('gateway')]);
    });
});

describe('TopologyVerifier', function () {
    it('runs all named probes through one concurrent host helper and preserves evidence', function () {
        setUpTopologyVerifierProcessFacade();
        $sha = str_repeat('a', 40);
        $probeRoles = topologyVerifierProbeRoles();
        $batches = [];
        $inventoryReads = 0;

        Process::fake(function (PendingProcess $process) use ($sha, $probeRoles, &$batches, &$inventoryReads) {
            $inventory = topologyVerifierInventory($process);
            if ($inventory instanceof ProcessResult) {
                $inventoryReads++;

                return $inventory;
            }

            expect(isTopologyVerifierHelper($process))->toBeTrue();
            $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
            expect(array_keys($payload))->toBe(['requests']);
            $batches[] = array_column($payload['requests'], 'label');
            $results = [];
            foreach ($payload['requests'] as $request) {
                if (isGlobalIpv4TopologyVerifierProbe($request['argv'] ?? [])) {
                    $results[] = [
                        'label' => $request['label'],
                        'stdout' =>
                            '2: enp5s0    inet 192.0.2.'
                                .['gateway' => 1, 'app-dev' => 2, 'app-prod' => 3][$request['label']]
                                .'/24 scope global',
                        'stderr' => '',
                        'exit_code' => 0,
                    ];
                    continue;
                }
                assertTopologyVerifierRequest($request, $probeRoles, $sha);
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => topologyVerifierEvidence($request, $sha),
                    'stderr' => '',
                    'exit_code' => 0,
                ];
            }

            return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
        });

        $report = new TopologyVerifier(
            new IncusHost(pool: 'orbit-e2e'),
            readinessTimeoutSeconds: 60,
            readinessPollIntervalMicroseconds: 0,
        )->verify(
            TopologyTarget::standby(),
            VerificationMode::Readiness,
            new SourceState($sha, $sha),
        );

        expect($report->passed)
            ->toBeTrue()
            ->and($report->probes)
            ->toHaveCount(23)
            ->and($report->probes['service.vpn'] ?? null)
            ->toBe([
                'passed' => true,
                'checked_at' => '2026-08-29T12:34:56+00:00',
                'expected' => 'healthy',
                'observed' => 'healthy',
                'evidence_ref' => 'incus://orbit-e2e-standby-gateway/service.vpn',
            ])
            ->and($batches)
            ->toBe([array_keys($probeRoles)])
            ->and($inventoryReads)
            ->toBe(3);
        Process::assertRanTimes(isTopologyVerifierHelper(...), 1);
        Process::assertNotRan(isDirectTopologyVerifierProbe(...));
    });

    it('fails closed when the host helper returns malformed output', function () {
        setUpTopologyVerifierProcessFacade();
        Process::fake(function (PendingProcess $process) {
            return topologyVerifierInventory($process) ?? Process::result('{');
        });
        $sha = str_repeat('a', 40);

        expect(fn () => new TopologyVerifier(new IncusHost(pool: 'orbit-e2e'))->verify(
            TopologyTarget::standby(),
            VerificationMode::Proof,
            new SourceState($sha, $sha),
        ))
            ->toThrow(RuntimeException::class, 'Incus guest command batch failed: Syntax error');
        Process::assertRanTimes(isTopologyVerifierHelper(...), 1);
        Process::assertNotRan(isDirectTopologyVerifierProbe(...));
    });

    it('does not retry readiness after a host helper failure', function () {
        setUpTopologyVerifierProcessFacade();
        $helperCalls = 0;
        Process::fake(function (PendingProcess $process) use (&$helperCalls) {
            $inventory = topologyVerifierInventory($process);
            if ($inventory instanceof ProcessResult) {
                return $inventory;
            }

            $helperCalls++;

            return Process::result('', 'helper unavailable', 9);
        });

        expect(fn () => new TopologyVerifier(
            new IncusHost(pool: 'orbit-e2e'),
            readinessTimeoutSeconds: 60,
            readinessPollIntervalMicroseconds: 1_000,
        )->verify(
            TopologyTarget::standby(),
            VerificationMode::Readiness,
            new SourceState(str_repeat('a', 40), str_repeat('a', 40)),
        ))
            ->toThrow(RuntimeException::class, 'Batch helper failed: helper unavailable.')
            ->and($helperCalls)
            ->toBe(1);
        Process::assertNotRan(isDirectTopologyVerifierProbe(...));
    });

    it('retries only pending readiness probes together and preserves attempt results', function () {
        setUpTopologyVerifierProcessFacade();
        $sha = str_repeat('a', 40);
        $probeRoles = topologyVerifierProbeRoles();
        $attempts = array_fill_keys(array_keys($probeRoles), 0);
        $batches = [];
        $transient = ['service.vpn', 'wireguard.reachability'];

        Process::fake(function (PendingProcess $process) use ($sha, $probeRoles, $transient, &$attempts, &$batches) {
            $inventory = topologyVerifierInventory($process);
            if ($inventory instanceof ProcessResult) {
                return $inventory;
            }

            expect(isTopologyVerifierHelper($process))->toBeTrue();
            $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
            $batches[] = array_column($payload['requests'], 'label');
            $results = [];
            foreach ($payload['requests'] as $request) {
                if (isGlobalIpv4TopologyVerifierProbe($request['argv'] ?? [])) {
                    $results[] = [
                        'label' => $request['label'],
                        'stdout' =>
                            '2: enp5s0    inet 192.0.2.'
                                .['gateway' => 1, 'app-dev' => 2, 'app-prod' => 3][$request['label']]
                                .'/24 scope global',
                        'stderr' => '',
                        'exit_code' => 0,
                    ];
                    continue;
                }
                assertTopologyVerifierRequest($request, $probeRoles, $sha);
                $attempts[$request['label']]++;
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => topologyVerifierEvidence($request, $sha),
                    'stderr' => in_array($request['label'], $transient, true) && $attempts[$request['label']] === 1
                        ? 'not ready'
                        : '',
                    'exit_code' => in_array($request['label'], $transient, true) && $attempts[$request['label']] === 1
                        ? 1
                        : 0,
                ];
            }

            return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
        });

        $report = new TopologyVerifier(
            new IncusHost(pool: 'orbit-e2e'),
            readinessTimeoutSeconds: 60,
            readinessPollIntervalMicroseconds: 0,
        )->verify(
            TopologyTarget::standby(),
            VerificationMode::Readiness,
            new SourceState($sha, $sha),
        );

        $singleAttempts = array_diff_key($attempts, array_flip($transient));
        expect($report->passed)
            ->toBeTrue()
            ->and($batches)
            ->toBe([array_keys($probeRoles), $transient])
            ->and(array_values(array_unique($singleAttempts)))
            ->toBe([1])
            ->and(array_intersect_key($attempts, array_flip($transient)))
            ->toBe(['service.vpn' => 2, 'wireguard.reachability' => 2]);
        Process::assertRanTimes(isTopologyVerifierHelper(...), 2);
        Process::assertNotRan(isDirectTopologyVerifierProbe(...));
    });
});

/**
 * Run one verification against a fake host that answers every probe, failing
 * exactly the named ones. Returns the report and the argv of each probe.
 *
 * @param list<string> $failing
 * @return array{report:VerificationReport,argv:array<string, list<string>>,batches:list<list<string>>}
 */
function runTopologyVerifierWithEndState(?TopologyEndState $endState, array $failing = []): array
{
    setUpTopologyVerifierProcessFacade();
    $sha = str_repeat('a', 40);
    $argv = [];
    $batches = [];

    Process::fake(function (PendingProcess $process) use ($sha, $failing, &$argv, &$batches) {
        $inventory = topologyVerifierInventory($process);
        if ($inventory instanceof ProcessResult) {
            return $inventory;
        }
        $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
        $labels = [];
        $results = [];
        foreach ($payload['requests'] as $request) {
            if (isGlobalIpv4TopologyVerifierProbe($request['argv'] ?? [])) {
                $results[] = [
                    'label' => $request['label'],
                    'stdout' =>
                        '2: enp5s0    inet 192.0.2.'
                            .['gateway' => 1, 'app-dev' => 2, 'app-prod' => 3][$request['label']]
                            .'/24 scope global',
                    'stderr' => '',
                    'exit_code' => 0,
                ];
                continue;
            }
            $labels[] = $request['label'];
            $argv[$request['label']] = $request['argv'];
            $failed = in_array($request['label'], $failing, true);
            $results[] = [
                'label' => $request['label'],
                'stdout' => $failed ? '' : topologyVerifierEvidence($request, $sha),
                'stderr' => $failed ? 'probe refused' : '',
                'exit_code' => $failed ? 1 : 0,
            ];
        }
        $batches[] = $labels;

        return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
    });

    $report = new TopologyVerifier(
        new IncusHost(pool: 'orbit-e2e'),
        readinessTimeoutSeconds: 60,
        readinessPollIntervalMicroseconds: 0,
    )->verify(TopologyTarget::standby(), VerificationMode::Proof, new SourceState($sha, $sha), $endState);

    return ['report' => $report, 'argv' => $argv, 'batches' => $batches];
}

describe('TopologyVerifier declared end state', function (): void {
    it('runs every probe when the plan declares nothing', function (): void {
        expect(TopologyVerifier::probesFor(TopologyEndState::complete()))
            ->toBe(topologyVerifierProbeRoles())
            ->and(TopologyVerifier::skippedProbes(TopologyEndState::complete()))
            ->toBe([]);
    });

    it('skips only the probes that run on a declared-absent node', function (): void {
        $endState = TopologyEndState::fromArray(['nodes' => ['gateway', 'app-dev']]);

        expect(TopologyVerifier::skippedProbes($endState))
            ->toBe(['vm.app-prod.running', 'role.app-prod', 'php-fpm.app-prod', 'caddy.app-prod', 'laravel.prod'])
            ->and(array_keys(TopologyVerifier::probesFor($endState)))
            ->toContain('role.assignments', 'wireguard.reachability', 'role.app-dev', 'source.manifest');
    });

    it('drops the reachability probe only when no node is left to reach', function (): void {
        expect(TopologyVerifier::skippedProbes(TopologyEndState::fromArray(['nodes' => ['gateway']])))
            ->toContain('wireguard.reachability', 'role.app-dev', 'operator.app-dev', 'source.app-dev')
            ->and(TopologyVerifier::probesFor(TopologyEndState::fromArray(['nodes' => ['gateway']])))
            ->toBe([
                'vm.gateway.running' => 'gateway',
                'role.gateway' => 'gateway',
                'role.assignments' => 'gateway',
                'metrics.publication' => 'gateway',
                'service.gateway' => 'gateway',
                'service.vpn' => 'gateway',
                'source.gateway' => 'gateway',
                'source.manifest' => 'gateway',
            ]);
    });

    it('tells the fleet probes which nodes to expect and runs nothing on the absent node', function (): void {
        $run = runTopologyVerifierWithEndState(TopologyEndState::fromArray(['nodes' => ['gateway', 'app-dev']]));
        $sha = str_repeat('a', 40);
        $script = '/usr/local/bin/verify-topology.sh';
        $gateway = TopologyTarget::standby()->instance('gateway');

        expect($run['report']->passed)
            ->toBeTrue()
            ->and($run['argv']['role.assignments'] ?? null)
            ->toBe([
                $script,
                'role.assignments',
                'proof',
                $sha,
                $gateway,
                base64_encode(json_encode([
                    'gateway' => ['gateway', 'vpn'],
                    'app-dev' => ['app-dev', 'metrics'],
                ], JSON_THROW_ON_ERROR)),
            ])
            ->and($run['argv']['wireguard.reachability'] ?? null)
            ->toBe([$script, 'wireguard.reachability', 'proof', $sha, $gateway, 'app-dev'])
            ->and($run['argv']['metrics.publication'] ?? null)
            ->toBe([
                $script,
                'metrics.publication',
                'proof',
                $sha,
                $gateway,
                base64_encode(json_encode([
                    'gateway' => ['gateway', 'vpn'],
                    'app-dev' => ['app-dev', 'metrics'],
                ], JSON_THROW_ON_ERROR)),
            ])
            ->and(array_keys($run['argv']))
            ->not
            ->toContain('role.app-prod')
            ->and($run['batches'])
            ->toBe([array_keys(TopologyVerifier::probesFor(
                TopologyEndState::fromArray(['nodes' => ['gateway', 'app-dev']]),
            ))]);
    });

    it('calls the fleet probes exactly as before when the plan declares nothing', function (): void {
        $run = runTopologyVerifierWithEndState(null);
        $sha = str_repeat('a', 40);
        $script = '/usr/local/bin/verify-topology.sh';
        $gateway = TopologyTarget::standby()->instance('gateway');

        expect($run['argv']['role.assignments'] ?? null)
            ->toBe([
                $script,
                'role.assignments',
                'proof',
                $sha,
                $gateway,
                base64_encode(json_encode(TopologyProfile::ASSIGNMENTS, JSON_THROW_ON_ERROR)),
            ])
            ->and($run['argv']['wireguard.reachability'] ?? null)
            ->toBe([$script, 'wireguard.reachability', 'proof', $sha, $gateway, 'app-dev', 'app-prod'])
            ->and($run['report']->probes)
            ->toHaveCount(23);
    });

    it('keeps the Gateway publication probe when app-dev is declared absent', function (): void {
        $endState = TopologyEndState::fromArray(['nodes' => ['gateway', 'app-prod']]);
        $run = runTopologyVerifierWithEndState($endState);
        $sha = str_repeat('a', 40);

        expect($run['report']->passed)
            ->toBeTrue()
            ->and($run['argv']['metrics.publication'] ?? null)
            ->toBe([
                '/usr/local/bin/verify-topology.sh',
                'metrics.publication',
                'proof',
                $sha,
                TopologyTarget::standby()->instance('gateway'),
                base64_encode(json_encode([
                    'gateway' => ['gateway', 'vpn'],
                    'app-prod' => ['app-prod'],
                ], JSON_THROW_ON_ERROR)),
            ])
            ->and(TopologyVerifier::skippedProbes($endState))
            ->not->toContain('metrics.publication');
    });

    it('fails when a node declared absent is still registered', function (): void {
        // The gateway registry probe is what sees it, and a declaration never skips it.
        $run = runTopologyVerifierWithEndState(
            TopologyEndState::fromArray(['nodes' => ['gateway', 'app-dev']]),
            ['role.assignments'],
        );

        expect($run['report']->passed)
            ->toBeFalse()
            ->and($run['report']->probes['role.assignments']['passed'] ?? null)
            ->toBeFalse()
            ->and($run['report']->failedSummary())
            ->toContain('role.assignments');
    });

    it('fails when a node is removed without a declaration', function (): void {
        $run = runTopologyVerifierWithEndState(null, ['role.app-prod', 'role.assignments']);

        expect($run['report']->passed)
            ->toBeFalse()
            ->and($run['report']->probes['role.app-prod']['passed'] ?? null)
            ->toBeFalse()
            ->and($run['report']->probes['laravel.prod']['passed'] ?? null)
            ->toBeTrue();
    });
});
