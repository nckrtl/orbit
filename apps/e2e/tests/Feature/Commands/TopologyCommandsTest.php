<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
use App\Console\Commands\Topology\CandidateCommand;
use App\Console\Commands\Topology\EquivalenceCommand;
use App\Console\Commands\Topology\ExecCommand;
use App\Console\Commands\Topology\ProveCommand;
use App\Console\Commands\Topology\ReleaseCommand;
use App\Console\Commands\Topology\ShellCommand;
use App\Console\Commands\Topology\StatusCommand;
use App\Console\Commands\Topology\SyncCommand;
use App\Console\Commands\Topology\VerifyCommand;
use App\E2E\IssueState;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\WorktreeLocator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/** A primary checkout with one issue worktree, so the locator resolves without git. */
function commandPrimaryFixture(string $issue = 'TST-12'): array
{
    $primary = temporaryPath('orbit-command-primary-', 6);
    $worktree = $primary.'/.worktrees/'.strtolower($issue).'-feature';
    mkdir($worktree, 0700, true);
    app()->instance(WorktreeLocator::class, new WorktreeLocator($primary));

    return ['primary' => $primary, 'worktree' => realpath($worktree)];
}

describe('topology commands', function () {
    it('registers the issue-only command family', function () {
        expect([
            new AcquireCommand()->getName(),
            new ShellCommand()->getName(),
            new ExecCommand()->getName(),
            new SyncCommand()->getName(),
            new VerifyCommand()->getName(),
            new ProveCommand()->getName(),
            new EquivalenceCommand()->getName(),
            new CandidateCommand()->getName(),
            new StatusCommand()->getName(),
            new ReleaseCommand()->getName(),
        ])->toBe([
            'topology:acquire',
            'topology:shell',
            'topology:exec',
            'topology:sync',
            'topology:verify',
            'topology:prove',
            'topology:equivalence',
            'topology:candidate',
            'topology:status',
            'topology:release',
        ]);
    });

    it('takes the issue and finds the worktree; only acquire names the worktree as an argument', function () {
        $arguments = static fn (\Illuminate\Console\Command $command): array => array_keys(
            $command->getDefinition()->getArguments(),
        );

        expect($arguments(new AcquireCommand))
            ->toBe(['issue', 'worktree'])
            ->and($arguments(new ShellCommand))
            ->toBe(['issue', 'role'])
            ->and($arguments(new ExecCommand))
            ->toBe(['issue', 'role'])
            ->and($arguments(new SyncCommand))
            ->toBe(['issue'])
            ->and($arguments(new VerifyCommand))
            ->toBe(['issue'])
            ->and($arguments(new ProveCommand))
            ->toBe(['issue'])
            ->and($arguments(new EquivalenceCommand))
            ->toBe(['issue'])
            ->and($arguments(new CandidateCommand))
            ->toBe(['issue'])
            ->and($arguments(new StatusCommand))
            ->toBe(['issue'])
            ->and($arguments(new ReleaseCommand))
            ->toBe(['issue'])
            ->and(
                new ProveCommand()
                    ->getDefinition()
                    ->hasOption('plan'),
            )
            ->toBeTrue()
            ->and(
                new ProveCommand()
                    ->getDefinition()
                    ->hasOption('candidate-sha'),
            )
            ->toBeFalse()
            ->and(
                new ExecCommand()
                    ->getDefinition()
                    ->hasOption('argv-file'),
            )
            ->toBeTrue()
            ->and(
                new ShellCommand()
                    ->getDefinition()
                    ->hasOption('proof'),
            )
            ->toBeTrue()
            ->and(
                new ExecCommand()
                    ->getDefinition()
                    ->hasOption('proof'),
            )
            ->toBeTrue()
            ->and(
                new ReleaseCommand()
                    ->getDefinition()
                    ->hasOption('proof'),
            )
            ->toBeTrue()
            ->and(
                new ReleaseCommand()
                    ->getDefinition()
                    ->hasOption('candidate'),
            )
            ->toBeTrue();

        foreach ([
            new ShellCommand,
            new ExecCommand,
            new SyncCommand,
            new VerifyCommand,
            new ProveCommand,
            new EquivalenceCommand,
            new CandidateCommand,
            new StatusCommand,
            new ReleaseCommand,
        ] as $command) {
            expect($command->getDefinition()->hasOption('worktree'))
                ->toBeTrue()
                ->and($command->getDefinition()->hasOption('json'))
                ->toBeTrue();
        }
    });

    it('opens the shell with the exec environment and a login shell in the checkout', function () {
        expect(ShellCommand::shellArgv('/home/orbit/orbit'))->toBe([
            'runuser',
            '-u',
            'orbit',
            '--',
            'env',
            '-C',
            '/home/orbit/orbit',
            'HOME=/home/orbit',
            'ORBIT_HOME=/home/orbit/.orbit',
            'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
            'bash',
            '-l',
        ]);
    });

    it('rejects unsafe command inputs before infrastructure access', function () {
        $this
            ->artisan('topology:exec', ['issue' => 'TST-12', 'role' => 'gateway'])
            ->expectsOutputToContain('argv JSON file')
            ->assertFailed();
        $this
            ->artisan('topology:status', ['issue' => 'not-an-issue'])
            ->expectsOutputToContain('Linear issue ID is invalid')
            ->assertFailed();
        $this
            ->artisan('topology:acquire', ['issue' => 'TST-12', 'worktree' => 'relative/path'])
            ->expectsOutputToContain('absolute')
            ->assertFailed();
    });

    it('names a missing worktree and refuses two candidates', function () {
        ['primary' => $primary] = commandPrimaryFixture();

        $this
            ->artisan('topology:status', ['issue' => 'TST-13'])
            ->expectsOutputToContain('No worktree matches '.$primary.'/.worktrees/tst-13-*')
            ->assertFailed();

        mkdir($primary.'/.worktrees/tst-12-other', 0700, true);
        $this
            ->artisan('topology:status', ['issue' => 'TST-12'])
            ->expectsOutputToContain('More than one worktree matches')
            ->assertFailed();
    });

    it('reports an absent attempt from the worktree state without touching Incus', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();

        $this->withoutMockingConsoleOutput()->artisan('topology:status', ['issue' => 'TST-12', '--json' => true]);

        expect(json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR))
            ->toBe(['state' => 'absent', 'issue' => 'TST-12', 'worktree' => $worktree, 'proof' => null]);
    });

    it('reports the active proof attempt and its result from the worktree state', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $state->writeAttempt(attemptId(), AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
        $state->writeProof(['status' => 'proved', 'attempt_id' => attemptId()->value]);

        $this
            ->artisan('topology:status', ['issue' => 'TST-12'])
            ->expectsOutput('proof '.attemptId()->value.' proved')
            ->assertSuccessful();
        $this
            ->artisan('topology:status', ['issue' => 'TST-12', '--worktree' => $worktree, '--json' => true])
            ->expectsOutputToContain('"proved":true')
            ->assertSuccessful();
    });

    it('reports discovery and proof attempts together', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
        $state->writeProof(['status' => 'diagnosis', 'attempt_id' => $proof->value]);

        $this
            ->artisan('topology:status', ['issue' => 'TST-12'])
            ->expectsOutput("discovery {$discovery->value}; proof {$proof->value} diagnosis")
            ->assertSuccessful();
        $this->withoutMockingConsoleOutput()->artisan('topology:status', [
            'issue' => 'TST-12',
            '--worktree' => $worktree,
            '--json' => true,
        ]);

        expect(json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR))
            ->toMatchArray([
                'state' => 'discovery+proof',
                'issue' => 'TST-12',
                'attempt_id' => $discovery->value,
                'proof_attempt_id' => $proof->value,
                'proved' => false,
            ]);
    });

    it('reports candidate convergence beside retained proof and discovery', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture('AUX-7');
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $candidate = new AttemptId(str_repeat('c', 32));
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('d', 32)));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('e', 32)));
        $state->writeAttempt($candidate, AttemptPurpose::CandidateConvergence, new OperationId(str_repeat('f', 32)));

        $this
            ->artisan('topology:status', ['issue' => 'AUX-7'])
            ->expectsOutput('discovery+proof+candidate-convergence '.$candidate->value)
            ->assertSuccessful();
    });

    it('refuses selecting proof and candidate release together', function (): void {
        commandPrimaryFixture();

        $this
            ->artisan('topology:release', [
                'issue' => 'TST-12',
                '--proof' => true,
                '--candidate' => true,
            ])
            ->expectsOutputToContain('Select only one of --proof or --candidate.')
            ->assertFailed();
    });

    it('refuses sync and exec on a proved extended attempt before touching Incus', function () {
        ['primary' => $primary, 'worktree' => $worktree] = commandPrimaryFixture();
        rmdir($worktree);
        file_put_contents($primary.'/README.md', "fixture\n");
        Process::run(['git', '-C', $primary, 'init', '--quiet', '-b', 'main'])->throw();
        Process::run(['git', '-C', $primary, 'config', 'user.email', 'orbit@example.test'])->throw();
        Process::run(['git', '-C', $primary, 'config', 'user.name', 'Orbit'])->throw();
        Process::run(['git', '-C', $primary, 'add', '.'])->throw();
        Process::run(['git', '-C', $primary, 'commit', '--quiet', '-m', 'fixture'])->throw();
        Process::run(['git', '-C', $primary, 'worktree', 'add', '--quiet', '-b', 'tst-12-feature', $worktree])->throw();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $proof = new AttemptId(str_repeat('c', 32));
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
        $state->writeProof(['status' => 'proved', 'attempt_id' => $proof->value]);
        $state->writeTopology(commandTopologyFixture(
            'TST-12',
            $proof,
            recipe: \App\E2E\Value\TopologyRecipe::extendedAppProd(),
        ));
        app()->instance(
            \App\E2E\State\StatePaths::class,
            new \App\E2E\State\StatePaths(temporaryPath('orbit-command-host-', 6)),
        );
        app()->instance(\App\E2E\TopologyAcquirer::class, new \App\E2E\TopologyAcquirer(
            app(\App\E2E\IncusHost::class),
            app(\App\E2E\IncusNetworkLifecycle::class),
            app(\App\E2E\PreparedStateFingerprint::class),
            app(\App\E2E\TopologySnapshotManifestStore::class),
            app(\App\E2E\WorktreeSynchronizer::class),
            app(\App\E2E\TopologyVerifier::class),
            app(\App\E2E\DiscoveryGuestPreparer::class),
            app(\App\E2E\HostCapacity::class),
            app(\App\E2E\State\StatePaths::class),
            app(\App\E2E\Value\OperationId::class),
            app(\App\E2E\Value\TopologySnapshotIdentity::class),
            $primary,
            converger: app(\App\E2E\TopologyConverger::class),
            constructor: app(\App\E2E\IssueTopologyConstructor::class),
        ));
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = $process->command;
            assert(is_array($command));
            $commands[] = $command;
            if (($command[0] ?? null) === 'git') {
                $gitCommand = ['git', '-C', $process->path ?? getcwd(), ...array_slice($command, 1)];
                $output = [];
                $exitCode = 0;
                exec(implode(' ', array_map(escapeshellarg(...), $gitCommand)).' 2>&1', $output, $exitCode);

                return Process::result(implode("\n", $output), exitCode: $exitCode);
            }

            return Process::result();
        });

        $this
            ->artisan('topology:exec', [
                'issue' => 'TST-12',
                'role' => 'gateway',
                '--argv' => '["orbit","doctor"]',
                '--proof' => true,
            ])
            ->expectsOutputToContain('is proved; release it before changing it')
            ->assertFailed();
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('topology:sync', ['issue' => 'TST-12']);
        expect($exitCode)
            ->toBe(1)
            ->and(Artisan::output())
            ->toContain('has no active discovery attempt');

        expect(array_filter($commands, static fn (array $command): bool => ($command[0] ?? null) === 'incus'))
            ->toBe([]);
    });

    it('executes on app-prod-2 and rejects an unrecorded physical Node key', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture('AUX-132');
        $state = IssueState::forWorktree('AUX-132', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $topology = commandTopologyFixture(
            'AUX-132',
            $attempt,
            AttemptPurpose::Discovery,
            \App\E2E\Value\TopologyRecipe::extendedAppProd(),
        );
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('b', 32)));
        $state->writeTopology($topology);
        app()->instance(
            \App\E2E\State\StatePaths::class,
            new \App\E2E\State\StatePaths(temporaryPath('orbit-command-host-', 6)),
        );
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands, $topology) {
            $command = $process->command;
            assert(is_array($command));
            $commands[] = $command;

            if (($command[3] ?? null) === 'list') {
                return Process::result(json_encode([
                    commandInstanceFixture($topology, 'app-prod-2'),
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result("extra-node-ok\n");
        });

        $shellInstance = app(\App\E2E\TopologyAcquirer::class)->instance(
            new \App\E2E\Value\TopologyRequest('AUX-132', $worktree),
            'app-prod-2',
        );

        $this
            ->artisan('topology:exec', [
                'issue' => 'AUX-132',
                'role' => 'app-prod-2',
                '--argv' => '["php","-v"]',
            ])
            ->expectsOutput("extra-node-ok\n")
            ->assertSuccessful();
        $this
            ->artisan('topology:exec', [
                'issue' => 'AUX-132',
                'role' => 'app-prod-3',
                '--argv' => '["php","-v"]',
            ])
            ->expectsOutputToContain('Topology role [app-prod-3] must resolve to exactly one physical Node.')
            ->assertFailed();
        $this
            ->artisan('topology:shell', [
                'issue' => 'AUX-132',
                'role' => 'app-prod-3',
            ])
            ->expectsOutputToContain('Topology role [app-prod-3] must resolve to exactly one physical Node.')
            ->assertFailed();

        expect($shellInstance)
            ->toBe($topology->target->instance('app-prod-2'))
            ->and(array_values(array_filter(
                $commands,
                static fn (array $command): bool => ($command[3] ?? null) === 'exec',
            )))
            ->toBe([[
                'incus',
                '--project',
                'default',
                'exec',
                'local:'.$topology->target->instance('app-prod-2'),
                '--',
                ...GuestCommand::ORBIT_USER_PREFIX,
                'php',
                '-v',
            ]]);
    });

    it('executes on discovery by default and on a retained failed proof when selected', function () {
        ['worktree' => $worktree] = commandPrimaryFixture('AUX-7');
        $state = IssueState::forWorktree('AUX-7', $worktree);
        $discovery = new AttemptId(str_repeat('a', 32));
        $proof = new AttemptId(str_repeat('b', 32));
        $discoveryTopology = commandTopologyFixture('AUX-7', $discovery, AttemptPurpose::Discovery);
        $proofTopology = commandTopologyFixture('AUX-7', $proof);
        $state->writeAttempt($discovery, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        $state->writeTopology($discoveryTopology);
        $state->writeAttempt($proof, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
        $state->writeTopology($proofTopology);
        $state->writeProof(['status' => 'diagnosis', 'attempt_id' => $proof->value]);
        app()->instance(
            \App\E2E\State\StatePaths::class,
            new \App\E2E\State\StatePaths(temporaryPath('orbit-command-host-', 6)),
        );
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands, $discoveryTopology, $proofTopology) {
            $command = $process->command;
            assert(is_array($command));
            $commands[] = $command;

            if (($command[3] ?? null) === 'list') {
                $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));
                $topology = $name === $discoveryTopology->target->instance('gateway')
                    ? $discoveryTopology
                    : $proofTopology;

                return Process::result(json_encode([commandInstanceFixture(
                    $topology,
                    'gateway',
                )], JSON_THROW_ON_ERROR));
            }

            return Process::result("ok\n");
        });

        $this
            ->artisan('topology:exec', [
                'issue' => 'AUX-7',
                'role' => 'gateway',
                '--argv' => '["orbit","doctor"]',
            ])
            ->expectsOutput("ok\n")
            ->assertSuccessful();
        $this
            ->artisan('topology:exec', [
                'issue' => 'AUX-7',
                'role' => 'gateway',
                '--argv' => '["orbit","doctor"]',
                '--proof' => true,
            ])
            ->expectsOutput("ok\n")
            ->assertSuccessful();

        $execCommands = array_values(array_filter(
            $commands,
            static fn (array $command): bool => ($command[3] ?? null) === 'exec',
        ));
        expect($execCommands)
            ->toHaveCount(2)
            ->and($execCommands[0])
            ->toBe([
                'incus',
                '--project',
                'default',
                'exec',
                'local:'.$discoveryTopology->target->instance('gateway'),
                '--',
                ...GuestCommand::ORBIT_USER_PREFIX,
                'orbit',
                'doctor',
            ])
            ->and($execCommands[1])
            ->toBe([
                'incus',
                '--project',
                'default',
                'exec',
                'local:'.$proofTopology->target->instance('gateway'),
                '--',
                ...GuestCommand::ORBIT_USER_PREFIX,
                'orbit',
                'doctor',
            ]);
    });

    it('reads the attempt from the worktree and names an absent one', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        app()->instance(
            \App\E2E\State\StatePaths::class,
            new \App\E2E\State\StatePaths(temporaryPath('orbit-command-host-', 6)),
        );

        $this
            ->artisan('topology:release', ['issue' => 'TST-12', '--json' => true])
            ->expectsOutputToContain('TST-12 has no active attempt.')
            ->assertFailed();
        $this
            ->artisan('topology:verify', ['issue' => 'TST-12'])
            ->expectsOutputToContain('TST-12 has no active attempt.')
            ->assertFailed();
        expect(file_get_contents($worktree.'/.e2e/log'))
            ->toContain('topology:release failed: TST-12 has no active attempt.')
            ->toContain('topology:verify failed: TST-12 has no active attempt.');
    });
});

function commandTopologyFixture(
    string $issue,
    AttemptId $attempt,
    AttemptPurpose $purpose = AttemptPurpose::Proof,
    ?\App\E2E\Value\TopologyRecipe $recipe = null,
): \App\E2E\Value\FeatureTopology {
    $target = \App\E2E\Value\TopologyTarget::feature($issue, $attempt, $recipe);
    $generation = new \App\E2E\Value\TopologySnapshotGeneration(
        'g-'.str_repeat('a', 12),
        str_repeat('b', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new \App\E2E\Value\LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        str_repeat('e', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );

    return new \App\E2E\Value\FeatureTopology(
        $target,
        $purpose,
        $generation,
        $target->network(),
        array_combine(
            $target->recipe->nodeKeys(),
            array_map($target->instance(...), $target->recipe->nodeKeys()),
        ),
        new \App\E2E\Value\SourceState(str_repeat('d', 40), str_repeat('d', 40)),
        new \App\E2E\Value\VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
        construction: $recipe?->nodeKeys() === \App\E2E\Value\TopologyRecipe::extendedAppProd()->nodeKeys()
            ? \App\E2E\Value\TopologyConstructionInputs::create(
                $target,
                $generation,
                2,
                \App\E2E\Value\TopologyExtension::AppProd,
                str_repeat('f', 64),
            )
            : null,
    );
}

/** @return array<string, mixed> */
function commandInstanceFixture(\App\E2E\Value\FeatureTopology $topology, string $role): array
{
    return [
        'name' => $topology->target->instance($role),
        'type' => 'virtual-machine',
        'status' => 'Running',
        'status_code' => 103,
        'config' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.issue' => $topology->target->issue,
            'user.orbit.e2e.attempt' => $topology->attempt->value,
        ],
        'devices' => [
            'root' => ['pool' => 'orbit-e2e'],
            'eth0' => ['network' => $topology->network],
        ],
    ];
}
