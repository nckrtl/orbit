<?php

declare(strict_types=1);

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\IncusHost;
use App\E2E\Value\TopologyTarget;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(Tests\TestCase::class);

/**
 * Every guest command reaches the fake through the host batch helper or one
 * direct `incus exec`; a failure map names the batch label (or `environment`
 * for the direct gateway `.env` placement) that must fail.
 *
 * @param array<string, int> $failures
 * @param list<array{labels:list<string>,instances:list<string>,argv:list<list<string>>}> $batches
 * @param list<array{instance:string,argv:list<string>}> $execs
 */
function fakePreparerGuests(array $failures, array &$batches, array &$execs): void
{
    Process::fake(function (PendingProcess $process) use ($failures, &$batches, &$execs) {
        $command = $process->command;
        if (($command[0] ?? null) === 'python3' && str_ends_with((string) $command[1], '/resources/host/exec-all.py')) {
            $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
            $batch = ['labels' => [], 'instances' => [], 'argv' => []];
            $results = [];
            foreach ($payload['requests'] as $request) {
                $batch['labels'][] = $request['label'];
                $batch['instances'][] = $request['instance'];
                $batch['argv'][] = $request['argv'];
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => in_array($request['label'], ['gateway', 'app-dev', 'app-prod'], true)
                        ? '2: enp5s0    inet 10.44.0.'.(10 + count($batch['labels']))."/24 scope global enp5s0\n"
                        : '',
                    'stderr' => '',
                    'exit_code' => $failures[$request['label']] ?? 0,
                ];
            }
            $batches[] = $batch;

            return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'list') {
            // The direct exec validates instance ownership from the inventory first.
            return Process::result(json_encode(array_map(
                static fn (string $role): array => [
                    'name' => preparerTarget()->instance($role),
                    'type' => 'virtual-machine',
                    'status' => 'Running',
                    'status_code' => 103,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                    'devices' => ['root' => ['pool' => 'default']],
                ],
                \App\E2E\Value\TopologyProfile::ROLES,
            ), JSON_THROW_ON_ERROR));
        }
        expect($command[3] ?? null)->toBe('exec');
        $execs[] = ['instance' => (string) $command[4], 'argv' => array_slice($command, 6)];

        return Process::result('', '', $failures['environment'] ?? 0);
    });
}

function preparerTarget(): TopologyTarget
{
    return featureTarget('NCK-123');
}

describe('mount.source', function () {
    it('proves the mount on every checkout role without writing to a guest', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new DiscoveryGuestPreparer(new IncusHost)->assertSourceMounted($target);

        expect($batches)
            ->toHaveCount(1)
            ->and($batches[0]['labels'])
            ->toBe(['mountpoint.gateway', 'mountpoint.app-dev'])
            ->and($batches[0]['instances'])
            ->toBe(['local:'.$target->instance('gateway'), 'local:'.$target->instance('app-dev')])
            ->and($batches[0]['argv'])
            ->each
            ->toBe(['mountpoint', '-q', '--', '/home/orbit/orbit'])
            ->and($execs)
            ->toBe([]);
    });

    it('names every role whose mount is missing', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['mountpoint.gateway' => 32, 'mountpoint.app-dev' => 32], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->assertSourceMounted(preparerTarget()))
            ->toThrow(RuntimeException::class, 'The worktree is not mounted on mountpoint.gateway, mountpoint.app-dev.')
            ->and($execs)
            ->toBe([]);
    });

    it('places the preserved gateway environment into the mounted worktree only when absent', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new DiscoveryGuestPreparer(new IncusHost)->placeGatewayEnvironment($target);

        expect($batches)
            ->toBe([])
            ->and($execs)
            ->toHaveCount(1)
            ->and($execs[0]['instance'])
            ->toBe('local:'.$target->instance('gateway'))
            ->and($execs[0]['argv'])
            ->toBe([
                'sh',
                '-c',
                '[ -e "$1" ] || install -o 1000 -g 1000 -m 0600 -- "$2" "$1"',
                'orbit-e2e',
                '/home/orbit/orbit/apps/gateway/.env',
                '/var/lib/orbit-e2e/gateway.env',
            ]);
    });

    it('names the refresh remedy when the preserved gateway environment is absent', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['environment' => 1], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->placeGatewayEnvironment(preparerTarget()))
            ->toThrow(
                RuntimeException::class,
                'the promoted topology snapshot generation must be refreshed so it preserves /var/lib/orbit-e2e/gateway.env.',
            )
            ->and($execs)
            ->toHaveCount(1);
    });

    it('links the orbit CLI onto the PATH of every checkout role as root', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new DiscoveryGuestPreparer(new IncusHost)->exposeOrbitCli($target);

        expect($batches)
            ->toHaveCount(1)
            ->and($batches[0]['labels'])
            ->toBe(['orbit-cli.gateway', 'orbit-cli.app-dev'])
            ->and($batches[0]['instances'])
            ->toBe(['local:'.$target->instance('gateway'), 'local:'.$target->instance('app-dev')])
            ->and($batches[0]['argv'])
            ->each
            ->toBe(['ln', '-sfn', '/home/orbit/orbit/apps/cli/orbit', '/usr/local/bin/orbit'])
            ->and($execs)
            ->toBe([]);
    });

    it('names every role whose CLI link failed', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['orbit-cli.app-dev' => 1], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->exposeOrbitCli(preparerTarget()))
            ->toThrow(RuntimeException::class, 'The orbit CLI could not be linked onto the PATH on orbit-cli.app-dev.');
    });
});

describe('repair.identity', function () {
    it('retargets the nodes at the cloned gateway address, then restarts PHP-FPM on the checkout roles', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = preparerTarget();

        new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity($target);

        expect(array_column($batches, 'labels'))
            ->toBe([
                ['gateway', 'app-dev', 'app-prod'],
                ['retarget-vpn.app-dev', 'retarget-vpn.app-prod'],
                ['php-fpm.gateway', 'php-fpm.app-dev'],
            ])
            ->and($batches[1]['instances'])
            ->toBe(['local:'.$target->instance('app-dev'), 'local:'.$target->instance('app-prod')])
            ->and($batches[1]['argv'])
            ->each->toBe(['/usr/local/bin/retarget-vpn.sh', '10.44.0.11'])->and($batches[2]['instances'])->toBe([
                'local:'.$target->instance('gateway'),
                'local:'.$target->instance('app-dev'),
            ])->and($batches[2]['argv'])
            ->each->toBe(['systemctl', 'restart', 'php8.5-fpm'])->and($execs)->toBe([]);
    });

    it('stops before the PHP-FPM restart when retargeting fails', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['retarget-vpn.app-prod' => 1], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'WireGuard retargeting failed on retarget-vpn.app-prod.')
            ->and(array_column($batches, 'labels'))
            ->toHaveCount(2);
    });

    it('reports a failed PHP-FPM restart by role', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['php-fpm.gateway' => 1], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'PHP-FPM restart failed on php-fpm.gateway.');
    });
});
