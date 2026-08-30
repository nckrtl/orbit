<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
use App\Console\Commands\Topology\DiagnoseCommand;
use App\Console\Commands\Topology\ExecCommand;
use App\Console\Commands\Topology\ProveCommand;
use App\Console\Commands\Topology\ReapCommand;
use App\Console\Commands\Topology\ReleaseCommand;
use App\Console\Commands\Topology\StatusCommand;
use App\Console\Commands\Topology\SyncCommand;
use App\Console\Commands\Topology\VerifyCommand;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\VerificationReport;

describe('topology commands', function () {
    it('registers the complete thin topology command family', function () {
        expect([
            new AcquireCommand()->getName(),
            new SyncCommand()->getName(),
            new VerifyCommand()->getName(),
            new ExecCommand()->getName(),
            new ProveCommand()->getName(),
            new DiagnoseCommand()->getName(),
            new ReleaseCommand()->getName(),
            new StatusCommand()->getName(),
            new ReapCommand()->getName(),
        ])->toBe([
            'topology:acquire',
            'topology:sync',
            'topology:verify',
            'topology:exec',
            'topology:prove',
            'topology:diagnose',
            'topology:release',
            'topology:status',
            'topology:reap',
        ]);
    });

    it('scopes every attempt command by the exact issue and attempt', function () {
        $arguments = static fn (\Illuminate\Console\Command $command): array => array_keys(
            $command->getDefinition()->getArguments(),
        );

        expect($arguments(new AcquireCommand))
            ->toBe(['issue', 'worktree'])
            ->and($arguments(new SyncCommand))
            ->toBe(['issue', 'attempt', 'worktree'])
            ->and($arguments(new VerifyCommand))
            ->toBe(['issue', 'attempt'])
            ->and($arguments(new ExecCommand))
            ->toBe(['issue', 'attempt', 'role'])
            ->and(
                new ExecCommand()
                    ->getDefinition()
                    ->hasOption('argv-file'),
            )
            ->toBeTrue()
            ->and($arguments(new ProveCommand))
            ->toBe(['issue', 'worktree'])
            ->and(
                new ProveCommand()
                    ->getDefinition()
                    ->hasOption('candidate-sha'),
            )
            ->toBeTrue()
            ->and(
                new ProveCommand()
                    ->getDefinition()
                    ->hasOption('proof-plan-file'),
            )
            ->toBeTrue()
            ->and($arguments(new DiagnoseCommand))
            ->toBe(['issue', 'attempt'])
            ->and($arguments(new ReleaseCommand))
            ->toBe(['issue', 'attempt'])
            ->and($arguments(new StatusCommand))
            ->toBe(['issue', 'attempt'])
            ->and(
                new StatusCommand()
                    ->getDefinition()
                    ->getArgument('attempt')
                    ->isRequired(),
            )
            ->toBeFalse();

        foreach ([
            new AcquireCommand,
            new SyncCommand,
            new VerifyCommand,
            new ExecCommand,
            new ProveCommand,
            new DiagnoseCommand,
            new ReleaseCommand,
            new StatusCommand,
        ] as $command) {
            expect($command->getDefinition()->hasOption('json'))->toBeTrue();
        }
    });

    it('rejects unsafe command inputs before infrastructure access', function () {
        $this
            ->artisan('topology:exec', ['issue' => 'NCK-12', 'attempt' => attemptId()->value, 'role' => 'gateway'])
            ->expectsOutputToContain('argv JSON file')
            ->assertFailed();
        $this
            ->artisan('topology:verify', ['issue' => 'NCK-12', 'attempt' => 'not-an-attempt'])
            ->expectsOutputToContain('attempt ID is invalid')
            ->assertFailed();
        $this
            ->artisan('topology:release', ['issue' => 'NCK-12', 'attempt' => 'not-an-attempt'])
            ->expectsOutputToContain('attempt ID is invalid')
            ->assertFailed();
        $this
            ->artisan('topology:prove', ['issue' => 'NCK-12', 'worktree' => dirname(__DIR__, 3)])
            ->expectsOutputToContain('candidate SHA')
            ->assertFailed();
        $this
            ->artisan('topology:prove', [
                'issue' => 'NCK-12',
                'worktree' => dirname(__DIR__, 3),
                '--candidate-sha' => str_repeat('a', 40),
            ])
            ->expectsOutputToContain('proof plan file')
            ->assertFailed();
        $this
            ->artisan('topology:prove', [
                'issue' => 'NCK-12',
                'worktree' => dirname(__DIR__, 3),
                '--candidate-sha' => str_repeat('a', 40),
                '--proof-plan-file' => dirname(__DIR__, 3).'/missing-plan.json',
            ])
            ->expectsOutputToContain('proof plan file cannot be read')
            ->assertFailed();
        $this
            ->artisan('topology:diagnose', ['issue' => 'NCK-12', 'attempt' => 'not-an-attempt'])
            ->expectsOutputToContain('attempt ID is invalid')
            ->assertFailed();
        $this
            ->artisan('topology:reap')
            ->expectsOutputToContain('issue state snapshot')
            ->assertFailed();
    });

    it('accepts an inline argv vector and rejects it together with an argv file', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        commandStatePaths();
        $argvFile = temporaryFile('orbit-argv-');
        file_put_contents($argvFile, json_encode(['argv' => ['true'], 'stdin' => null], JSON_THROW_ON_ERROR));
        $exec = fn (array $options) => $this->artisan('topology:exec', [
            'issue' => 'NCK-12',
            'attempt' => attemptId()->value,
            'role' => 'gateway',
            '--json' => true,
            ...$options,
        ]);

        $exec(['--argv' => '["orbit","doctor","--json"]', '--argv-file' => $argvFile])
            ->expectsOutputToContain('Use either --argv or --argv-file, not both.')
            ->assertFailed();
        $exec(['--argv' => '{"argv":["orbit"]}'])
            ->expectsOutputToContain('The --argv value must be a non-empty JSON array of strings')
            ->assertFailed();
        $exec(['--argv' => '[]'])
            ->expectsOutputToContain('The --argv value must be a non-empty JSON array of strings')
            ->assertFailed();
        $exec(['--argv' => '["orbit",'])
            ->expectsOutputToContain('The --argv value must be a JSON array of strings')
            ->assertFailed();
        $exec(['--argv' => '["orbit",1]'])
            ->expectsOutputToContain('Every argv item must be a string.')
            ->assertFailed();
        // A valid inline vector or file passes validation and reaches the topology lookup, which finds no attempt.
        $exec(['--argv' => '["orbit","doctor","--json"]'])
            ->expectsOutputToContain('"state":"failed"')
            ->doesntExpectOutputToContain('argv')
            ->assertFailed();
        $exec(['--argv-file' => $argvFile])
            ->expectsOutputToContain('"state":"failed"')
            ->doesntExpectOutputToContain('argv')
            ->assertFailed();
    });

    it('returns a structured JSON failure envelope', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $this
            ->artisan('topology:exec', [
                'issue' => 'NCK-12',
                'attempt' => attemptId()->value,
                'role' => 'gateway',
                '--json' => true,
            ])
            ->expectsOutput(json_encode([
                'state' => 'failed',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'error' => 'An exact argv JSON array (--argv) or argv JSON file (--argv-file) is required.',
            ], JSON_THROW_ON_ERROR))
            ->assertFailed();
    });

    it('reports an absent topology without touching infrastructure', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $paths = commandStatePaths();

        $this
            ->artisan('topology:status', ['issue' => 'NCK-12', '--json' => true])
            ->expectsOutput(json_encode([
                'state' => 'absent',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'issue' => 'NCK-12',
                'attempt_id' => null,
            ], JSON_THROW_ON_ERROR))
            ->assertSuccessful();
        $this
            ->artisan('topology:status', ['issue' => 'NCK-12', 'attempt' => attemptId('b')->value, '--json' => true])
            ->expectsOutput(json_encode([
                'state' => 'absent',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'issue' => 'NCK-12',
                'attempt_id' => attemptId('b')->value,
            ], JSON_THROW_ON_ERROR))
            ->assertSuccessful();
        expect(glob($paths->root().'/*'))->toBe([]);
    });

    it('reports the active topology and the exact attempt record read-only', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $paths = commandStatePaths();
        $store = new AtomicJsonStore($paths);
        $topology = commandTopologyFixture('NCK-12');
        new TopologyManifestStore($store, $paths)->writeActive($topology);
        $before = commandStateListing($paths);

        $this
            ->artisan('topology:status', ['issue' => 'NCK-12', '--json' => true])
            ->expectsOutput(json_encode([
                'state' => 'discovery',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'issue' => 'NCK-12',
                'attempt_id' => attemptId()->value,
                'topology' => $topology->toArray(),
            ], JSON_THROW_ON_ERROR))
            ->assertSuccessful();
        $this
            ->artisan('topology:status', ['issue' => 'NCK-12', 'attempt' => attemptId()->value, '--json' => true])
            ->expectsOutput(json_encode([
                'state' => 'discovery',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'issue' => 'NCK-12',
                'attempt_id' => attemptId()->value,
                'topology' => $topology->toArray(),
            ], JSON_THROW_ON_ERROR))
            ->assertSuccessful();
        $this
            ->artisan('topology:status', ['issue' => 'NCK-12', 'attempt' => attemptId('b')->value, '--json' => true])
            ->expectsOutputToContain('"state":"absent"')
            ->assertSuccessful();
        $this
            ->artisan('topology:status', ['issue' => 'NCK-12'])
            ->expectsOutput('discovery '.attemptId()->value)
            ->assertSuccessful();

        expect(commandStateListing($paths))->toBe($before);
    });

    it('refuses to diagnose an attempt that is not the active proved attempt without touching infrastructure', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $paths = commandStatePaths();
        app()->forgetInstance(\App\E2E\ProofStore::class);
        app()->forgetInstance(\App\E2E\TopologyProofRunner::class);
        $store = new AtomicJsonStore($paths);
        new TopologyManifestStore($store, $paths)->writeActive(commandTopologyFixture('NCK-12'));
        $before = commandStateListing($paths);

        $this
            ->artisan('topology:diagnose', ['issue' => 'NCK-12', 'attempt' => attemptId('b')->value, '--json' => true])
            ->expectsOutput(json_encode([
                'state' => 'failed',
                'operation_id' => '0123456789abcdef0123456789abcdef',
                'error' => 'The attempt is not the active topology attempt.',
            ], JSON_THROW_ON_ERROR))
            ->assertFailed();
        $this
            ->artisan('topology:diagnose', ['issue' => 'NCK-12', 'attempt' => attemptId()->value, '--json' => true])
            ->expectsOutputToContain('not a proof attempt')
            ->assertFailed();

        // Only the issue lock the command took and released is new.
        expect(array_keys(array_diff_key(commandStateListing($paths), $before)))
            ->toBe([$paths->root().'/locks/topology-NCK-12.lock']);
    });

    it('binds one command operation identity from the environment', function () {
        config(['e2e.incus.operation_id' => '0123456789abcdef0123456789abcdef']);
        app()->forgetInstance(OperationId::class);
        $first = app(OperationId::class);
        $second = app(OperationId::class);

        expect($first->value)->toBe('0123456789abcdef0123456789abcdef')->and($second)->toBe($first);
    });

    it('generates one 32-character hex operation identity when absent', function () {
        config(['e2e.incus.operation_id' => null]);
        app()->forgetInstance(OperationId::class);
        $id = app(OperationId::class);

        expect($id->value)->toMatch('/\A[0-9a-f]{32}\z/');
    });
});

function commandStatePaths(): StatePaths
{
    $paths = new StatePaths(temporaryPath('orbit-command-state-', 8));
    app()->instance(StatePaths::class, $paths);
    app()->forgetInstance(AtomicJsonStore::class);
    app()->forgetInstance(TopologyManifestStore::class);

    return $paths;
}

/** @return array<string, string> */
function commandStateListing(StatePaths $paths): array
{
    $listing = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $paths->root(),
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $listing[$file->getPathname()] = hash_file('sha256', $file->getPathname()) ?: '';
        }
    }
    ksort($listing);

    return $listing;
}

function commandTopologyFixture(string $issue): FeatureTopology
{
    $target = featureTarget($issue);

    return new FeatureTopology(
        $target,
        AttemptPurpose::Discovery,
        new StandbyGeneration(
            str_repeat('a', 12).'-'.str_repeat('b', 12),
            str_repeat('a', 40),
            ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
            str_repeat('b', 64),
            str_repeat('c', 64),
            new LaravelRelease('v13.10.1', str_repeat('d', 40)),
            str_repeat('e', 64),
            1,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            TopologyProfile::NAME,
            TopologyProfile::ROLES,
            TopologyProfile::CHECKOUT_ROLES,
        ),
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState(str_repeat('a', 40), str_repeat('a', 40), mounted: true, pointerHash: str_repeat('f', 64)),
        new VerificationReport(true, ['fixture' => verificationProbeFixture()]),
        [
            'gateway' => ['device' => 'orbit-source', 'source' => '/srv/worktree', 'path' => '/home/orbit/orbit'],
            'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/worktree', 'path' => '/home/orbit/orbit'],
        ],
    );
}
