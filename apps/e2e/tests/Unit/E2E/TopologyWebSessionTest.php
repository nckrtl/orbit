<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\State\SecretRedactor;
use App\E2E\TopologyWebSession;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** Model accepted writes separately from the responses returned to the caller. */
function fakeWebHost(array &$commands, bool $published = false, string $failure = '', bool $present = true, ?array &$state = null): IncusHost
{
    $state ??= ['published' => $published, 'token' => $published ? str_repeat('a', 32) : null,
        'unit' => false, 'unit_token' => null, 'stop_attempts' => 0, 'cancelled' => [], 'fault' => null];
    Process::fake(function (PendingProcess $process) use (&$commands, &$state, $failure, $present) {
        $command = $process->command;
        assert(is_array($command));
        $commands[] = $command;
        if (($command[3] ?? '') === 'list') {
            return Process::result(json_encode($present ? [[
                'name' => 'operator', 'type' => 'container', 'status' => 'Running', 'status_code' => 103,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'default'], ...($state['published'] ? ['orbit-e2e-web' => ['type' => 'proxy', 'user.orbit.e2e.web-session' => $state['token']]] : [])],
            ]] : [], JSON_THROW_ON_ERROR));
        }
        if ($failure === 'occupied' && in_array('ss', $command, true)) {
            return Process::result('LISTEN 0 4096 0.0.0.0:5173');
        }
        if ($failure !== '' && in_array($failure, $command, true)) {
            return Process::result(errorOutput: 'injected failure', exitCode: 1);
        }
        if (in_array('add', $command, true)) {
            if ($state['fault'] === 'duplicate') {
                $state['published'] = true;
                $state['token'] = str_repeat('b', 32);
                $state['unit'] = true;
                $state['unit_token'] = $state['token'];

                return Process::result(errorOutput: 'device already exists', exitCode: 1);
            }
            $state['published'] = true;
            foreach ($command as $argument) {
                if (str_starts_with($argument, 'user.orbit.e2e.web-session=')) {
                    $state['token'] = substr($argument, strlen('user.orbit.e2e.web-session='));
                }
            }
            if ($state['fault'] === 'accepted-add') {
                $state['fault'] = null;
                throw new RuntimeException('lost publication response');
            }
        }
        if (in_array('/home/orbit/orbit/apps/e2e/resources/web-unit.sh', $command, true)) {
            $token = $command[array_key_last($command)];
            if (in_array('start', $command, true)) {
                if ($state['unit'] || isset($state['cancelled'][$token])) {
                    return Process::result(errorOutput: 'unit already exists or cancelled', exitCode: 1);
                }
                $state['unit'] = true;
                $state['unit_token'] = $token;
                if ($state['fault'] === 'accepted-start') {
                    $state['fault'] = null;
                    throw new RuntimeException('lost unit start response');
                }
            } else {
                $state['cancelled'][$token] = true;
                if ($state['unit'] && $state['unit_token'] === $token) {
                    $state['stop_attempts']++;
                    if ($state['fault'] === 'rejected-stop') {
                        $state['fault'] = null;

                        return Process::result(errorOutput: 'stop failed', exitCode: 1);
                    }
                    $state['unit'] = false;
                }
                if ($state['fault'] === 'accepted-stop') {
                    $state['fault'] = null;
                    throw new RuntimeException('lost stop response');
                }
            }
        }
        if (in_array('remove', $command, true)) {
            $state['published'] = false;
            $state['token'] = null;
            if ($state['fault'] === 'accepted-remove') {
                $state['fault'] = null;
                throw new RuntimeException('lost removal response');
            }
        }
        if (in_array('journalctl', $command, true)) {
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--since=')) {
                    // Real timestamp parsing: never accept an invented journal argument.
                    $journal = new Symfony\Component\Process\Process(['journalctl', '--no-pager', '--lines=0', $argument]);
                    $journal->run();
                    if (! $journal->isSuccessful()) {
                        return Process::result(errorOutput: $journal->getErrorOutput(), exitCode: $journal->getExitCode());
                    }
                }
            }

            return Process::result("vite ready\n-- cursor: cursor1\n");
        }

        return Process::result();
    });

    return new IncusHost('local', 'default', 'default', redactor: new SecretRedactor);
}

it('starts a clean-environment strict-port unit, publishes loopback, streams output and stops on interruption', function () {
    $commands = [];
    $host = fakeWebHost($commands);
    $ticks = 0;
    $lines = [];
    new TopologyWebSession($host)->run('operator', function () use (&$ticks): bool {
        return $ticks++ === 0;
    }, function (string $line) use (&$lines): void {
        $lines[] = $line;
    });

    $flat = array_map(static fn (array $command): string => implode(' ', $command), $commands);
    expect(implode("\n", $flat))->toContain('web-unit.sh start', 'web-unit.sh stop', 'config device remove local:operator orbit-e2e-web', '--since=-1min');
    $publication = current(array_filter($flat, static fn (string $line): bool => str_contains($line, 'device add')));
    expect($publication)->toMatch('/listen=tcp:127\.0\.0\.1:[1-9][0-9]+/')->toContain('connect=tcp:127.0.0.1:5173 bind=host');
    expect(implode("\n", $lines))->toContain('vite ready', 'Topology web: http://127.0.0.1:', 'ssh -N')->not->toContain('-- cursor:');
});

it('does not disturb an existing session', function () {
    $commands = [];
    $host = fakeWebHost($commands, published: true);
    expect(fn () => new TopologyWebSession($host)->run('operator', fn (): bool => false, fn (string $line) => null))
        ->toThrow(RuntimeException::class, 'already owns');
    expect(implode(' ', array_merge(...$commands)))->not->toContain('systemd-run', 'stop', 'remove');
});

it('cleans up a startup failure or a dead unit', function (string $failure) {
    $commands = [];
    $host = fakeWebHost($commands, failure: $failure);
    expect(fn () => new TopologyWebSession($host)->run('operator', fn (): bool => true, fn (string $line) => null))
        ->toThrow(RuntimeException::class);
    $text = implode(' ', array_merge(...$commands));
    expect($text)->toContain('device remove');
    expect($text)->toContain('web-unit.sh stop');
})->with(['start', 'is-active', 'journalctl', 'occupied']);

it('cleans up a publication when interrupted during reservation', function () {
    $commands = [];
    $host = fakeWebHost($commands);
    new TopologyWebSession($host)->run('operator', fn (): bool => false, fn (string $line) => null);
    expect(implode(' ', array_merge(...$commands)))->toContain('device remove');
});

it('allows cleanup after topology release deleted the operator', function () {
    $commands = [];
    fakeWebHost($commands, present: false)->stopWeb('operator');
    expect(implode(' ', array_merge(...$commands)))->not->toContain('exec', 'remove');
});

it('reconciles accepted writes with lost responses and retains the marker on unconfirmed cleanup', function (string $fault) {
    $commands = [];
    $state = null;
    $host = fakeWebHost($commands, state: $state);
    $state['fault'] = $fault;
    $ticks = 0;
    $run = fn () => new TopologyWebSession($host)->run('operator', function () use (&$ticks): bool {
        return $ticks++ === 0;
    }, fn (string $line) => null);
    expect($run)->toThrow(RuntimeException::class);
    if (in_array($fault, ['rejected-stop', 'accepted-stop'], true)) {
        expect($state['published'])->toBeTrue();
        expect($state['unit'])->toBe($fault === 'rejected-stop');
        $host->stopWeb('operator');
    }
    expect($state['published'])->toBeFalse();
    expect($state['unit'])->toBeFalse();
    expect($state['stop_attempts'])->toBe($fault === 'accepted-add' ? 0 : ($fault === 'rejected-stop' ? 2 : 1));
    // Retry also tolerates an accepted removal whose response was lost.
    $host->stopWeb('operator');
    expect($state['published'])->toBeFalse();
})->with(['accepted-add', 'accepted-start', 'rejected-stop', 'accepted-stop', 'accepted-remove']);

it('does not clean another token when a concurrent reservation wins', function () {
    $commands = [];
    $state = null;
    $host = fakeWebHost($commands, state: $state);
    $state['fault'] = 'duplicate';
    expect(fn () => new TopologyWebSession($host)->run('operator', fn (): bool => false, fn (string $line) => null))->toThrow(RuntimeException::class);
    expect($state['unit'])->toBeTrue();
    expect($state['published'])->toBeTrue();
    expect($state['stop_attempts'])->toBe(0);
    expect(implode(' ', array_merge(...$commands)))->not->toContain('web-unit.sh stop', 'device remove');
});

it('does not stop a pre-existing unit when its new reservation cannot start', function () {
    $commands = [];
    $state = null;
    $host = fakeWebHost($commands, state: $state);
    $state['unit'] = true;
    $state['unit_token'] = 'foreign';
    expect(fn () => new TopologyWebSession($host)->run('operator', fn (): bool => false, fn (string $line) => null))->toThrow(RuntimeException::class);
    expect($state['unit'])->toBeTrue();
    expect($state['published'])->toBeFalse();
    expect($state['stop_attempts'])->toBe(0);
});

it('retains a reservation whose ownership token cannot be established', function () {
    $commands = [];
    $state = null;
    $host = fakeWebHost($commands, published: true, state: $state);
    $state['token'] = null;
    expect(fn () => $host->stopWeb('operator'))->toThrow(RuntimeException::class, 'no ownership token');
    expect($state['published'])->toBeTrue();
    expect(implode(' ', array_merge(...$commands)))->not->toContain('web-unit.sh stop', 'device remove');
});

it('recovers a reservation whose owner crashed before or after starting the unit', function (bool $started) {
    $commands = [];
    $state = null;
    $host = fakeWebHost($commands, state: $state);
    $token = str_repeat('c', 32);
    $host->publishWeb('operator', 55173, $token);
    $state['unit'] = $started;
    $state['unit_token'] = $started ? $token : null;
    // Another command has no in-memory knowledge of the reservation or start.
    new IncusHost('local', 'default', 'default', redactor: new SecretRedactor)->stopWeb('operator');
    expect($state['published'])->toBeFalse();
    expect($state['unit'])->toBeFalse();
    expect($state['stop_attempts'])->toBe($started ? 1 : 0);
})->with([false, true]);

it('pins the guest launcher to its own Gateway and CA despite inherited live overrides', function () {
    $root = temporaryPath('orbit-web-launcher-', 6);
    mkdir($root.'/apps/web', 0700, true);
    mkdir($root.'/home', 0700, true);
    mkdir($root.'/bin', 0700, true);
    file_put_contents($root.'/home/e2e-gateway-root-ca.pem', 'topology-ca');
    file_put_contents($root.'/wireguard.conf', 'topology-peer');
    file_put_contents($root.'/home/config.json', json_encode(['gateways' => ['e2e' => ['url' => 'https://10.44.0.1']]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/bin/vp', "#!/bin/bash\nif [[ \$1 == dev ]]; then env; printf 'ARGV=%s\\n' \"\$*\"; fi\n");
    chmod($root.'/bin/vp', 0700);
    file_put_contents($root.'/bin/curl', "#!/bin/bash\nprintf 'CURL=%s\\n' \"\$*\" >&2\n");
    chmod($root.'/bin/curl', 0700);
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/web-session.sh');
    $script = str_replace(['/home/orbit/orbit', '/home/orbit/.orbit', '/etc/wireguard/orbit.conf'], [$root, $root.'/home', $root.'/wireguard.conf'], $source);
    file_put_contents($root.'/launch.sh', $script);
    $process = new Symfony\Component\Process\Process(['bash', $root.'/launch.sh'], env: [
        'PATH' => $root.'/bin:/usr/bin:/bin', 'ORBIT_GATEWAY_URL' => 'https://live.example',
        'ORBIT_CA_PATH' => '/live.pem', 'ORBIT_REALTIME_URL' => 'https://live.example',
        'ORBIT_GRAFANA_URL' => 'https://live.example', 'ORBIT_GATEWAY' => 'live', 'VITE_ORBIT_DEMO' => '1',
    ]);
    try {
        $process->mustRun();
        expect($process->getOutput())->toContain('ORBIT_GATEWAY_URL=https://10.44.0.1', 'ORBIT_CA_PATH='.$root.'/home/e2e-gateway-root-ca.pem', 'ARGV=dev --host 0.0.0.0 --port 5173 --strictPort')
            ->not->toContain('live.example', '/live.pem', 'ORBIT_REALTIME_URL=', 'ORBIT_GRAFANA_URL=', 'VITE_ORBIT_DEMO=');
        expect($process->getErrorOutput())->toContain('--cacert '.$root.'/home/e2e-gateway-root-ca.pem https://10.44.0.1/api/v1/nodes');
        file_put_contents($root.'/home/config.json', json_encode(['gateways' => ['e2e' => ['url' => 'https://live.example']]], JSON_THROW_ON_ERROR));
        $process->run();
        expect($process->getExitCode())->toBe(66);
        unlink($root.'/home/e2e-gateway-root-ca.pem');
        $process->run();
        expect($process->getExitCode())->toBe(66);
    } finally {
        new Symfony\Component\Process\Process(['rm', '-rf', $root])->mustRun();
    }
});
