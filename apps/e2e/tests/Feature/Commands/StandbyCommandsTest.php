<?php

declare(strict_types=1);

use App\Console\Commands\Standby\FingerprintCommand;
use App\Console\Commands\Standby\RefreshCommand;
use App\Console\Commands\Standby\RestoreCommand;
use App\Console\Commands\Standby\StatusCommand;
use App\E2E\IncusHost;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\StandbyGeneration;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function promotedGenerationFixture(): StandbyGeneration
{
    return new StandbyGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
    );
}

function bindPromotedStandby(StandbyGeneration $generation): void
{
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-standby-command-'.bin2hex(random_bytes(8)));
    $store = new AtomicJsonStore($paths);
    $manifests = new StandbyManifestStore($store, $paths);
    $manifests->promote($generation);
    app()->instance(StandbyManifestStore::class, $manifests);
}

describe('standby commands', function () {
    it('resolves a separate stateful lock for each lifecycle owner', function () {
        expect(app(OperationLock::class))->not->toBe(app(OperationLock::class));
    });

    it('registers one thin command for each wrapper action', function () {
        expect([
            new StatusCommand()->getName(),
            new FingerprintCommand()->getName(),
            new RefreshCommand()->getName(),
            new RestoreCommand()->getName(),
        ])->toBe(['standby:status', 'standby:fingerprint', 'standby:refresh', 'standby:restore']);
    });

    it('rejects refresh without an exact main SHA', function () {
        $this
            ->artisan('standby:refresh', ['--json' => true])
            ->expectsOutputToContain('exact main SHA')
            ->assertFailed();
    });

    it('fails status when a promoted snapshot is missing', function () {
        bindPromotedStandby(promotedGenerationFixture());
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));

            if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-standby-', '', $instance);

                return Process::result(json_encode(
                    $role === 'app-prod'
                        ? []
                        : [[
                            'name' => 'main-'.$role,
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        ]],
                    JSON_THROW_ON_ERROR,
                ));
            }

            return Process::result(json_encode([[
                'name' => preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? 'orbit-e2e-standby-gateway')),
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'default']],
            ]], JSON_THROW_ON_ERROR));
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('standby:status', ['--json' => true])
            ->expectsOutputToContain('snapshot identity changed')
            ->assertFailed();
    });

    it('fails status when a promoted snapshot is not Orbit-owned', function () {
        bindPromotedStandby(promotedGenerationFixture());
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));

            if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-standby-', '', $instance);

                return Process::result(json_encode([[
                    'name' => 'main-'.$role,
                    'config' => ['user.orbit.e2e.owner' => $role === 'app-prod' ? 'foreign-owner' : 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result(json_encode([[
                'name' => preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? 'orbit-e2e-standby-gateway')),
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['root' => ['pool' => 'default']],
            ]], JSON_THROW_ON_ERROR));
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('standby:status', ['--json' => true])
            ->expectsOutputToContain('ownership metadata does not match')
            ->assertFailed();
    });
});
