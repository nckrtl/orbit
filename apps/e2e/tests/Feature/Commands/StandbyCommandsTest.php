<?php

declare(strict_types=1);

use App\Console\Commands\Standby\FingerprintCommand;
use App\Console\Commands\Standby\PromoteCommand;
use App\Console\Commands\Standby\RefreshCommand;
use App\Console\Commands\Standby\RestoreCommand;
use App\Console\Commands\Standby\StatusCommand;
use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\StandbyRefresher;
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
        str_repeat('e', 64),
        1,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );
}

function bindPromotedStandby(StandbyGeneration $generation): void
{
    $paths = new StatePaths(temporaryPath('orbit-standby-command-', 8));
    $store = new AtomicJsonStore($paths);
    $manifests = new StandbyManifestStore($store, $paths, new IncusHost);
    $manifests->promote($generation);
    app()->instance(StandbyManifestStore::class, $manifests);
}

describe('standby commands', function () {
    it('resolves a separate stateful lock for each lifecycle owner', function () {
        expect(app(OperationLock::class))->not->toBe(app(OperationLock::class));
    });

    it('resolves the production refresher with separate refresh and generation locks', function () {
        expect(app(StandbyRefresher::class))->toBeInstanceOf(StandbyRefresher::class);
    });

    it('registers one thin command for each wrapper action', function () {
        expect([
            new StatusCommand()->getName(),
            new FingerprintCommand()->getName(),
            new PromoteCommand()->getName(),
            new RefreshCommand()->getName(),
            new RestoreCommand()->getName(),
        ])->toBe(['standby:status', 'standby:fingerprint', 'standby:promote', 'standby:refresh', 'standby:restore']);
    });

    it('limits cold permission to initial standby construction', function () {
        $description = new RefreshCommand()
            ->getDefinition()
            ->getOption('allow-cold')
            ->getDescription();

        expect($description)->toBe('Permit initial construction from the generic base image');
    });

    it('refuses promote for an issue with no live attempt before touching Incus', function () {
        $worktree = temporaryPath('orbit-standby-promote-', 8);
        mkdir($worktree.'/proofs', 0700, true);
        file_put_contents($worktree.'/proofs/NCK-123.json', json_encode([
            'setup' => [],
            'acceptance' => [[
                'id' => 'doctor',
                'node' => 'app-dev',
                'argv' => ['orbit', 'doctor'],
                'timeout_seconds' => 60,
            ]],
        ], JSON_THROW_ON_ERROR));
        Process::fake();

        $this
            ->artisan('standby:promote', ['issue' => 'NCK-123', '--worktree' => $worktree, '--json' => true])
            ->expectsOutputToContain('NCK-123 has no active attempt.')
            ->assertFailed();
        Process::assertNothingRan();
    });

    it('rejects refresh without an exact main SHA', function () {
        $this
            ->artisan('standby:refresh', ['--json' => true])
            ->expectsOutputToContain('exact main SHA')
            ->assertFailed();
    });

    it('keeps the promoted Laravel pin when merged main is a structural no-op', function () {
        $repository = standbyCommandFingerprintRepository(false);

        try {
            $fingerprints = new PreparedStateFingerprint(
                new GitRepository($repository['path']),
                'resources/prepared-state.json',
            );
            $release = new LaravelRelease('v13.10.1', str_repeat('a', 40));
            $promotedFingerprint = $fingerprints->forCommit($repository['old'], $release);
            $structuralFingerprint = $fingerprints->forCommit($repository['old']);
            bindPromotedStandby(new StandbyGeneration(
                'g-'.str_repeat('a', 12),
                $repository['old'],
                ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
                $promotedFingerprint->value,
                str_repeat('d', 64),
                $release,
                $structuralFingerprint->value,
                $structuralFingerprint->manifest['schema'],
                $structuralFingerprint->manifest['cold_epoch'],
                $structuralFingerprint->manifest['base_image_alias'],
                $structuralFingerprint->manifest['topology']['profile'],
                $structuralFingerprint->manifest['topology']['roles'],
                $structuralFingerprint->manifest['topology']['checkout_roles'],
            ));
            app()->instance(PreparedStateFingerprint::class, $fingerprints);
            app()->instance(LaravelReleaseResolver::class, new LaravelReleaseResolver('/missing/laravel.git'));
            $expected = $fingerprints->forCommit($repository['new'], $release)->value;

            $this
                ->artisan('standby:fingerprint', ['--main-sha' => $repository['new']])
                ->expectsOutput($expected)
                ->assertSuccessful();
        } finally {
            removeStandbyCommandFingerprintRepository($repository['path']);
        }
    });

    it('resolves the latest Laravel pin only after merged main changes prepared structure', function () {
        $repository = standbyCommandFingerprintRepository(true);

        try {
            $fingerprints = new PreparedStateFingerprint(
                new GitRepository($repository['path']),
                'resources/prepared-state.json',
            );
            $promotedRelease = new LaravelRelease('v13.10.1', str_repeat('a', 40));
            $promotedFingerprint = $fingerprints->forCommit($repository['old'], $promotedRelease);
            $structuralFingerprint = $fingerprints->forCommit($repository['old']);
            bindPromotedStandby(new StandbyGeneration(
                'g-'.str_repeat('a', 12),
                $repository['old'],
                ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
                $promotedFingerprint->value,
                str_repeat('d', 64),
                $promotedRelease,
                $structuralFingerprint->value,
                $structuralFingerprint->manifest['schema'],
                $structuralFingerprint->manifest['cold_epoch'],
                $structuralFingerprint->manifest['base_image_alias'],
                $structuralFingerprint->manifest['topology']['profile'],
                $structuralFingerprint->manifest['topology']['roles'],
                $structuralFingerprint->manifest['topology']['checkout_roles'],
            ));
            app()->instance(PreparedStateFingerprint::class, $fingerprints);
            app()->instance(LaravelReleaseResolver::class, new LaravelReleaseResolver($repository['path']));
            $latestRelease = new LaravelRelease('v13.11.0', $repository['new']);
            $expected = $fingerprints->forCommit($repository['new'], $latestRelease)->value;

            $this
                ->artisan('standby:fingerprint', ['--main-sha' => $repository['new']])
                ->expectsOutput($expected)
                ->assertSuccessful();
        } finally {
            removeStandbyCommandFingerprintRepository($repository['path']);
        }
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

            return Process::result(standbyCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('standby:status', ['--json' => true])
            ->expectsOutputToContain('snapshots do not exist')
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

            return Process::result(standbyCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('standby:status', ['--json' => true])
            ->expectsOutputToContain('ownership metadata does not match')
            ->assertFailed();
    });

    it('reads standby instances and promoted snapshots in batches', function () {
        bindPromotedStandby(promotedGenerationFixture());
        $instanceInventories = 0;
        $snapshotInventories = 0;
        Process::fake(function (PendingProcess $process) use (&$instanceInventories, &$snapshotInventories) {
            $command = $process->command;
            assert(is_array($command));

            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
                $snapshotInventories++;
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-standby-', '', $instance);

                return Process::result(json_encode([[
                    'name' => 'main-'.$role,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            $instanceInventories++;

            return Process::result(standbyCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('standby:status', ['--json' => true])
            ->expectsOutputToContain('"state":"promoted"')
            ->assertSuccessful();

        expect($instanceInventories)
            ->toBe(2)
            ->and($snapshotInventories)
            ->toBe(3);
    });
});

/** @return array{path: string, old: string, new: string} */
function standbyCommandFingerprintRepository(bool $changePreparedInput): array
{
    $path = temporaryPath('orbit-standby-fingerprint-', 6);
    mkdir($path.'/contracts', 0700, true);
    mkdir($path.'/resources', 0700, true);
    file_put_contents($path.'/contracts/prepared.php', "prepared-v1\n");
    file_put_contents(
        $path.'/resources/prepared-state.json',
        json_encode(
            [
                'schema' => 1,
                'paths' => ['contracts/prepared.php'],
                'cold_epoch' => 'ubuntu-26.04-amd64-v1',
                'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
                'declared_epochs' => ['node_convergence' => 1],
                'topology' => [
                    'profile' => 'gateway_app-dev_app-prod',
                    'roles' => ['gateway', 'app-dev', 'app-prod'],
                    'checkout_roles' => ['gateway', 'app-dev'],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )
            ."\n",
    );
    standbyCommandGit($path, ['init', '--quiet']);
    standbyCommandGit($path, ['config', 'user.email', 'orbit@example.test']);
    standbyCommandGit($path, ['config', 'user.name', 'Orbit']);
    standbyCommandGit($path, ['add', '.']);
    standbyCommandGit($path, ['commit', '--quiet', '-m', 'old']);
    $old = standbyCommandGit($path, ['rev-parse', 'HEAD']);
    file_put_contents(
        $path.'/contracts/prepared.php',
        $changePreparedInput ? "prepared-v2\n" : "prepared-v1\n",
    );
    file_put_contents($path.'/unrelated.txt', "new merged source\n");
    standbyCommandGit($path, ['add', '.']);
    standbyCommandGit($path, ['commit', '--quiet', '-m', 'new']);
    $new = standbyCommandGit($path, ['rev-parse', 'HEAD']);
    standbyCommandGit($path, ['tag', 'v13.11.0', $new]);

    return ['path' => $path, 'old' => $old, 'new' => $new];
}

/** @param list<string> $arguments */
function standbyCommandGit(string $path, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $path, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Standby command Git fixture failed.');
    }

    return trim(implode("\n", $output));
}

function removeStandbyCommandFingerprintRepository(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function standbyCommandInstanceInventory(): string
{
    return json_encode(array_map(
        static fn (string $role): array => [
            'name' => 'orbit-e2e-standby-'.$role,
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => ['root' => ['pool' => 'default']],
        ],
        ['gateway', 'app-dev', 'app-prod'],
    ), JSON_THROW_ON_ERROR);
}
