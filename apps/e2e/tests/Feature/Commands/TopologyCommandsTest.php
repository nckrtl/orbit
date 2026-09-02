<?php

declare(strict_types=1);

use App\Console\Commands\Topology\AcquireCommand;
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
use App\E2E\Value\OperationId;
use App\E2E\WorktreeLocator;
use Illuminate\Support\Facades\Artisan;

/** A primary checkout with one worktree for NCK-12, so the locator resolves without git. */
function commandPrimaryFixture(): array
{
    $primary = temporaryPath('orbit-command-primary-', 6);
    $worktree = $primary.'/.worktrees/nck-12-feature';
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
            new StatusCommand()->getName(),
            new ReleaseCommand()->getName(),
        ])->toBe([
            'topology:acquire',
            'topology:shell',
            'topology:exec',
            'topology:sync',
            'topology:verify',
            'topology:prove',
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
            ->toBeTrue();

        foreach ([
            new ShellCommand,
            new ExecCommand,
            new SyncCommand,
            new VerifyCommand,
            new ProveCommand,
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
            ->artisan('topology:exec', ['issue' => 'NCK-12', 'role' => 'gateway'])
            ->expectsOutputToContain('argv JSON file')
            ->assertFailed();
        $this
            ->artisan('topology:status', ['issue' => 'not-an-issue'])
            ->expectsOutputToContain('Linear issue ID is invalid')
            ->assertFailed();
        $this
            ->artisan('topology:acquire', ['issue' => 'NCK-12', 'worktree' => 'relative/path'])
            ->expectsOutputToContain('absolute')
            ->assertFailed();
    });

    it('names a missing worktree and refuses two candidates', function () {
        ['primary' => $primary] = commandPrimaryFixture();

        $this
            ->artisan('topology:status', ['issue' => 'NCK-13'])
            ->expectsOutputToContain('No worktree matches '.$primary.'/.worktrees/nck-13-*')
            ->assertFailed();

        mkdir($primary.'/.worktrees/nck-12-other', 0700, true);
        $this
            ->artisan('topology:status', ['issue' => 'NCK-12'])
            ->expectsOutputToContain('More than one worktree matches')
            ->assertFailed();
    });

    it('reports an absent attempt from the worktree state without touching Incus', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();

        $this->withoutMockingConsoleOutput()->artisan('topology:status', ['issue' => 'NCK-12', '--json' => true]);

        expect(json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR))
            ->toBe(['state' => 'absent', 'issue' => 'NCK-12', 'worktree' => $worktree, 'proof' => null]);
    });

    it('reports the live attempt and its proof from the worktree state', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('NCK-12', $worktree);
        $state->writeAttempt(attemptId(), AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
        $state->writeProof(['status' => 'proved', 'attempt_id' => attemptId()->value]);

        $this
            ->artisan('topology:status', ['issue' => 'NCK-12'])
            ->expectsOutput('proof '.attemptId()->value.' proved')
            ->assertSuccessful();
        $this
            ->artisan('topology:status', ['issue' => 'NCK-12', '--worktree' => $worktree, '--json' => true])
            ->expectsOutputToContain('"proved":true')
            ->assertSuccessful();
    });

    it('refuses exec and sync on a proved attempt before touching Incus', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        $state = IssueState::forWorktree('NCK-12', $worktree);
        $attempt = new AttemptId(str_repeat('c', 32));
        $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('b', 32)));
        $state->writeProof(['status' => 'proved', 'attempt_id' => $attempt->value]);
        $state->writeTopology(commandTopologyFixture('NCK-12', $attempt));
        app()->instance(
            \App\E2E\State\StatePaths::class,
            new \App\E2E\State\StatePaths(temporaryPath('orbit-command-host-', 6)),
        );

        $this
            ->artisan('topology:exec', ['issue' => 'NCK-12', 'role' => 'gateway', '--argv' => '["orbit","doctor"]'])
            ->expectsOutputToContain('is proved; release it before changing it')
            ->assertFailed();
    });

    it('reads the attempt from the worktree and names an absent one', function () {
        ['worktree' => $worktree] = commandPrimaryFixture();
        app()->instance(
            \App\E2E\State\StatePaths::class,
            new \App\E2E\State\StatePaths(temporaryPath('orbit-command-host-', 6)),
        );

        $this
            ->artisan('topology:release', ['issue' => 'NCK-12', '--json' => true])
            ->expectsOutputToContain('NCK-12 has no active attempt.')
            ->assertFailed();
        $this
            ->artisan('topology:verify', ['issue' => 'NCK-12'])
            ->expectsOutputToContain('NCK-12 has no active attempt.')
            ->assertFailed();
        expect(file_get_contents($worktree.'/.e2e/log'))
            ->toContain('topology:release failed: NCK-12 has no active attempt.')
            ->toContain('topology:verify failed: NCK-12 has no active attempt.');
    });
});

function commandTopologyFixture(string $issue, AttemptId $attempt): \App\E2E\Value\FeatureTopology
{
    $target = \App\E2E\Value\TopologyTarget::feature($issue, $attempt);

    return new \App\E2E\Value\FeatureTopology(
        $target,
        AttemptPurpose::Proof,
        new \App\E2E\Value\TopologySnapshotGeneration(
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
        ),
        $target->network(),
        array_combine(
            \App\E2E\Value\TopologyProfile::ROLES,
            array_map($target->instance(...), \App\E2E\Value\TopologyProfile::ROLES),
        ),
        new \App\E2E\Value\SourceState(str_repeat('d', 40), str_repeat('d', 40)),
        new \App\E2E\Value\VerificationReport(true, ['ready' => verificationProbeFixture(probe: 'ready')]),
    );
}
