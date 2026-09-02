<?php

declare(strict_types=1);

use App\Console\Commands\TopologySnapshot\FingerprintCommand;
use App\Console\Commands\TopologySnapshot\PromoteCommand;
use App\Console\Commands\TopologySnapshot\RebuildCommand;
use App\Console\Commands\TopologySnapshot\RecoverLegacyCommand;
use App\Console\Commands\TopologySnapshot\RefreshCommand;
use App\Console\Commands\TopologySnapshot\RestoreCommand;
use App\Console\Commands\TopologySnapshot\StatusCommand;
use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\LaravelReleaseResolver;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotRebuilder;
use App\E2E\TopologySnapshotRefresher;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

function promotedGenerationFixture(): TopologySnapshotGeneration
{
    return new TopologySnapshotGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        str_repeat('e', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );
}

function bindPromotedTopologySnapshot(TopologySnapshotGeneration $generation): void
{
    $paths = new StatePaths(temporaryPath('orbit-topology-snapshot-command-', 8));
    $store = new AtomicJsonStore($paths);
    $manifests = new TopologySnapshotManifestStore($store, $paths, new IncusHost);
    $manifests->promote($generation);
    app()->instance(TopologySnapshotManifestStore::class, $manifests);
}

/** @mago-expect lint:cyclomatic-complexity The command contract stays grouped by its public topology snapshot surface. */
describe('topology snapshot commands', function () {
    it('resolves a separate stateful lock for each lifecycle owner', function () {
        expect(app(OperationLock::class))->not->toBe(app(OperationLock::class));
    });

    it('resolves the production refresher with separate refresh and generation locks', function () {
        expect(app(TopologySnapshotRefresher::class))->toBeInstanceOf(TopologySnapshotRefresher::class);
    });

    it('registers one thin command for each wrapper action', function () {
        expect([
            new StatusCommand()->getName(),
            new FingerprintCommand()->getName(),
            new PromoteCommand()->getName(),
            new RefreshCommand()->getName(),
            new RestoreCommand()->getName(),
            new RebuildCommand()->getName(),
            new RecoverLegacyCommand()->getName(),
        ])->toBe([
            'topology-snapshot:status',
            'topology-snapshot:fingerprint',
            'topology-snapshot:promote',
            'topology-snapshot:refresh',
            'topology-snapshot:restore',
            'topology-snapshot:rebuild',
            'topology-snapshot:recover-legacy',
        ]);
    });

    it('limits cold permission to initial topology snapshot construction', function () {
        $description = new RefreshCommand()
            ->getDefinition()
            ->getOption('allow-cold')
            ->getDescription();

        expect($description)->toBe('Permit initial construction from the generic base image');
    });

    it('refuses promote for an issue with no retained proof attempt before touching Incus', function () {
        $worktree = temporaryPath('orbit-topology-snapshot-promote-', 8);
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
            ->artisan('topology-snapshot:promote', ['issue' => 'NCK-123', '--worktree' => $worktree, '--json' => true])
            ->expectsOutputToContain('NCK-123 has no active attempt.')
            ->assertFailed();
        Process::assertNothingRan();
    });

    it('rejects refresh without an exact main SHA', function () {
        $this
            ->artisan('topology-snapshot:refresh', ['--json' => true])
            ->expectsOutputToContain('exact main SHA')
            ->assertFailed();
    });

    it('keeps the promoted Laravel pin when merged main is a structural no-op', function () {
        $repository = topologySnapshotCommandFingerprintRepository(false);

        try {
            $fingerprints = new PreparedStateFingerprint(
                new GitRepository($repository['path']),
                'resources/prepared-state.json',
            );
            $release = new LaravelRelease('v13.10.1', str_repeat('a', 40));
            $promotedFingerprint = $fingerprints->forCommit($repository['old'], $release);
            $structuralFingerprint = $fingerprints->forCommit($repository['old']);
            bindPromotedTopologySnapshot(new TopologySnapshotGeneration(
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
                ->artisan('topology-snapshot:fingerprint', ['--main-sha' => $repository['new']])
                ->expectsOutput($expected)
                ->assertSuccessful();
        } finally {
            removeTopologySnapshotCommandFingerprintRepository($repository['path']);
        }
    });

    it('resolves the latest Laravel pin only after merged main changes prepared structure', function () {
        $repository = topologySnapshotCommandFingerprintRepository(true);

        try {
            $fingerprints = new PreparedStateFingerprint(
                new GitRepository($repository['path']),
                'resources/prepared-state.json',
            );
            $promotedRelease = new LaravelRelease('v13.10.1', str_repeat('a', 40));
            $promotedFingerprint = $fingerprints->forCommit($repository['old'], $promotedRelease);
            $structuralFingerprint = $fingerprints->forCommit($repository['old']);
            bindPromotedTopologySnapshot(new TopologySnapshotGeneration(
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
                ->artisan('topology-snapshot:fingerprint', ['--main-sha' => $repository['new']])
                ->expectsOutput($expected)
                ->assertSuccessful();
        } finally {
            removeTopologySnapshotCommandFingerprintRepository($repository['path']);
        }
    });

    it('resolves the rebuilder for the persistent topology snapshot', function () {
        expect(app(TopologySnapshotRebuilder::class))
            ->toBeInstanceOf(TopologySnapshotRebuilder::class)
            ->and(app(TopologySnapshotIdentity::class))
            ->toEqual(TopologySnapshotIdentity::primary());
    });

    it('always resolves the repository persistent topology snapshot', function () {
        app()->forgetInstance(TopologySnapshotIdentity::class);

        expect(app(TopologySnapshotIdentity::class))
            ->toEqual(TopologySnapshotIdentity::primary())
            ->and(app(TopologySnapshotIdentity::class)->instance('gateway'))
            ->toBe('orbit-e2e-topology-snapshot-gateway');
    });

    it('refuses a rebuild without the exact main SHA before touching Incus', function () {
        Process::fake();

        $this
            ->artisan('topology-snapshot:rebuild', ['--main-sha' => 'main'])
            ->expectsOutputToContain('The exact main SHA is required.')
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('refuses ordinary rebuild before mutation when an exact topology snapshot VM exists', function () {
        app()->instance(StatePaths::class, new StatePaths(temporaryPath('orbit-rebuild-command-', 8)));
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));

            if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
                return Process::result('[]');
            }

            return Process::result(json_encode([[
                'name' => 'orbit-e2e-topology-snapshot-gateway',
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => [],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        });

        $this
            ->artisan('topology-snapshot:rebuild', ['--main-sha' => str_repeat('a', 40), '--json' => true])
            ->expectsOutputToContain(
                'Topology snapshot resources are present: orbit-e2e-topology-snapshot-gateway. '
                .'Use bin/e2e-topology-snapshot recover-legacy',
            )
            ->assertFailed();

        Process::assertDidntRun(function (PendingProcess $process): bool {
            $command = $process->command;
            assert(is_array($command));

            return (
                in_array('delete', $command, true)
                || in_array('stop', $command, true)
                || ($command[0] ?? null) === 'python3'
            );
        });
    });

    it('refuses legacy recovery without the exact main SHA before touching Incus', function () {
        Process::fake();

        $this
            ->artisan('topology-snapshot:recover-legacy', ['--main-sha' => 'main', '--json' => true])
            ->expectsOutputToContain(
                '"error":"The exact main SHA is required.","recovery_evidence":null,'
                .'"recovery_phase":null,"next_action":"bin/e2e-topology-snapshot recover-legacy --main-sha=<sha>"',
            )
            ->assertFailed();

        Process::assertNothingRan();
    });

    it('reports a stale manifest as recoverable instead of corrupt', function () {
        bindPromotedTopologySnapshot(promotedGenerationFixture());
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));

            if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
                return Process::result('[]');
            }

            return Process::result(topologySnapshotCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('topology-snapshot:status', ['--json' => true])
            ->expectsOutputToContain('"state":"stale"')
            ->assertFailed();
        $this
            ->artisan('topology-snapshot:status', ['--json' => true])
            ->expectsOutputToContain('"recovery":"bin/e2e-topology-snapshot rebuild --main-sha=<sha>"')
            ->assertFailed();
    });

    it('fails status when a promoted snapshot is missing', function () {
        bindPromotedTopologySnapshot(promotedGenerationFixture());
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));

            if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-topology-snapshot-', '', $instance);

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

            return Process::result(topologySnapshotCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('topology-snapshot:status', ['--json' => true])
            ->expectsOutputToContain('snapshots do not exist')
            ->assertFailed();
    });

    it('fails status when a promoted snapshot is not Orbit-owned', function () {
        bindPromotedTopologySnapshot(promotedGenerationFixture());
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            assert(is_array($command));

            if (in_array('snapshot', $command, true) && in_array('list', $command, true)) {
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-topology-snapshot-', '', $instance);

                return Process::result(json_encode([[
                    'name' => 'main-'.$role,
                    'config' => ['user.orbit.e2e.owner' => $role === 'app-prod' ? 'foreign-owner' : 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            return Process::result(topologySnapshotCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        $this
            ->artisan('topology-snapshot:status', ['--json' => true])
            ->expectsOutputToContain('ownership metadata does not match')
            ->assertFailed();
    });

    it('reads topology snapshot instances and promoted snapshots in batches', function () {
        bindPromotedTopologySnapshot(promotedGenerationFixture());
        $instanceInventories = 0;
        $snapshotInventories = 0;
        Process::fake(function (PendingProcess $process) use (&$instanceInventories, &$snapshotInventories) {
            $command = $process->command;
            assert(is_array($command));

            if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
                $snapshotInventories++;
                $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
                $role = str_replace('orbit-e2e-topology-snapshot-', '', $instance);

                return Process::result(json_encode([[
                    'name' => 'main-'.$role,
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            $instanceInventories++;

            return Process::result(topologySnapshotCommandInstanceInventory());
        });
        app()->instance(IncusHost::class, new IncusHost);

        expect(Artisan::call('topology-snapshot:status', ['--json' => true]))
            ->toBe(0)
            ->and(Artisan::output())
            ->toContain('"state":"promoted"')
            ->not->toContain('topology_snapshot_namespace');

        expect($instanceInventories)
            ->toBe(2)
            ->and($snapshotInventories)
            ->toBe(3);
    });
});

/** @return array{path: string, old: string, new: string} */
function topologySnapshotCommandFingerprintRepository(bool $changePreparedInput): array
{
    $path = temporaryPath('orbit-topology-snapshot-fingerprint-', 6);
    mkdir($path.'/contracts', 0700, true);
    mkdir($path.'/resources', 0700, true);
    file_put_contents($path.'/contracts/prepared.php', "prepared-v1\n");
    file_put_contents(
        $path.'/resources/prepared-state.json',
        json_encode(
            [
                'schema' => 2,
                'paths' => ['contracts/prepared.php'],
                'cold_epoch' => 'ubuntu-26.04-amd64-v1',
                'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
                'declared_epochs' => ['node_convergence' => 1],
                'topology' => [
                    'profile' => 'gateway_app-dev_app-prod',
                    'roles' => ['gateway', 'app-dev', 'app-prod'],
                    'checkout_roles' => ['gateway', 'app-dev'],
                    'assignments' => [
                        'gateway' => ['gateway', 'vpn'],
                        'app-dev' => ['app-dev', 'metrics'],
                        'app-prod' => ['app-prod'],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )
            ."\n",
    );
    topologySnapshotCommandGit($path, ['init', '--quiet']);
    topologySnapshotCommandGit($path, ['config', 'user.email', 'orbit@example.test']);
    topologySnapshotCommandGit($path, ['config', 'user.name', 'Orbit']);
    topologySnapshotCommandGit($path, ['add', '.']);
    topologySnapshotCommandGit($path, ['commit', '--quiet', '-m', 'old']);
    $old = topologySnapshotCommandGit($path, ['rev-parse', 'HEAD']);
    file_put_contents(
        $path.'/contracts/prepared.php',
        $changePreparedInput ? "prepared-v2\n" : "prepared-v1\n",
    );
    file_put_contents($path.'/unrelated.txt', "new merged source\n");
    topologySnapshotCommandGit($path, ['add', '.']);
    topologySnapshotCommandGit($path, ['commit', '--quiet', '-m', 'new']);
    $new = topologySnapshotCommandGit($path, ['rev-parse', 'HEAD']);
    topologySnapshotCommandGit($path, ['tag', 'v13.11.0', $new]);

    return ['path' => $path, 'old' => $old, 'new' => $new];
}

/** @param list<string> $arguments */
function topologySnapshotCommandGit(string $path, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $path, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('TopologySnapshot command Git fixture failed.');
    }

    return trim(implode("\n", $output));
}

function removeTopologySnapshotCommandFingerprintRepository(string $path): void
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

function topologySnapshotCommandInstanceInventory(): string
{
    return json_encode(array_map(
        static fn (string $role): array => [
            'name' => 'orbit-e2e-topology-snapshot-'.$role,
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => ['root' => ['pool' => 'default']],
        ],
        ['gateway', 'app-dev', 'app-prod'],
    ), JSON_THROW_ON_ERROR);
}
