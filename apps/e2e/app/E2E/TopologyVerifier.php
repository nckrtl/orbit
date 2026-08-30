<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\SourceState;
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

    public function verify(TopologyTarget $target, VerificationMode $mode, SourceState $source): VerificationReport
    {
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $instances[$role] = $target->instance($role);
        }
        $this->host->assertTopologyNetworkIdentity($instances, $target->network());

        $results = [];
        foreach (self::PROBES as $name => $role) {
            $results[$name] = $this->failedProbe(
                $name,
                $target->instance($role),
                'valid passing evidence for source '.$source->guestSha,
                'no evidence received',
            );
        }
        $pending = self::PROBES;
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
                    $arguments[] = 'app-dev';
                    $arguments[] = 'app-prod';
                } elseif ($name === 'source.manifest') {
                    $arguments[] = $source->treeHash ?? '-';
                    $arguments[] = base64_encode(
                        $source->overlayPaths === [] ? '' : implode("\0", $source->overlayPaths)."\0",
                    );
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
                $instance = $target->instance(self::PROBES[$name]);
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
