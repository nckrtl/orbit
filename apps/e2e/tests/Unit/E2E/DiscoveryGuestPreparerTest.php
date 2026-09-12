<?php

declare(strict_types=1);

use App\E2E\DiscoveryGuestPreparer;
use App\E2E\IncusHost;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Every guest command reaches the fake through the host batch helper or one
 * direct `incus exec`; a failure map names the batch label (or `environment`
 * for the direct gateway `.env` placement) that must fail.
 *
 * @param  array<string, int>  $failures
 * @param  list<array{labels:list<string>,instances:list<string>,argv:list<list<string>>}>  $batches
 * @param  list<array{instance:string,argv:list<string>}>  $execs
 */
function fakePreparerGuests(array $failures, array &$batches, array &$execs, array $outputs = []): void
{
    Process::fake(function (PendingProcess $process) use ($failures, &$batches, &$execs, $outputs) {
        $command = $process->command;
        if (($command[0] ?? null) === 'python3' && str_ends_with((string) $command[1], '/resources/host/exec-all.py')) {
            $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
            $batch = ['labels' => [], 'instances' => [], 'argv' => [], 'stdin' => []];
            $results = [];
            foreach ($payload['requests'] as $request) {
                $batch['labels'][] = $request['label'];
                $batch['instances'][] = $request['instance'];
                $batch['argv'][] = $request['argv'];
                $batch['stdin'][] = $request['stdin'];
                $results[] = [
                    'label' => $request['label'],
                    'stdout' => $outputs[$request['label']] ?? (in_array($request['label'], ['gateway', 'app-dev', 'app-prod'], true)
                        ? '2: enp5s0    inet 10.44.0.'.(10 + count($batch['labels']))."/24 scope global enp5s0\n"
                        : ($request['label'] === 'retarget-gateway'
                            ? '{"app-dev":"10.44.0.11:51820","app-prod":"10.44.0.11:51820"}' : '')),
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
                TopologyProfile::ROLES,
            ), JSON_THROW_ON_ERROR));
        }
        expect($command[3] ?? null)->toBe('exec');
        $execs[] = ['instance' => (string) $command[4], 'argv' => array_slice($command, 6)];

        return Process::result('', '', $failures['environment'] ?? 0);
    });
}

function preparerTarget(): TopologyTarget
{
    return featureTarget('TST-123');
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
                ['retarget-gateway'],
                ['retarget-vpn.app-dev', 'retarget-vpn.app-prod'],
                ['php-fpm.gateway', 'php-fpm.app-dev'],
            ])
            ->and($batches[1]['instances'])->toBe(['local:'.$target->instance('gateway')])
            ->and($batches[1]['argv'])->toBe([[
                ...GuestCommand::ORBIT_USER_PREFIX,
                'php', '/home/orbit/orbit/apps/e2e/resources/guest/retarget-gateway.php', '/home/orbit/.orbit/gateway.sqlite', '10.44.0.11', '10.44.0.12', '10.44.0.13',
            ]])
            ->and($batches[1]['stdin'])->toBe([null])
            ->and($batches[2]['instances'])
            ->toBe(['local:'.$target->instance('app-dev'), 'local:'.$target->instance('app-prod')])
            ->and($batches[2]['argv'])
            ->each->toBe(['bash', '-s', '--', '10.44.0.11', '10.44.0.11:51820'])
            ->and($batches[2]['stdin'])->each->toBe(file_get_contents(dirname(__DIR__, 3).'/resources/guest/retarget-vpn.sh'))
            ->and($batches[3]['instances'])->toBe([
                'local:'.$target->instance('gateway'),
                'local:'.$target->instance('app-dev'),
            ])->and($batches[3]['argv'])
            ->each->toBe(['systemctl', 'restart', 'php8.5-fpm'])->and($execs)->toBe([]);
    });

    it('repairs only the snapshot clones in an extended topology', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs);
        $target = featureTarget('TST-123', recipe: TopologyRecipe::extendedAppProd());

        new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity($target);

        expect(array_column($batches, 'labels'))
            ->toBe([
                ['gateway', 'app-dev', 'app-prod'],
                ['retarget-gateway'],
                ['retarget-vpn.app-dev', 'retarget-vpn.app-prod'],
                ['php-fpm.gateway', 'php-fpm.app-dev'],
            ])
            ->and(collect($batches)->flatMap(fn (array $batch): array => $batch['instances'])->all())
            ->not->toContain('local:'.$target->instance('app-prod-2'));
    });

    it('stops before the PHP-FPM restart when retargeting fails', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['retarget-vpn.app-prod' => 1], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'WireGuard retargeting failed on retarget-vpn.app-prod.')
            ->and(array_column($batches, 'labels'))
            ->toHaveCount(3);
    });

    it('reports a failed PHP-FPM restart by role', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['php-fpm.gateway' => 1], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'PHP-FPM restart failed on php-fpm.gateway.');
    });

    it('stops before peer mutation when Gateway identity publication fails', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests(['retarget-gateway' => 65], $batches, $execs);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'Gateway clone identity preparation failed on retarget-gateway.')
            ->and(array_column($batches, 'labels'))->toBe([
                ['gateway', 'app-dev', 'app-prod'], ['retarget-gateway'],
            ]);
    });

    it('refuses an invalid Gateway result before peer mutation', function (string $output) {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs, ['retarget-gateway' => $output]);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'Gateway clone identity preparation returned')
            ->and($batches)->toHaveCount(2);
    })->with([
        'invalid JSON' => 'not JSON',
        'missing peer' => '{"app-dev":"10.44.0.11:51820"}',
        'unknown peer' => '{"app-dev":"10.44.0.11:51820","app-prod":"10.44.0.11:51820","extra":"10.44.0.11:51820"}',
        'snapshot endpoint' => '{"app-dev":"10.232.1.10:51820","app-prod":"10.44.0.11:51820"}',
        'invalid port' => '{"app-dev":"10.44.0.11:65536","app-prod":"10.44.0.11:51820"}',
        'wrong type' => '{"app-dev":null,"app-prod":"10.44.0.11:51820"}',
    ]);

    it('refuses duplicate clone addresses before publishing identity', function () {
        $batches = [];
        $execs = [];
        fakePreparerGuests([], $batches, $execs, ['app-prod' => "2: eth0 inet 10.44.0.11/24 scope global eth0\n"]);

        expect(fn () => new DiscoveryGuestPreparer(new IncusHost)->repairCloneIdentity(preparerTarget()))
            ->toThrow(RuntimeException::class, 'Cloned Nodes must have distinct global IPv4 addresses.')
            ->and($batches)->toHaveCount(1);
    });
});
