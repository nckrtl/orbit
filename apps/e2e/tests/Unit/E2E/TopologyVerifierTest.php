<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\TopologyVerifier;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

function topologyVerifierProbe(array $command): string
{
    $script = array_search('/usr/local/bin/verify-topology.sh', $command, true);
    $probe = $script === false ? null : $command[$script + 1] ?? null;

    if (! is_string($probe)) {
        throw new RuntimeException('The verifier command has no probe argument.');
    }

    return $probe;
}

describe('TopologyVerifier', function () {
    it('requires every named probe to return matching structured identity evidence', function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $sha = str_repeat('a', 40);
        $probeRoles = [
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
        $observedProbes = [];

        Process::fake(function (PendingProcess $process) use ($sha, $probeRoles, &$observedProbes) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];
                $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;

                return Process::result(json_encode([[
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]], JSON_THROW_ON_ERROR));
            }
            $probe = topologyVerifierProbe($command);
            assert(is_string($probe) && isset($probeRoles[$probe]), 'The verifier uses a declared probe.');
            $expected = [
                'incus',
                '--project',
                'default',
                'exec',
                'local:orbit-e2e-standby-'.$probeRoles[$probe],
                '--',
                '/usr/local/bin/verify-topology.sh',
                $probe,
                'readiness',
                $sha,
            ];
            if ($probe === 'wireguard.reachability') {
                $expected[] = 'orbit-e2e-standby-app-dev';
                $expected[] = 'orbit-e2e-standby-app-prod';
            }
            expect($command)->toBe($expected);
            $observedProbes[] = $probe;

            return Process::result(json_encode([
                'probe' => $probe,
                'passed' => true,
                'identity' => $sha,
            ], JSON_THROW_ON_ERROR));
        });

        $report = new TopologyVerifier(new IncusHost(pool: 'orbit-e2e'))->verify(
            TopologyTarget::standby(),
            VerificationMode::Readiness,
            new SourceState($sha, $sha),
        );

        expect($report->passed)
            ->toBeTrue()
            ->and($report->probes)
            ->toHaveKeys([
                'vm.gateway.running',
                'service.vpn',
                'wireguard.reachability',
                'https.gateway-internal',
                'laravel.prod',
                'source.manifest',
            ])
            ->and($observedProbes)
            ->toBe(array_keys($probeRoles));
    });

    it('fails closed on malformed probe evidence', function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            if (($process->command[array_key_last($process->command)] ?? null) === '--format=json') {
                return Process::result(json_encode([[
                    'name' => 'orbit-e2e-standby-gateway',
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result('{');
        });
        $sha = str_repeat('a', 40);

        $report = new TopologyVerifier(new IncusHost(pool: 'orbit-e2e'))->verify(
            TopologyTarget::standby(),
            VerificationMode::Proof,
            new SourceState($sha, $sha),
        );

        expect($report->passed)->toBeFalse();
        expect($commands)->toContain([
            'incus',
            '--project',
            'default',
            'exec',
            'local:orbit-e2e-standby-gateway',
            '--',
            '/usr/local/bin/verify-topology.sh',
            'vm.gateway.running',
            'proof',
            $sha,
        ]);
    });

    it('retries transient readiness failures within a bounded wait', function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $sha = str_repeat('a', 40);
        $wireGuardAttempts = 0;

        Process::fake(function (PendingProcess $process) use ($sha, &$wireGuardAttempts) {
            $command = $process->command;
            assert(is_array($command), 'Incus commands use argument arrays.');
            if (($command[count($command) - 1] ?? null) === '--format=json') {
                $target = $command[count($command) - 2];
                $name = str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;

                return Process::result(json_encode([[
                    'name' => $name,
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'orbit-e2e']],
                ]], JSON_THROW_ON_ERROR));
            }

            $probe = topologyVerifierProbe($command);
            if ($probe === 'wireguard.reachability' && ++$wireGuardAttempts === 1) {
                return Process::result(exitCode: 1);
            }

            return Process::result(json_encode([
                'probe' => $probe,
                'passed' => true,
                'identity' => $sha,
            ], JSON_THROW_ON_ERROR));
        });

        $report = new TopologyVerifier(
            new IncusHost(pool: 'orbit-e2e'),
            readinessTimeoutSeconds: 1,
            readinessPollIntervalMicroseconds: 0,
        )->verify(
            TopologyTarget::standby(),
            VerificationMode::Readiness,
            new SourceState($sha, $sha),
        );

        expect($report->passed)
            ->toBeTrue()
            ->and($wireGuardAttempts)
            ->toBe(2);
    });
});
