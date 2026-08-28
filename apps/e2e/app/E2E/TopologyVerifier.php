<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use App\E2E\Value\VerificationReport;
use JsonException;

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
        private IncusHost $host,
    ) {}

    public function verify(TopologyTarget $target, VerificationMode $mode, SourceState $source): VerificationReport
    {
        $results = [];

        foreach (self::PROBES as $name => $role) {
            $results[$name] = $this->probe($target->instance($role), $name, $mode, $source);
        }

        return new VerificationReport(! in_array(false, $results, true), $results);
    }

    private function probe(string $instance, string $name, VerificationMode $mode, SourceState $source): bool
    {
        try {
            $result = $this->host->exec($instance, new GuestCommand([
                '/usr/local/bin/verify-topology.sh',
                $name,
                $mode->value,
                $source->guestSha,
            ], 30));

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
            && ($evidence['identity'] ?? null) === $source->guestSha
        );
    }
}
