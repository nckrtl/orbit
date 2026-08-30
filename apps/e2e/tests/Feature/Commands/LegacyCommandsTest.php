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
    $root = temporaryPath('legacy-provider-delete-', 6);
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
    it('rejects file and directory substitution for every observed host path kind', function (
        string $kind,
        string $expectedType,
    ): void {
        $root = temporaryPath('legacy-provider-type-', 5);
        mkdir($root, 0700);
        $path = $root.'/target';
        if ($expectedType === 'directory') {
            file_put_contents($path, 'substituted file');
        } else {
            mkdir($path, 0700);
        }
        $resource = [
            'path' => $path,
            'filesystem_type' => $expectedType,
            'classification' => $kind === 'evidence' ? 'preserve' : 'legacy',
            'sha256' => str_repeat('a', 64),
        ];
        if ($kind !== 'evidence') {
            $resource['safe_root'] = $root;
        } else {
            $resource['identity'] = 'proof-1';
        }
        $observation = $root.'/observation.json';
        file_put_contents($observation, json_encode([$kind => [$resource]], JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);

        expect(fn () => app(\App\E2E\LegacyRetirementHost::class)->observeCurrent())
            ->toThrow(RuntimeException::class, 'filesystem type');

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        if (is_dir($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
        unlink($observation);
        rmdir($root);
    })->with([
        'source directory replaced by file' => ['source_paths', 'directory'],
        'manifest file replaced by directory' => ['manifests', 'file'],
        'lock file replaced by directory' => ['locks', 'file'],
        'evidence file replaced by directory' => ['evidence', 'file'],
    ]);

    it('rejects every parent and final symbolic link for observed host paths', function (
        string $kind,
        bool $finalLink,
    ): void {
        $root = temporaryPath('legacy-provider-link-', 5);
        mkdir($root.'/real', 0700, true);
        file_put_contents($root.'/real/target', 'protected');
        $path = $root.'/real/target';
        if ($finalLink) {
            symlink($path, $root.'/linked-target');
            $path = $root.'/linked-target';
        } else {
            symlink($root.'/real', $root.'/linked-parent');
            $path = $root.'/linked-parent/target';
        }
        $resource = [
            'path' => $path,
            'filesystem_type' => $kind === 'source_paths' ? 'directory' : 'file',
            'classification' => $kind === 'evidence' ? 'preserve' : 'legacy',
            'sha256' => str_repeat('a', 64),
        ];
        if ($kind !== 'evidence') {
            $resource['safe_root'] = $root;
        } else {
            $resource['identity'] = 'proof-1';
        }
        $observation = $root.'/observation.json';
        file_put_contents($observation, json_encode([$kind => [$resource]], JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);

        expect(fn () => app(\App\E2E\LegacyRetirementHost::class)->observeCurrent())
            ->toThrow(RuntimeException::class, 'symbolic link');

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($finalLink ? $root.'/linked-target' : $root.'/linked-parent');
        unlink($root.'/real/target');
        rmdir($root.'/real');
        unlink($observation);
        rmdir($root);
    })->with([
        'source parent link' => ['source_paths', false],
        'source final link' => ['source_paths', true],
        'manifest parent link' => ['manifests', false],
        'manifest final link' => ['manifests', true],
        'lock parent link' => ['locks', false],
        'lock final link' => ['locks', true],
        'evidence parent link' => ['evidence', false],
        'evidence final link' => ['evidence', true],
    ]);

    it('rejects a provider observation manifest beneath a symbolic-link parent', function () {
        $root = temporaryPath('legacy-provider-', 5);
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
        $root = temporaryPath('legacy-inputs-', 5);
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

    it('queries only the resources requested by one mutation barrier', function () {
        $root = temporaryPath('legacy-provider-batch-', 5);
        mkdir($root, 0700);
        $observation = $root.'/observation.json';
        $resource = static fn (string $name): array => [
            'name' => $name,
            'remote' => 'lab',
            'project' => 'orbit',
            'status' => 'STOPPED',
            'metadata' => [],
            'dependencies' => [],
            'classification' => 'legacy',
        ];
        $requested = $resource('old-vm');
        file_put_contents($observation, json_encode([
            'instances' => [$requested, $resource('unrelated-vm')],
        ], JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(json_encode([
                'name' => 'old-vm',
                'type' => 'virtual-machine',
                'status' => 'STOPPED',
                'config' => [],
                'devices' => [],
            ], JSON_THROW_ON_ERROR));
        });

        app(\App\E2E\LegacyRetirementHost::class)->observeCurrent(['instances' => [$requested]]);

        expect($commands)->toBe([
            ['incus', 'query', 'lab:/1.0/instances/old-vm?project=orbit'],
        ]);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($observation);
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

    it('verifies retirement against the current Incus host instead of the frozen observation', function () {
        $root = temporaryPath('legacy-provider-verify-', 5);
        mkdir($root, 0700);
        $observation = $root.'/observation.json';
        $retirement = $root.'/retirement.json';
        file_put_contents($observation, json_encode([
            'instances' => [[
                'name' => 'old-vm',
                'remote' => 'lab',
                'project' => 'orbit',
                'status' => 'STOPPED',
                'metadata' => ['owner' => 'old'],
                'dependencies' => [],
                'classification' => 'legacy',
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($retirement, json_encode([
            'version' => 1,
            'successful' => true,
            'quarantine_sha256' => str_repeat('a', 64),
            'deleted' => [[
                'kind' => 'instances',
                'identity' => 'old-vm',
                'result' => 'deleted',
            ]],
            'remaining' => [],
            'preserved' => [],
        ], JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        chmod($retirement, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
        Process::fake([
            '*' => Process::result(
                json_encode([
                    'type' => 'error',
                    'status_code' => 404,
                    'error' => 'Resource not found',
                ], JSON_THROW_ON_ERROR),
                '',
                1,
            ),
        ]);

        $this
            ->artisan('legacy:verify', [
                '--retirement' => $retirement,
                '--json' => true,
            ])
            ->expectsOutputToContain('"successful":true')
            ->assertSuccessful();
        Process::assertRan(['incus', 'query', 'lab:/1.0/instances/old-vm?project=orbit']);

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($retirement);
        unlink($observation);
        rmdir($root);
    });
});
