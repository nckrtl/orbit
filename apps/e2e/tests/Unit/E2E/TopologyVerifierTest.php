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

describe('TopologyVerifier', function () {
    it('requires every named probe to return matching structured identity evidence', function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $sha = str_repeat('a', 40);

        Process::fake(function (PendingProcess $process) use ($sha) {
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
            $probe = $command[count($command) - 3];

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
            ]);
    });

    it('fails closed on malformed probe evidence', function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Process::fake([
            '*--format=json' => Process::result(json_encode([[
                'name' => 'orbit-e2e-standby-gateway',
                'type' => 'virtual-machine',
                'status' => 'Running',
                'status_code' => 103,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR)),
            '*' => Process::result('{'),
        ]);
        $sha = str_repeat('a', 40);

        $report = new TopologyVerifier(new IncusHost(pool: 'orbit-e2e'))->verify(
            TopologyTarget::standby(),
            VerificationMode::Readiness,
            new SourceState($sha, $sha),
        );

        expect($report->passed)->toBeFalse();
    });
});
