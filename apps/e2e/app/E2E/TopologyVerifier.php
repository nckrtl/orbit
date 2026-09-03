<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use App\E2E\Value\VerificationReport;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Bounded readiness retries and fail-closed evidence validation form one verification boundary. */
final readonly class TopologyVerifier
{
    private const array PROBES = [
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

    public function __construct(
        private IncusHost $host,
        private int $readinessTimeoutSeconds = 120,
        private int $readinessPollIntervalMicroseconds = 1_000_000,
    ) {
        if ($readinessTimeoutSeconds < 1 || $readinessPollIntervalMicroseconds < 0) {
            throw new InvalidArgumentException('Topology readiness timing must be bounded and non-negative.');
        }
    }

    /**
     * The probes one declared end state runs: every probe of a node it keeps.
     *
     * A probe runs on exactly one node, so a node the plan declares gone takes
     * its own probes with it and nothing else. The two fleet probes still run:
     * they are told which nodes to expect, so `role.assignments` is what fails
     * when a node declared absent is still registered, and
     * `wireguard.reachability` still proves every node that stayed. Only a
     * declaration that keeps the gateway alone leaves it nothing to reach.
     *
     * @return array<string, string> Probe name to the role that runs it.
     */
    public static function probesFor(TopologyEndState $endState): array
    {
        $probes = [];
        foreach (self::PROBES as $name => $role) {
            if (! $endState->keeps($role)) {
                continue;
            }
            if ($name === 'wireguard.reachability' && $endState->peers() === []) {
                continue;
            }
            $probes[$name] = $role;
        }

        return $probes;
    }

    /**
     * The probes one declared end state does not run, for the record a
     * reviewer reads.
     *
     * @return list<string>
     */
    public static function skippedProbes(TopologyEndState $endState): array
    {
        return array_values(array_diff(array_keys(self::PROBES), array_keys(self::probesFor($endState))));
    }

    public function verify(
        TopologyTarget $target,
        VerificationMode $mode,
        SourceState $source,
        ?TopologyEndState $endState = null,
        array $requiredAssignments = TopologyProfile::ASSIGNMENTS,
    ): VerificationReport {
        $declared = $endState ?? TopologyEndState::complete();
        $assignments = array_intersect_key($requiredAssignments, array_flip($declared->nodes));
        if (array_keys($assignments) !== $declared->nodes) {
            throw new InvalidArgumentException('The required topology assignment map is incomplete.');
        }
        $probes = self::probesFor($declared);
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
        }
        $this->host->assertTopologyNetworkIdentity($instances, $target->network(), $target);
        $typedCheckoutPath = $this->typedCheckoutPath($instances['app-dev']);
        if ($typedCheckoutPath !== null) {
            unset($probes['role.app-prod'], $probes['workspace.app-dev'], $probes['laravel.prod']);
        }

        $results = [];
        foreach ($probes as $name => $role) {
            $results[$name] = $this->failedProbe(
                $name,
                $target->instance($role),
                'valid passing evidence for source '.$source->guestSha,
                'no evidence received',
            );
        }
        $pending = $probes;
        $deadline = microtime(true) + $this->readinessTimeoutSeconds;

        do {
            $remainingSeconds = max(1, (int) ceil($deadline - microtime(true)));
            $commands = [];
            foreach ($pending as $name => $role) {
                $arguments = [
                    '/usr/local/bin/verify-topology.sh',
                    $name,
                    $mode->value,
                    $source->guestSha,
                    $target->instance($role),
                ];
                if ($name === 'wireguard.reachability') {
                    // The registry names the nodes by role; a declared-absent node is not among them.
                    array_push($arguments, ...$declared->peers());
                } elseif (in_array($name, ['role.assignments', 'metrics.publication'], true)) {
                    $arguments[] = base64_encode(json_encode($assignments, JSON_THROW_ON_ERROR));
                } elseif ($name === 'source.manifest') {
                    $arguments[] = $source->treeHash ?? '-';
                    $arguments[] = base64_encode(
                        $source->overlayPaths === [] ? '' : implode("\0", $source->overlayPaths)."\0",
                    );
                }
                if ($typedCheckoutPath !== null && in_array($name, ['role.app-dev', 'laravel.dev'], true)) {
                    $arguments[] = $typedCheckoutPath;
                }
                // A mounted source adds the expected `.git` pointer hash: the guest
                // must hash the pointer file it sees through the mount itself.
                if ($source->pointerHash !== null && str_starts_with($name, 'source.')) {
                    $arguments[] = $source->pointerHash;
                }
                $commands[$name] = [
                    'instance' => $target->instance($role),
                    'command' => new GuestCommand($arguments, min(30, $remainingSeconds)),
                ];
            }

            $probeResults = $this->host->execAll($commands);
            foreach ($pending as $name => $_role) {
                $result = $probeResults[$name] ?? null;
                $instance = $target->instance($probes[$name]);
                if ($result instanceof GuestCommandResult) {
                    $evidence = $this->probe($name, $result, $source->guestSha, $instance);
                    if ($evidence !== null) {
                        $results[$name] = $evidence;
                        unset($pending[$name]);
                        continue;
                    }
                    $results[$name] = $this->failedProbe(
                        $name,
                        $instance,
                        'valid passing evidence for source '.$source->guestSha,
                        $result->successful() ? 'malformed evidence' : 'guest exit '.$result->exitCode,
                    );
                } else {
                    $results[$name] = $this->failedProbe(
                        $name,
                        $instance,
                        'valid passing evidence for source '.$source->guestSha,
                        'missing batch result',
                    );
                }
            }

            if ($pending === [] || $mode === VerificationMode::Proof || microtime(true) >= $deadline) {
                break;
            }

            if ($this->readinessPollIntervalMicroseconds > 0) {
                usleep($this->readinessPollIntervalMicroseconds);
            }
        } while (microtime(true) < $deadline);

        $passed = ! array_filter($results, static fn (array $probe): bool => ! $probe['passed']);

        return new VerificationReport($passed, $results);
    }

    private function typedCheckoutPath(string $appDevInstance): ?string
    {
        $results = $this->host->execAll([
            'sample-app-state' => [
                'instance' => $appDevInstance,
                'command' => new GuestCommand(['/usr/local/bin/converge-sample-app.sh', 'inspect-state'], 30),
            ],
        ]);
        $result = $results['sample-app-state'] ?? null;
        if (! $result instanceof GuestCommandResult || ! $result->successful()) {
            throw new RuntimeException('Failed to inspect the sample App convergence state.');
        }

        try {
            $state = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Sample App convergence state is malformed.', 0, $exception);
        }

        if ($state === ['shape' => 'instances']) {
            return null;
        }
        if (
            ! is_array($state)
            || array_keys($state) !== ['shape', 'app_id', 'node_id', 'name', 'checkout_path', 'effective_root']
            || ($state['shape'] ?? null) !== 'app_instances'
            || ! is_int($state['app_id'] ?? null)
            || ! is_int($state['node_id'] ?? null)
            || ($state['name'] ?? null) !== 'e2e-dev'
            || ! is_string($state['checkout_path'] ?? null)
            || ! str_starts_with($state['checkout_path'], '/')
            || ($state['effective_root'] ?? null) !== 'public'
        ) {
            throw new RuntimeException('Sample App convergence state is invalid.');
        }

        return $state['checkout_path'];
    }

    /** @return array{passed:bool,checked_at:string,expected:string,observed:string,evidence_ref:string}|null */
    private function probe(
        string $name,
        GuestCommandResult $result,
        string $identity,
        string $instance,
    ): ?array {
        try {
            if (! $result->successful()) {
                return null;
            }

            $evidence = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($evidence)
            || array_keys($evidence) !== [
                'probe',
                'passed',
                'identity',
                'checked_at',
                'expected',
                'observed',
                'evidence_ref',
            ]
            || ($evidence['probe'] ?? null) !== $name
            || ($evidence['passed'] ?? null) !== true
            || ($evidence['identity'] ?? null) !== $identity
            || ($evidence['evidence_ref'] ?? null) !== "incus://{$instance}/{$name}"
        ) {
            return null;
        }

        /** @var array{passed:bool,checked_at:string,expected:string,observed:string,evidence_ref:string} $probe */
        $probe = [
            'passed' => $evidence['passed'],
            'checked_at' => $evidence['checked_at'],
            'expected' => $evidence['expected'],
            'observed' => $evidence['observed'],
            'evidence_ref' => $evidence['evidence_ref'],
        ];

        try {
            new VerificationReport(true, [$name => $probe]);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $probe;
    }

    /** @return array{passed:false,checked_at:string,expected:string,observed:string,evidence_ref:string} */
    private function failedProbe(string $name, string $instance, string $expected, string $observed): array
    {
        return [
            'passed' => false,
            'checked_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'expected' => $expected,
            'observed' => $observed,
            'evidence_ref' => "incus://{$instance}/{$name}",
        ];
    }
}
