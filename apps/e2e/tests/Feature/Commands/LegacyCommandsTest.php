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

/** @param array<string, mixed> $metadata */
function legacyCommandSync(array $metadata): \Illuminate\Contracts\Process\ProcessResult
{
    return Process::result(json_encode([
        'type' => 'sync',
        'status' => 'Success',
        'status_code' => 200,
        'metadata' => $metadata,
    ], JSON_THROW_ON_ERROR));
}

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

/** @return array{string, string, string} */
function observedHostTypeSubstitutionFixture(string $kind, string $expectedType): array
{
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
        'content_sha256' => str_repeat('a', 64),
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

    return [$root, $path, $observation];
}

function removeObservedHostTypeSubstitutionFixture(string $root, string $path, string $observation): void
{
    putenv('ORBIT_E2E_LEGACY_OBSERVATION');
    if (is_dir($path)) {
        rmdir($path);
    } else {
        unlink($path);
    }
    unlink($observation);
    rmdir($root);
}

/** @mago-expect lint:cyclomatic-complexity The command specification keeps one coherent retirement lifecycle. */
describe('legacy commands', function () {
    it('rejects file and directory substitution for every observed host path kind', function (
        string $kind,
        string $expectedType,
    ): void {
        [$root, $path, $observation] = observedHostTypeSubstitutionFixture($kind, $expectedType);

        expect(fn () => app(\App\E2E\LegacyRetirementHost::class)->observeCurrent())
            ->toThrow(RuntimeException::class, 'filesystem type');

        removeObservedHostTypeSubstitutionFixture($root, $path, $observation);
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
            'content_sha256' => str_repeat('a', 64),
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

    it('refuses changed reviewed host contents before deleting the resource', function (
        string $kind,
        string $message,
    ): void {
        $root = temporaryPath('legacy-content-digest-', 5);
        $safeRoot = $root.'/safe';
        mkdir($safeRoot, 0700, true);
        $target = $safeRoot.'/target';
        $initialContents = 'reviewed';
        if ($kind === 'source_paths') {
            mkdir($target, 0700);
            file_put_contents($target.'/payload.txt', $initialContents);
            $contentSha256 = hash('sha256', 'payload.txt:'.hash('sha256', $initialContents));
        } else {
            file_put_contents($target, $initialContents);
            $contentSha256 = hash('sha256', $initialContents);
        }
        $resource = [
            'path' => $target,
            'filesystem_type' => $kind === 'source_paths' ? 'directory' : 'file',
            'classification' => $kind === 'evidence' ? 'preserve' : 'legacy',
            'content_sha256' => $contentSha256,
        ];
        if ($kind === 'evidence') {
            $resource['identity'] = 'proof-1';
            $candidate = [
                'path' => $safeRoot.'/candidate',
                'safe_root' => $safeRoot,
                'filesystem_type' => 'directory',
                'classification' => 'legacy',
                'content_sha256' => hash('sha256', ''),
            ];
            mkdir($candidate['path'], 0700);
            $observed = ['source_paths' => [$candidate], 'evidence' => [$resource]];
        } else {
            $resource['safe_root'] = $safeRoot;
            $observed = [$kind => [$resource]];
        }
        $observation = $root.'/observation.json';
        $freezeEvidence = $root.'/freeze.json';
        file_put_contents($observation, json_encode($observed, JSON_THROW_ON_ERROR));
        file_put_contents($freezeEvidence, 'frozen');
        chmod($observation, 0600);
        chmod($freezeEvidence, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
        $host = app(\App\E2E\LegacyRetirementHost::class);
        $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-content-lock-', 5));
        $early = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            new \App\E2E\State\OperationLock($paths),
            new \App\E2E\Value\OperationId(str_repeat('d', 32)),
            $host->observeCurrent(...),
        );
        $inventory = $early->inventory();
        $manifest = $early->quarantine($inventory, $inventory->sha256(), $freezeEvidence);
        $changedContents = 'changed after review';
        if ($kind === 'source_paths') {
            file_put_contents($target.'/payload.txt', $changedContents);
            $resource['content_sha256'] = hash('sha256', 'payload.txt:'.hash('sha256', $changedContents));
        } else {
            file_put_contents($target, $changedContents);
            $resource['content_sha256'] = hash('sha256', $changedContents);
        }
        if ($kind === 'evidence') {
            $observed['evidence'] = [$resource];
        } else {
            $observed[$kind] = [$resource];
        }
        file_put_contents($observation, json_encode($observed, JSON_THROW_ON_ERROR));
        $later = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-05T10:00:00+00:00'),
            new \App\E2E\State\OperationLock($paths),
            new \App\E2E\Value\OperationId(str_repeat('e', 32)),
            $host->observeCurrent(...),
        );

        expect(fn () => $later->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class, $message);
        expect(file_exists($target))->toBeTrue();

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        if ($kind === 'source_paths') {
            unlink($target.'/payload.txt');
            rmdir($target);
        } else {
            unlink($target);
        }
        if ($kind === 'evidence') {
            rmdir($candidate['path']);
        }
        unlink($freezeEvidence);
        unlink($observation);
        rmdir($safeRoot);
        rmdir($root);
    })->with([
        'directory content' => ['source_paths', 'quarantined resource drifted'],
        'file content' => ['manifests', 'quarantined resource drifted'],
        'preserved evidence content' => ['evidence', 'preserved resource drifted'],
    ]);

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
                ['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm?project=orbit'] => legacyCommandSync([
                    'name' => 'old-vm',
                    'type' => 'virtual-machine',
                    'status' => 'Stopped',
                    'config' => ['owner' => 'old'],
                    'devices' => [],
                ]),
                ['incus', '--project', 'orbit', 'delete', 'lab:old-vm'] => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $retirement->delete($manifest, $manifest->sha256());

        expect($commands)->toBe([
            ['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm?project=orbit'],
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

            return legacyCommandSync([
                'name' => 'old-vm',
                'type' => 'virtual-machine',
                'status' => 'STOPPED',
                'config' => [],
                'devices' => [],
            ]);
        });

        app(\App\E2E\LegacyRetirementHost::class)->observeCurrent(['instances' => [$requested]]);

        expect($commands)->toBe([
            ['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm?project=orbit'],
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

            return legacyCommandSync([
                'name' => 'old-vm',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'config' => ['owner' => 'replacement'],
                'devices' => [],
            ]);
        });

        expect(fn () => $retirement->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class, 'metadata changed')
            ->and($commands)
            ->toBe([['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm?project=orbit']]);
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
                ['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm/snapshots/ready?project=orbit']
                    => legacyCommandSync([
                    'name' => 'ready',
                    'config' => ['owner' => 'old'],
                ]),
                ['incus', '--project', 'orbit', 'snapshot', 'delete', 'lab:old-vm', 'ready'] => Process::result(),
                default => Process::result('', 'Unexpected command.', 1),
            };
        });

        $retirement->delete($manifest, $manifest->sha256());

        expect($commands)->toBe([
            ['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm/snapshots/ready?project=orbit'],
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
            'version' => 3,
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
                    'error_code' => 404,
                    'error' => 'Resource not found',
                ], JSON_THROW_ON_ERROR),
                '',
                0,
            ),
        ]);

        $this
            ->artisan('legacy:verify', [
                '--retirement' => $retirement,
                '--json' => true,
            ])
            ->expectsOutputToContain('"successful":true')
            ->assertSuccessful();
        Process::assertRan(['incus', 'query', '--raw', 'lab:/1.0/instances/old-vm?project=orbit']);

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($retirement);
        unlink($observation);
        rmdir($root);
    });

    it('uses the production batch path for exact preserved references before deleting only the reviewed file', function () {
        $root = temporaryPath('legacy-preserved-delete-', 5);
        $safeRoot = $root.'/safe';
        mkdir($safeRoot, 0700, true);
        $candidate = $safeRoot.'/candidate.json';
        $observation = $root.'/observation.json';
        $freezeEvidence = $root.'/freeze.json';
        $fingerprint = str_repeat('d', 64);
        file_put_contents($candidate, 'reviewed candidate');
        file_put_contents($freezeEvidence, 'frozen');
        file_put_contents($observation, json_encode([
            'manifests' => [[
                'path' => $candidate,
                'safe_root' => $safeRoot,
                'filesystem_type' => 'file',
                'classification' => 'legacy',
                'content_sha256' => hash('sha256', 'reviewed candidate'),
            ]],
            'base_images' => [[
                'name' => 'ubuntu-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => $fingerprint,
                'classification' => 'preserve',
            ]],
            'pools' => [[
                'name' => 'orbit-e2e',
                'identity' => 'display-only-pool',
                'remote' => 'local',
                'project' => 'default',
                'classification' => 'preserve',
            ]],
        ], JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        chmod($freezeEvidence, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
        $host = app(\App\E2E\LegacyRetirementHost::class);
        $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-preserved-lock-', 5));
        $early = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            new \App\E2E\State\OperationLock($paths),
            new \App\E2E\Value\OperationId(str_repeat('a', 32)),
            $host->observeCurrent(...),
        );
        $inventory = $early->inventory();
        $manifest = $early->quarantine($inventory, $inventory->sha256(), $freezeEvidence);
        $commands = [];
        $realProcesses = new \Illuminate\Process\Factory;
        Process::fake(function (PendingProcess $process) use (&$commands, $fingerprint, $realProcesses) {
            $commands[] = $process->command;

            if (($process->command[0] ?? null) === 'python3') {
                return $realProcesses->newPendingProcess()->timeout(30)->run($process->command);
            }

            return (
                str_contains($process->command[3], '/storage-pools/')
                    ? legacyCommandSync(['name' => 'orbit-e2e', 'config' => ['source' => 'orbit-e2e']])
                    : legacyCommandSync(['fingerprint' => $fingerprint, 'aliases' => []])
            );
        });
        $later = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-05T10:00:00+00:00'),
            new \App\E2E\State\OperationLock($paths),
            new \App\E2E\Value\OperationId(str_repeat('b', 32)),
            $host->observeCurrent(...),
        );

        $result = $later->delete($manifest, $manifest->sha256());

        expect($result->successful)
            ->toBeTrue()
            ->and(file_exists($candidate))
            ->toBeFalse()
            ->and(array_slice($commands, 0, 2))
            ->toBe([
                ['incus', 'query', '--raw', 'local:/1.0/images/'.$fingerprint.'?project=default'],
                ['incus', 'query', '--raw', 'local:/1.0/storage-pools/orbit-e2e?project=default'],
            ])
            ->and($commands[2][0])
            ->toBe('python3');

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($freezeEvidence);
        unlink($observation);
        rmdir($safeRoot);
        rmdir($root);
    });

    it('refuses an appended duplicate preserved reference before any command mutation', function (
        string $kind,
        array $duplicate,
    ): void {
        $root = temporaryPath('legacy-preserved-duplicate-', 5);
        $safeRoot = $root.'/safe';
        mkdir($safeRoot, 0700, true);
        $candidate = $safeRoot.'/candidate.json';
        $observation = $root.'/observation.json';
        $freezeEvidence = $root.'/freeze.json';
        $quarantinePath = $root.'/quarantine.json';
        $fingerprint = str_repeat('d', 64);
        $candidateBytes = 'reviewed candidate';
        file_put_contents($candidate, $candidateBytes);
        file_put_contents($freezeEvidence, 'frozen');
        $observed = [
            'manifests' => [[
                'path' => $candidate,
                'safe_root' => $safeRoot,
                'filesystem_type' => 'file',
                'classification' => 'legacy',
                'content_sha256' => hash('sha256', $candidateBytes),
            ]],
            'base_images' => [[
                'name' => 'ubuntu-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => $fingerprint,
                'classification' => 'preserve',
            ]],
            'pools' => [[
                'name' => 'orbit-e2e',
                'identity' => 'display-only-pool',
                'remote' => 'local',
                'project' => 'default',
                'classification' => 'preserve',
            ]],
        ];
        file_put_contents($observation, json_encode($observed, JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        chmod($freezeEvidence, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
        $host = app(\App\E2E\LegacyRetirementHost::class);
        $early = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            new \App\E2E\State\OperationLock(
                new \App\E2E\State\StatePaths(temporaryPath('legacy-preserved-duplicate-lock-', 5)),
            ),
            new \App\E2E\Value\OperationId(str_repeat('e', 32)),
            $host->observeCurrent(...),
        );
        $inventory = $early->inventory();
        $manifest = $early->quarantine($inventory, $inventory->sha256(), $freezeEvidence);
        $early->write($quarantinePath, $manifest->toArray());
        $observed[$kind][] = $duplicate;
        file_put_contents($observation, json_encode($observed, JSON_THROW_ON_ERROR));
        Process::fake();

        $this
            ->artisan('legacy:delete', [
                '--quarantine' => $quarantinePath,
                '--ack-sha256' => $manifest->sha256(),
            ])
            ->expectsOutputToContain('frozen host observation contains a duplicate exact resource')
            ->assertFailed();

        expect(file_get_contents($candidate))
            ->toBe($candidateBytes)
            ->and(file_exists($quarantinePath.'.retirement.json'))
            ->toBeFalse();
        Process::assertNothingRan();

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        $journalPath = $quarantinePath.'.retirement.json.journal.json';
        if (is_file($journalPath)) {
            unlink($journalPath);
        }
        unlink($quarantinePath);
        unlink($candidate);
        unlink($freezeEvidence);
        unlink($observation);
        rmdir($safeRoot);
        rmdir($root);
    })->with([
        'pool exact key' => [
            'pools',
            [
                'name' => 'orbit-e2e',
                'identity' => 'appended-pool-display',
                'remote' => 'local',
                'project' => 'default',
                'classification' => 'preserve',
            ],
        ],
        'base image exact key' => [
            'base_images',
            [
                'name' => 'appended-image-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => str_repeat('d', 64),
                'classification' => 'preserve',
            ],
        ],
    ]);

    it('leaves a file candidate byte-identical when a preserved reference changes', function (
        string $kind,
        string $field,
        string $replacement,
    ): void {
        $root = temporaryPath('legacy-preserved-refusal-', 5);
        $safeRoot = $root.'/safe';
        mkdir($safeRoot, 0700, true);
        $candidate = $safeRoot.'/candidate.json';
        $observation = $root.'/observation.json';
        $freezeEvidence = $root.'/freeze.json';
        $fingerprint = str_repeat('d', 64);
        $observed = [
            'manifests' => [[
                'path' => $candidate,
                'safe_root' => $safeRoot,
                'filesystem_type' => 'file',
                'classification' => 'legacy',
                'content_sha256' => hash('sha256', 'reviewed candidate'),
            ]],
            'base_images' => [[
                'name' => 'ubuntu-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => $fingerprint,
                'classification' => 'preserve',
            ]],
            'pools' => [[
                'name' => 'orbit-e2e',
                'identity' => 'display-only-pool',
                'remote' => 'local',
                'project' => 'default',
                'classification' => 'preserve',
            ]],
        ];
        file_put_contents($candidate, 'reviewed candidate');
        file_put_contents($freezeEvidence, 'frozen');
        file_put_contents($observation, json_encode($observed, JSON_THROW_ON_ERROR));
        chmod($observation, 0600);
        chmod($freezeEvidence, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);
        $host = app(\App\E2E\LegacyRetirementHost::class);
        $paths = new \App\E2E\State\StatePaths(temporaryPath('legacy-preserved-refusal-lock-', 5));
        $early = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
            new \App\E2E\State\OperationLock($paths),
            new \App\E2E\Value\OperationId(str_repeat('c', 32)),
            $host->observeCurrent(...),
        );
        $inventory = $early->inventory();
        $manifest = $early->quarantine($inventory, $inventory->sha256(), $freezeEvidence);
        $observed[$kind][0][$field] = $replacement;
        file_put_contents($observation, json_encode($observed, JSON_THROW_ON_ERROR));
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands, $fingerprint) {
            $commands[] = $process->command;

            return (
                str_contains($process->command[3], '/storage-pools/')
                    ? legacyCommandSync(['name' => 'orbit-e2e', 'config' => []])
                    : legacyCommandSync(['fingerprint' => $fingerprint, 'aliases' => []])
            );
        });
        $later = new LegacyRetirement(
            $host->observe(...),
            $host->mutate(...),
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-05T10:00:00+00:00'),
            new \App\E2E\State\OperationLock($paths),
            new \App\E2E\Value\OperationId(str_repeat('d', 32)),
            $host->observeCurrent(...),
        );

        expect(fn () => $later->delete($manifest, $manifest->sha256()))
            ->toThrow(RuntimeException::class)
            ->and(file_get_contents($candidate))
            ->toBe('reviewed candidate');
        foreach ($commands as $command) {
            expect(array_slice($command, 0, 3))->toBe(['incus', 'query', '--raw']);
        }

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($candidate);
        unlink($freezeEvidence);
        unlink($observation);
        rmdir($safeRoot);
        rmdir($root);
    })->with([
        'remote' => ['pools', 'remote', 'replacement'],
        'project' => ['pools', 'project', 'replacement'],
        'pool API name' => ['pools', 'name', 'replacement'],
        'pool display identity' => ['pools', 'identity', 'redirect-attempt'],
        'image fingerprint' => ['base_images', 'fingerprint', str_repeat('e', 64)],
    ]);

    it('restarts schema 2 partial retirement through new schema 3 paths and a fresh retention period', function () {
        $root = temporaryPath('legacy-schema3-restart-', 5);
        mkdir($root, 0700);
        $observation = $root.'/observation.json';
        $freezeEvidence = $root.'/freeze.json';
        $schema2Inventory = $root.'/inventory-v2.json';
        $schema3Inventory = $root.'/inventory-v3.json';
        $fingerprint = str_repeat('d', 64);
        $oldBytes = "{\n  \"version\": 2,\n  \"audit\": \"preserve exactly\"\n}\n";
        file_put_contents($schema2Inventory, $oldBytes);
        file_put_contents($freezeEvidence, 'fresh freeze');
        file_put_contents($observation, json_encode([
            'instances' => [[
                'name' => 'already-stopped',
                'remote' => 'local',
                'project' => 'default',
                'status' => 'STOPPED',
                'metadata' => [],
                'dependencies' => [],
                'classification' => 'legacy',
            ]],
            'base_images' => [[
                'name' => 'ubuntu-display',
                'remote' => 'local',
                'project' => 'default',
                'fingerprint' => $fingerprint,
                'classification' => 'preserve',
            ]],
            'pools' => [[
                'name' => 'orbit-e2e',
                'identity' => 'pool-display',
                'remote' => 'local',
                'project' => 'default',
                'classification' => 'preserve',
            ]],
        ], JSON_THROW_ON_ERROR));
        chmod($schema2Inventory, 0600);
        chmod($freezeEvidence, 0600);
        chmod($observation, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);

        $this
            ->artisan('legacy:quarantine', [
                '--inventory' => $schema2Inventory,
                '--ack-sha256' => str_repeat('a', 64),
                '--freeze-evidence' => $freezeEvidence,
            ])
            ->assertFailed();
        $this->artisan('legacy:inventory', ['--output' => $schema3Inventory])->assertSuccessful();
        $fresh = \App\E2E\Value\RetirementInventory::fromArray(LegacyRetirement::readProtectedJson($schema3Inventory));
        $this
            ->artisan('legacy:quarantine', [
                '--inventory' => $schema3Inventory,
                '--ack-sha256' => $fresh->sha256(),
                '--freeze-evidence' => $freezeEvidence,
            ])
            ->assertSuccessful();
        $quarantinePath = $schema3Inventory.'.quarantine.json';
        $manifest = QuarantineManifest::fromArray(LegacyRetirement::readProtectedJson($quarantinePath));

        expect(file_get_contents($schema2Inventory))
            ->toBe($oldBytes)
            ->and(file_exists($schema2Inventory.'.quarantine.json'))
            ->toBeFalse()
            ->and($fresh->toArray()['version'])
            ->toBe(3)
            ->and($manifest->toArray()['version'])
            ->toBe(3)
            ->and($manifest->targets[0]['result'])
            ->toBe('unchanged')
            ->and(new DateTimeImmutable($manifest->deleteAfter))
            ->toEqual(new DateTimeImmutable($manifest->quarantinedAt)->modify('+7 days'))
            ->and(LegacyRetirement::readProtectedJson($quarantinePath.'.journal.json')['version'])
            ->toBe(3);

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($quarantinePath.'.journal.json');
        unlink($quarantinePath);
        unlink($schema3Inventory);
        unlink($schema2Inventory);
        unlink($freezeEvidence);
        unlink($observation);
        rmdir($root);
    });

    it('writes only a fresh reviewed inventory when no legacy candidate remains', function () {
        $root = temporaryPath('legacy-empty-restart-', 5);
        mkdir($root, 0700);
        $observation = $root.'/observation.json';
        $freezeEvidence = $root.'/freeze.json';
        $inventoryPath = $root.'/inventory-v3.json';
        file_put_contents($observation, json_encode([
            'pools' => [[
                'name' => 'orbit-e2e',
                'identity' => 'pool-display',
                'remote' => 'local',
                'project' => 'default',
                'classification' => 'preserve',
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($freezeEvidence, 'fresh freeze');
        chmod($observation, 0600);
        chmod($freezeEvidence, 0600);
        putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observation);

        $this->artisan('legacy:inventory', ['--output' => $inventoryPath])->assertSuccessful();
        $inventory = \App\E2E\Value\RetirementInventory::fromArray(
            LegacyRetirement::readProtectedJson($inventoryPath),
        );
        $this
            ->artisan('legacy:quarantine', [
                '--inventory' => $inventoryPath,
                '--ack-sha256' => $inventory->sha256(),
                '--freeze-evidence' => $freezeEvidence,
            ])
            ->assertFailed();

        expect($inventory->candidates)
            ->toBe([])
            ->and(file_exists($inventoryPath.'.quarantine.json'))
            ->toBeFalse()
            ->and(file_exists($inventoryPath.'.quarantine.json.journal.json'))
            ->toBeFalse();

        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        unlink($inventoryPath);
        unlink($freezeEvidence);
        unlink($observation);
        rmdir($root);
    });
});
