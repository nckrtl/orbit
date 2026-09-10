<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
use App\Console\Commands\Topology\CandidateCommand;
use App\Console\Commands\Topology\CaptureCommand;
use App\Console\Commands\Topology\CloseoutCommand;
use App\Console\Commands\Topology\EquivalenceCommand;
use App\Console\Commands\Topology\ExecCommand;
use App\Console\Commands\Topology\ProveCommand;
use App\Console\Commands\Topology\ReleaseCommand;
use App\Console\Commands\Topology\ReviewCommand;
use App\Console\Commands\Topology\ShellCommand;
use App\Console\Commands\Topology\StatusCommand;
use App\Console\Commands\Topology\SyncCommand;
use App\Console\Commands\Topology\VerifyCommand;
use App\E2E\DiscoveryGuestPreparer;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\IssueTopologyConstructor;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyConverger;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofCloseoutRecord;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\ProofReviewAction;
use App\E2E\Value\ProofReviewEvaluation;
use App\E2E\Value\ProofReviewRecord;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeLocator;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/** A primary checkout with one registered external issue worktree. */
function commandPrimaryFixture(string $issue = 'TST-12'): array
{
    $primary = temporaryPath('orbit-command-primary-', 6);
    $worktree = $primary.'-worktrees/'.strtolower($issue);
    mkdir($primary, 0700, true);
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'user.name', 'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['commit', '--allow-empty', '-m', 'fixture'],
        ['worktree', 'add', '-b', strtolower($issue).'-feature', $worktree],
    ] as $arguments) {
        new Symfony\Component\Process\Process(['git', ...$arguments], $primary)->mustRun();
    }
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
            new CaptureCommand()->getName(),
            new ReviewCommand()->getName(),
            new EquivalenceCommand()->getName(),
            new CandidateCommand()->getName(),
            new CloseoutCommand()->getName(),
            new StatusCommand()->getName(),
            new ReleaseCommand()->getName(),
        ])->toBe([
            'topology:acquire',
            'topology:shell',
            'topology:exec',
            'topology:sync',
            'topology:verify',
            'topology:prove',
            'topology:capture',
            'topology:review',
            'topology:equivalence',
            'topology:candidate',
            'topology:closeout',
            'topology:status',
            'topology:release',
        ]);
    });

    it('takes the issue and finds the worktree; only acquire names the worktree as an argument', function () {
        $arguments = static fn (Command $command): array => array_keys(
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
            ->and($arguments(new CaptureCommand))
            ->toBe(['issue'])
            ->and($arguments(new ReviewCommand))
            ->toBe(['issue'])
            ->and($arguments(new EquivalenceCommand))
            ->toBe(['issue'])
            ->and($arguments(new CandidateCommand))
            ->toBe(['issue'])
            ->and($arguments(new CloseoutCommand))
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
                new CaptureCommand()
                    ->getDefinition()
                    ->hasOption('plan'),
            )
            ->toBeTrue()
            ->and(
                new ReviewCommand()
                    ->getDefinition()
                    ->hasOption('complete'),
            )
            ->toBeTrue()
            ->and(
                new ReviewCommand()
                    ->getDefinition()
                    ->hasOption('result'),
            )
            ->toBeTrue()
            ->and(
                new ReviewCommand()
                    ->getDefinition()
                    ->hasOption('finding'),
            )
            ->toBeTrue()
            ->and(
                new CloseoutCommand()
                    ->getDefinition()
                    ->hasOption('candidate'),
            )
            ->toBeTrue()
            ->and(
                new CloseoutCommand()
                    ->getDefinition()
                    ->hasOption('artifact'),
            )
            ->toBeTrue()
            ->and(
                new CloseoutCommand()
                    ->getDefinition()
                    ->hasOption('merge'),
            )
            ->toBeTrue()
            ->and(
                new CloseoutCommand()
                    ->getDefinition()
                    ->hasOption('main-sha'),
            )
            ->toBeTrue()
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
            ->and(new ExecCommand()->getDefinition()->hasOption('review-action'))
            ->toBeTrue()
            ->and(new ExecCommand()->getDefinition()->hasOption('required'))
            ->toBeTrue()
            ->and(new ShellCommand()->getDefinition()->hasOption('review-action'))
            ->toBeTrue()
            ->and(new ShellCommand()->getDefinition()->hasOption('required'))
            ->toBeTrue()
            ->and(new ReleaseCommand()->getDefinition()->hasOption('replace'))
            ->toBeTrue()
            ->and(new ReleaseCommand()->getDefinition()->hasOption('abandon'))
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
            ->toBeTrue()
            ->and(
                new ReleaseCommand()
                    ->getDefinition()
                    ->hasOption('recover-extension'),
            )
            ->toBeTrue()
            ->and(
                new ReleaseCommand()
                    ->getDefinition()
                    ->hasOption('expected-attempt'),
            )
            ->toBeTrue();

        foreach ([
            new ShellCommand,
            new ExecCommand,
            new SyncCommand,
            new VerifyCommand,
            new ProveCommand,
            new CaptureCommand,
            new ReviewCommand,
            new EquivalenceCommand,
            new CandidateCommand,
            new CloseoutCommand,
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
            ->expectsOutputToContain('No registered worktree matches TST-13')
            ->assertFailed();

        new Symfony\Component\Process\Process(['git', 'worktree', 'add', '-b', 'tst-12-other', $primary.'-other'], $primary)->mustRun();
        $this
            ->artisan('topology:status', ['issue' => 'TST-12'])
            ->expectsOutputToContain('More than one worktree matches')
            ->assertFailed();
    });

    it('reports an absent attempt from the worktree state without touching Incus', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();

        $this->withoutMockingConsoleOutput()->artisan('topology:status', ['issue' => 'TST-12', '--json' => true]);

        expect(json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR))
            ->toBe([
                'state' => 'absent',
                'issue' => 'TST-12',
                'worktree' => $worktree,
                'proof' => null,
                'captured_topology' => null,
            ]);
    });

    it('reports captured evidence in both human and JSON status after release', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $topology = commandTopologyFixture('TST-12', attemptId());
        $capture = commandCapturedProofFixture($topology);
        $state->writeProof($capture->proof);
        $state->captureProof($capture);

        $this
            ->artisan('topology:status', ['issue' => 'TST-12'])
            ->expectsOutput('captured '.attemptId()->value)
            ->assertSuccessful();
        $this->withoutMockingConsoleOutput()->artisan('topology:status', [
            'issue' => 'TST-12',
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR);

        expect($output['state'])
            ->toBe('captured')
            ->and($output['capture']['fingerprint'])
            ->toBe($capture->fingerprint())
            ->and($output['retained_topology'])
            ->toBeNull();
    });

    it('reports active clean replacement recovery with retained evidence', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $topology = commandTopologyFixture('TST-12', attemptId());
        $capture = commandCapturedProofFixture($topology);
        $state->writeProof($capture->proof);
        $state->captureProof($capture);
        $paths = new StatePaths(temporaryPath('orbit-command-replacement-host-', 6));
        $store = new TopologySnapshotReplacementStore(new AtomicJsonStore($paths));
        $old = $topology->generation;
        $mainSha = str_repeat('7', 40);
        $new = new TopologySnapshotGeneration(
            'replacement-generation',
            $mainSha,
            array_fill_keys(TopologyProfile::ROLES, 'main-replacement-generation'),
            str_repeat('8', 64),
            $old->baseImageFingerprint,
            $old->laravel,
            str_repeat('9', 64),
            2,
            'ubuntu-26.04-amd64-v1',
            $old->baseImageAlias,
            TopologyProfile::NAME,
            TopologyProfile::ROLES,
            TopologyProfile::CHECKOUT_ROLES,
            $old->id,
            TopologyProfile::ASSIGNMENTS,
        );
        $replacementAttempt = new AttemptId(str_repeat('c', 32));
        $temporary = TopologyTarget::disposableCold(
            'TST-12',
            $replacementAttempt,
            TopologyRecipe::registered(),
        );
        $canonical = TopologyTarget::topologySnapshot(TopologySnapshotIdentity::primary());
        $temporaryInstances = [];
        $canonicalInstances = [];
        $nextInstances = [];
        $oldInstances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $temporaryInstances[$role] = $temporary->instance($role);
            $canonicalInstances[$role] = $canonical->instance($role);
            $nextInstances[$role] = $canonical->instance($role).'-next';
            $oldInstances[$role] = $canonical->instance($role).'-old';
        }
        $recovery = $store->start(new TopologySnapshotReplacementInstallation(
            'TST-12',
            $capture->attempt,
            $replacementAttempt,
            new OperationId(str_repeat('d', 32)),
            str_repeat('d', 40),
            str_repeat('e', 40),
            str_repeat('f', 40),
            $mainSha,
            $capture->fingerprint(),
            $capture->manifestSha256,
            $old,
            $new,
            $old->baseImageAlias,
            $old->baseImageFingerprint,
            $temporary->network(),
            $temporaryInstances,
            $canonicalInstances,
            $nextInstances,
            $oldInstances,
        ), '2026-09-10T10:05:00Z');
        app()->instance(TopologySnapshotReplacementStore::class, $store);

        $this->withoutMockingConsoleOutput()->artisan('topology:status', [
            'issue' => 'TST-12',
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);

        expect($output['state'])
            ->toBe('captured')
            ->and($output['snapshot_replacement']['installation']['issue'])
            ->toBe('TST-12')
            ->and($output['snapshot_replacement']['phase'])
            ->toBe($recovery->phase)
            ->and($output['snapshot_replacement']['installation']['new_generation']['id'])
            ->toBe('replacement-generation');
    });

    it('reports retained capture, review evaluation, and closeout state separately', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $topology = commandTopologyFixture('TST-12', attemptId());
        $capture = commandCapturedProofFixture($topology);
        $state->writeAttempt(attemptId(), AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
        $state->writeTopology($topology);
        $state->writeProof($capture->proof);
        $state->captureProof($capture);
        $action = ProofReviewAction::incomplete(
            'inspect-runtime',
            'exec',
            'app-dev',
            true,
            ['orbit', 'doctor'],
            null,
            '2026-09-10T10:01:00Z',
        )->complete('failed', 1, '', 'doctor failed', 'Fix required.', '2026-09-10T10:02:00Z');
        $review = ProofReviewRecord::empty(
            'TST-12',
            $capture->candidateSha,
            $capture->attempt,
            '2026-09-10T10:01:00Z',
        )->withAction($action, '2026-09-10T10:02:00Z');
        $state->writeReviewRecord($review);
        $state->writeReviewEvaluation(ProofReviewEvaluation::forRecord($review, '2026-09-10T10:03:00Z'));
        $state->writeCloseoutRecord(new ProofCloseoutRecord(
            'refresh-failed',
            'TST-12',
            $capture->attempt,
            $capture->candidateSha,
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
            null,
            'Snapshot refresh failed.',
            '2026-09-10T10:04:00Z',
        ));

        $this->withoutMockingConsoleOutput()->artisan('topology:status', [
            'issue' => 'TST-12',
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR);

        expect($output['state'])
            ->toBe('proof')
            ->and($output['capture']['attempt_id'])
            ->toBe(attemptId()->value)
            ->and($output['capture']['candidate_sha'])
            ->toBe($capture->candidateSha)
            ->and($output['capture']['fingerprint'])
            ->toBe($capture->fingerprint())
            ->and($output['retained_topology']['attempt_id'])
            ->toBe(attemptId()->value)
            ->and($output['review_record']['attempt_id'])
            ->toBe(attemptId()->value)
            ->and($output['review_record']['actions'][0]['id'])
            ->toBe('inspect-runtime')
            ->and($output['review_record']['actions'][0]['status'])
            ->toBe('failed')
            ->and($output['review_evaluation']['status'])
            ->toBe('blocked')
            ->and($output['review_evaluation']['required_failed'])
            ->toBe(['inspect-runtime'])
            ->and($output['closeout']['state'])
            ->toBe('refresh-failed')
            ->and($output['closeout']['attempt_id'])
            ->toBe(attemptId()->value);
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

    it('requires exact paired legacy recovery options before state or Incus mutation', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $state->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('b', 32)));
        $leasePath = $worktree.'/.e2e/'.IssueState::ATTEMPT;
        $lease = file_get_contents($leasePath);
        Process::fake();

        $this
            ->artisan('topology:release', [
                'issue' => 'TST-12',
                '--recover-extension' => 'app-prod',
            ])
            ->expectsOutputToContain('Use --recover-extension and --expected-attempt together.')
            ->assertFailed();
        $this
            ->artisan('topology:release', [
                'issue' => 'TST-12',
                '--recover-extension' => 'other',
                '--expected-attempt' => $attempt->value,
            ])
            ->expectsOutputToContain('must be none or app-prod')
            ->assertFailed();
        $this
            ->artisan('topology:release', [
                'issue' => 'TST-12',
                '--recover-extension' => 'none',
                '--expected-attempt' => 'short',
            ])
            ->expectsOutputToContain('attempt ID is invalid')
            ->assertFailed();

        expect(file_get_contents($leasePath))->toBe($lease);
        Process::assertNothingRan();
    });

    it('refuses sync and exec on a proved extended attempt before touching Incus', function () {
        ['primary' => $primary, 'worktree' => $worktree] = commandPrimaryFixture();
        Process::run(['git', '-C', $primary, 'worktree', 'remove', $worktree])->throw();
        Process::run(['git', '-C', $primary, 'branch', '-d', 'tst-12-feature'])->throw();
        file_put_contents($primary.'/README.md', "fixture\n");
        Process::run(['git', '-C', $primary, 'init', '--quiet', '-b', 'main'])->throw();
        Process::run(['git', '-C', $primary, 'config', 'user.email', 'orbit@example.test'])->throw();
        Process::run(['git', '-C', $primary, 'config', 'user.name', 'Orbit'])->throw();
        Process::run(['git', '-C', $primary, 'add', '.'])->throw();
        Process::run(['git', '-C', $primary, 'commit', '--quiet', '-m', 'fixture'])->throw();
        Process::run(['git', '-C', $primary, 'worktree', 'add', '--quiet', '-b', 'tst-12-feature', $worktree])->throw();
        $state = IssueState::forWorktree('TST-12', $worktree);
        $proof = new AttemptId(str_repeat('c', 32));
        $state->writeAttempt(
            $proof,
            AttemptPurpose::Proof,
            new OperationId(str_repeat('b', 32)),
            TopologyExtension::AppProd,
        );
        $state->writeProof(['status' => 'proved', 'attempt_id' => $proof->value]);
        $state->writeTopology(commandTopologyFixture(
            'TST-12',
            $proof,
            recipe: TopologyRecipe::extendedAppProd(),
        ));
        app()->instance(
            StatePaths::class,
            new StatePaths(temporaryPath('orbit-command-host-', 6)),
        );
        app()->instance(TopologyAcquirer::class, new TopologyAcquirer(
            app(IncusHost::class),
            app(IncusNetworkLifecycle::class),
            app(PreparedStateFingerprint::class),
            app(TopologySnapshotManifestStore::class),
            app(WorktreeSynchronizer::class),
            app(TopologyVerifier::class),
            app(DiscoveryGuestPreparer::class),
            app(HostCapacity::class),
            app(StatePaths::class),
            app(OperationId::class),
            app(TopologySnapshotIdentity::class),
            $primary,
            converger: app(TopologyConverger::class),
            constructor: app(IssueTopologyConstructor::class),
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

    it('executes captured successful proof through a redacted review action', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture();
        mkdir($worktree.'/.loop', 0700, true);
        file_put_contents($worktree.'/.loop/flow.json', "{\"schema\":1,\"flow\":\"proof\"}\n");
        $state = IssueState::forWorktree('TST-12', $worktree);
        $topology = commandTopologyFixture('TST-12', attemptId());
        $capture = commandCapturedProofFixture($topology);
        $state->writeAttempt(attemptId(), AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
        $state->writeTopology($topology);
        $state->writeProof($capture->proof);
        $state->captureProof($capture);
        $hostPaths = new StatePaths(temporaryPath('orbit-command-review-host-', 6));
        app()->instance(StatePaths::class, $hostPaths);
        new AtomicJsonStore($hostPaths)->write(
            'proof-evidence/TST-12/'.attemptId()->value.'.json',
            $capture->toArray(),
        );
        Process::fake(function (PendingProcess $process) use ($topology) {
            $command = $process->command;
            assert(is_array($command));
            if (($command[3] ?? null) === 'list') {
                return Process::result(json_encode([
                    commandInstanceFixture($topology, 'gateway'),
                ], JSON_THROW_ON_ERROR));
            }

            return Process::result("review-ok\n");
        });

        $this
            ->artisan('topology:exec', [
                'issue' => 'TST-12',
                'role' => 'gateway',
                '--argv' => '["printf","--token=secret-value"]',
                '--proof' => true,
                '--review-action' => 'inspect-gateway',
                '--required' => true,
            ])
            ->expectsOutput("review-ok\n")
            ->assertSuccessful();
        $this
            ->artisan('topology:review', ['issue' => 'TST-12'])
            ->expectsOutput('review ready')
            ->assertSuccessful();

        expect($state->reviewRecord()?->action('inspect-gateway')?->status)
            ->toBe('passed')
            ->and($state->reviewRecord()?->action('inspect-gateway')?->argv)
            ->toBe(['printf', '--token=[REDACTED]'])
            ->and((string) file_get_contents($worktree.'/.e2e/log'))
            ->not->toContain('secret-value')
            ->and($state->capturedProof()?->fingerprint())
            ->toBe($capture->fingerprint());
    });

    it('executes on app-prod-2 and rejects an unrecorded physical Node key', function (): void {
        ['worktree' => $worktree] = commandPrimaryFixture('AUX-132');
        $state = IssueState::forWorktree('AUX-132', $worktree);
        $attempt = new AttemptId(str_repeat('a', 32));
        $topology = commandTopologyFixture(
            'AUX-132',
            $attempt,
            AttemptPurpose::Discovery,
            TopologyRecipe::extendedAppProd(),
        );
        $state->writeAttempt(
            $attempt,
            AttemptPurpose::Discovery,
            new OperationId(str_repeat('b', 32)),
            TopologyExtension::AppProd,
        );
        $state->writeTopology($topology);
        app()->instance(
            StatePaths::class,
            new StatePaths(temporaryPath('orbit-command-host-', 6)),
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

        $shellInstance = app(TopologyAcquirer::class)->instance(
            new TopologyRequest('AUX-132', $worktree),
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
            StatePaths::class,
            new StatePaths(temporaryPath('orbit-command-host-', 6)),
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
            StatePaths::class,
            new StatePaths(temporaryPath('orbit-command-host-', 6)),
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
    ?TopologyRecipe $recipe = null,
): FeatureTopology {
    $target = TopologyTarget::feature($issue, $attempt, $recipe);
    $generation = new TopologySnapshotGeneration(
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
    $construction = $recipe?->nodeKeys() === TopologyRecipe::extendedAppProd()->nodeKeys()
        ? TopologyConstructionInputs::create(
            $target,
            $generation,
            2,
            TopologyExtension::AppProd,
            str_repeat('f', 64),
        )
        : TopologyConstructionInputs::create($target, $generation, 2);

    return new FeatureTopology(
        $construction,
        $purpose,
        $generation,
        new SourceState(str_repeat('d', 40), str_repeat('d', 40)),
        new VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
    );
}

function commandCapturedProofFixture(FeatureTopology $topology): CapturedProof
{
    $candidateSha = $topology->source->hostSha;
    $planSha256 = str_repeat('1', 64);
    $manifest = new ProofInputManifest(
        3,
        $candidateSha,
        $topology->generation->mainSha,
        [],
        [],
        '.loop/proof/'.$topology->target->issue.'.json',
        [],
        $topology->construction,
        null,
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $proof = [
        'status' => 'proved',
        'issue' => $topology->target->issue,
        'attempt_id' => $topology->attempt->value,
        'candidate_sha' => $candidateSha,
        'plan_sha256' => $planSha256,
        'manifest_sha256' => $manifest->fingerprint(),
    ];

    return new CapturedProof(
        $topology->target->issue,
        $topology->attempt,
        $candidateSha,
        $planSha256,
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T10:00:00Z',
    );
}

/** @return array<string, mixed> */
function commandInstanceFixture(FeatureTopology $topology, string $role): array
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

it('refuses proof-only commands before transport without an explicit proof selection', function (
    string $command,
    bool $explicit,
): void {
    ['worktree' => $worktree] = commandPrimaryFixture();
    if ($explicit) {
        mkdir($worktree.'/.loop', 0700, true);
        file_put_contents($worktree.'/.loop/flow.json', '{"schema":1,"flow":"discovery"}');
    }
    Process::fake();

    $this
        ->artisan($command, ['issue' => 'TST-12', '--json' => true])
        ->expectsOutputToContain('discovery flow disables proof')
        ->assertFailed();

    Process::assertNothingRan();
})->with(['topology:prove', 'topology:equivalence', 'topology:candidate'])->with([false, true]);
