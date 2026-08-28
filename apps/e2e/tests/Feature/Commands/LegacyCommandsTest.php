<?php

declare(strict_types=1);

use App\Console\Commands\Legacy\DeleteCommand;
use App\Console\Commands\Legacy\InventoryCommand;
use App\Console\Commands\Legacy\QuarantineCommand;
use App\Console\Commands\Legacy\VerifyCommand;
use App\E2E\LegacyRetirement;
use App\E2E\Value\QuarantineManifest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/** @return array{LegacyRetirement, QuarantineManifest, string} */
function providerDeletionFixture(string $kind = 'instances', string $name = 'old-vm'): array
{
    $root = sys_get_temp_dir().'/legacy-provider-delete-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $observation = $root.'/observation.json';
    $evidence = $root.'/freeze.json';
    $resource = [
        'name' => $name,
        'metadata' => ['owner' => 'old'],
        'dependencies' => [],
        'classification' => 'legacy',
        'remote' => 'lab',
        'project' => 'orbit',
    ];
    if ($kind === 'instances') {
        $resource['status'] = 'STOPPED';
    }
    file_put_contents($observation, json_encode([$kind => [$resource]], JSON_THROW_ON_ERROR));
    file_put_contents($evidence, 'frozen');
    chmod($observation, 0600);
    chmod($evidence, 0600);
    putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
    $retirement = app(LegacyRetirement::class);
    $inventory = $retirement->inventory();
    $quarantined = $retirement->quarantine($inventory, $inventory->sha256(), $evidence);
    $manifest = new QuarantineManifest(
        $quarantined->inventorySha256,
        $quarantined->freezeEvidence,
        $quarantined->targets,
        $quarantined->preserved,
        '2026-08-01T00:00:00+00:00',
        '2026-08-08T00:00:00+00:00',
    );

    return [$retirement, $manifest, $root];
}

describe('legacy commands', function () {
    it('rejects a provider observation manifest beneath a symbolic-link parent', function () {
        $root = sys_get_temp_dir().'/legacy-provider-'.bin2hex(random_bytes(5));
        mkdir($root.'/real', 0700, true);
        file_put_contents($root.'/real/observation.json', '{}');
        chmod($root.'/real/observation.json', 0600);
        symlink($root.'/real', $root.'/escape');
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$root.'/escape/observation.json');

        $this
            ->artisan('legacy:inventory', ['--output' => $root.'/inventory.json'])
            ->expectsOutputToContain('symbolic-link component')
            ->assertFailed();

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($root.'/escape');
        unlink($root.'/real/observation.json');
        rmdir($root.'/real');
        rmdir($root);
    });

    it('rejects symlink-parent inventory, quarantine, and retirement command inputs', function () {
        $root = sys_get_temp_dir().'/legacy-inputs-'.bin2hex(random_bytes(5));
        mkdir($root.'/real', 0700, true);
        file_put_contents($root.'/real/state.json', '{}');
        chmod($root.'/real/state.json', 0600);
        symlink($root.'/real', $root.'/escape');
        $path = $root.'/escape/state.json';

        $this
            ->artisan('legacy:quarantine', [
                '--inventory' => $path,
                '--ack-sha256' => str_repeat('a', 64),
                '--freeze-evidence' => $root.'/real/state.json',
            ])
            ->expectsOutputToContain('symbolic-link component')
            ->assertFailed();
        $this
            ->artisan('legacy:delete', ['--quarantine' => $path, '--ack-sha256' => str_repeat('a', 64)])
            ->expectsOutputToContain('symbolic-link component')
            ->assertFailed();
        $this
            ->artisan('legacy:verify', ['--retirement' => $path])
            ->expectsOutputToContain('symbolic-link component')
            ->assertFailed();

        unlink($root.'/escape');
        unlink($root.'/real/state.json');
        rmdir($root.'/real');
        rmdir($root);
    });

    it('registers the exact command family', function () {
        expect([
            new InventoryCommand()->getName(),
            new QuarantineCommand()->getName(),
            new DeleteCommand()->getName(),
            new VerifyCommand()->getName(),
        ])
            ->toBe(['legacy:inventory', 'legacy:quarantine', 'legacy:delete', 'legacy:verify']);
    });

    it('rejects missing exact manifest inputs before infrastructure access', function () {
        $this->artisan('legacy:inventory')->expectsOutputToContain('absolute output path')->assertFailed();
        $this->artisan('legacy:quarantine')->expectsOutputToContain('--inventory')->assertFailed();
        $this->artisan('legacy:delete')->expectsOutputToContain('--quarantine')->assertFailed();
        $this->artisan('legacy:verify')->expectsOutputToContain('--retirement')->assertFailed();
    });

    it('reads the exact live resource before a provider deletion mutation', function () {
        [$retirement, $manifest, $root] = providerDeletionFixture();
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return match ($process->command) {
                ['incus', 'query', 'lab:/1.0/instances/old-vm?project=orbit'] => Process::result(json_encode([
                    'name' => 'old-vm',
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'config' => ['owner' => 'old'],
                    'devices' => [],
                ], JSON_THROW_ON_ERROR)),
                ['incus', '--project', 'orbit', 'delete', 'lab:old-vm'] => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $retirement->delete($manifest, $manifest->sha256());

        expect($commands)->toBe([
            ['incus', 'query', 'lab:/1.0/instances/old-vm?project=orbit'],
            ['incus', '--project', 'orbit', 'delete', 'lab:old-vm'],
        ]);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($root.'/observation.json');
        unlink($root.'/freeze.json');
        rmdir($root);
    });

    it('refuses provider deletion when the exact live resource drifted', function () {
        [$retirement, $manifest, $root] = providerDeletionFixture();
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(json_encode([
                'name' => 'old-vm',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'config' => ['owner' => 'replacement'],
                'devices' => [],
            ], JSON_THROW_ON_ERROR));
        });

        expect(fn () => $retirement->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class, 'metadata changed')
            ->and($commands)
            ->toBe([['incus', 'query', 'lab:/1.0/instances/old-vm?project=orbit']]);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($root.'/observation.json');
        unlink($root.'/freeze.json');
        rmdir($root);
    });

    it('revalidates and deletes a snapshot with Incus 6 query and operand syntax', function () {
        [$retirement, $manifest, $root] = providerDeletionFixture('snapshots', 'old-vm/ready');
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return match ($process->command) {
                ['incus', 'query', 'lab:/1.0/instances/old-vm/snapshots/ready?project=orbit']
                    => Process::result(json_encode([
                    'name' => 'ready',
                    'config' => ['owner' => 'old'],
                ], JSON_THROW_ON_ERROR)),
                ['incus', '--project', 'orbit', 'snapshot', 'delete', 'lab:old-vm', 'ready'] => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $retirement->delete($manifest, $manifest->sha256());

        expect($commands)->toBe([
            ['incus', 'query', 'lab:/1.0/instances/old-vm/snapshots/ready?project=orbit'],
            ['incus', '--project', 'orbit', 'snapshot', 'delete', 'lab:old-vm', 'ready'],
        ]);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($root.'/observation.json');
        unlink($root.'/freeze.json');
        rmdir($root);
    });
});
