<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use App\E2E\Value\VerificationReport;
use InvalidArgumentException;
use JsonException;

/** @mago-expect lint:cyclomatic-complexity Bounded readiness retries and fail-closed evidence validation form one verification boundary. */
final readonly class TopologyVerifier
{
    private const array PROBES = [
        'vm.gateway.running' => 'gateway',
        'vm.app-dev.running' => 'app-dev',
        'vm.app-prod.running' => 'app-prod',
        'role.gateway' => 'gateway',
        'role.app-dev' => 'app-dev',
        'role.app-prod' => 'app-prod',
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
        private GuestTransport $host,
        private int $readinessTimeoutSeconds = 120,
        private int $readinessPollIntervalMicroseconds = 1_000_000,
    ) {
        if ($readinessTimeoutSeconds < 1 || $readinessPollIntervalMicroseconds < 0) {
            throw new InvalidArgumentException('Topology readiness timing must be bounded and non-negative.');
        }
    }

    public function verify(TopologyTarget $target, VerificationMode $mode, SourceState $source): VerificationReport
    {
        $results = array_fill_keys(array_keys(self::PROBES), false);
        $pending = self::PROBES;
        $deadline = microtime(true) + $this->readinessTimeoutSeconds;

        do {
            foreach ($pending as $name => $role) {
                $remainingSeconds = max(1, (int) ceil($deadline - microtime(true)));
                $arguments = [
                    '/usr/local/bin/verify-topology.sh',
                    $name,
                    $mode->value,
                    $source->guestSha,
                ];
                if ($name === 'wireguard.reachability') {
                    $arguments[] = $target->instance('app-dev');
                    $arguments[] = $target->instance('app-prod');
                }
                if ($this->probe(
                    $target->instance($role),
                    $name,
                    $arguments,
                    $source->guestSha,
                    min(30, $remainingSeconds),
                )) {
                    $results[$name] = true;
                    unset($pending[$name]);
                }

                if (microtime(true) >= $deadline) {
                    break;
                }
            }

            if ($pending === [] || $mode === VerificationMode::Proof || microtime(true) >= $deadline) {
                break;
            }

            if ($this->readinessPollIntervalMicroseconds > 0) {
                usleep($this->readinessPollIntervalMicroseconds);
            }
        } while (microtime(true) < $deadline);

        return new VerificationReport(! in_array(false, $results, true), $results);
    }

    /** @param list<string> $arguments */
    private function probe(
        string $instance,
        string $name,
        array $arguments,
        string $identity,
        int $timeoutSeconds,
    ): bool {
        try {
            $result = $this->host->exec($instance, new GuestCommand($arguments, $timeoutSeconds));

            if (! $result->successful()) {
                return false;
            }

            $evidence = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException|\RuntimeException) {
            return false;
        }

        return (
            is_array($evidence)
            && array_keys($evidence) === ['probe', 'passed', 'identity']
            && ($evidence['probe'] ?? null) === $name
            && ($evidence['passed'] ?? null) === true
            && ($evidence['identity'] ?? null) === $identity
        );
    }
}
