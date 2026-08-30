<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\OrphanNetworkSweep;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/**
 * A host holding the attempt's VMs and network with the given metadata; every
 * mutation is recorded and reflected in later reads.
 *
 * @mago-expect lint:cyclomatic-complexity The fake answers each Incus read and mutation kind.
 *
 * @param array<string, string> $metadata
 * @param list<string> $commands
 */
function fakeReleaseHost(TopologyTarget $target, array $metadata, array &$commands, bool $network = true): void
{
    $present = array_map($target->instance(...), TopologyProfile::ROLES);
    Process::fake(function (PendingProcess $process) use ($target, $metadata, &$commands, &$present, &$network) {
        $command = $process->command;
        assert(is_array($command));
        $commands[] = implode(' ', array_slice($command, 3));
        if (($command[0] ?? null) === 'python3') {
            return Process::result('{"changed":true}');
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(json_encode(
                $network
                    ? [[
                        'name' => $target->network(),
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', ...$metadata],
                        'used_by' => [],
                    ]] : [],
                JSON_THROW_ON_ERROR,
            ));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $network = false;

            return Process::result();
        }
        if (($command[3] ?? null) === 'list') {
            $wanted = ($command[4] ?? '') === 'local:'
                ? $present
                : array_intersect($present, [substr((string) $command[4], 6)]);

            return Process::result(json_encode(
                array_values(array_map(
                    static fn (string $name): array => [
                        'name' => $name,
                        'type' => 'virtual-machine',
                        'status' => 'Running',
                        'status_code' => 103,
                        'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', ...$metadata],
                        'devices' => ['root' => ['pool' => 'default'], 'eth0' => ['network' => $target->network()]],
                    ],
                    $wanted,
                )),
                JSON_THROW_ON_ERROR,
            ));
        }
        if (($command[3] ?? null) === 'delete') {
            $present = array_values(array_diff($present, [substr((string) $command[4], 6)]));
        }

        return Process::result();
    });
}

function releaserForTest(StatePaths $paths): TopologyReleaser
{
    $host = new IncusHost;
    $operation = new OperationId(str_repeat('b', 32));

    return new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        $paths,
        $operation,
        new OrphanNetworkSweep($host, new IncusNetworkLifecycle($host), $paths, $operation),
    );
}

describe('TopologyReleaser', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('stops and deletes the exact VMs and network, drops the lease, and keeps the proof', function () {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('NCK-12', $attempt);
        $state = IssueState::forWorktree('NCK-12', $worktree);
        $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('c', 32)));
        $state->writeProof(['status' => 'proved', 'attempt_id' => $attempt->value]);
        $commands = [];
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'NCK-12', 'user.orbit.e2e.attempt' => $attempt->value],
            $commands,
        );

        $result = releaserForTest($paths)->release(new TopologyRequest('NCK-12', $worktree));

        expect($result['state'])
            ->toBe('released')
            ->and($result['attempt_id'])
            ->toBe($attempt->value)
            ->and($result['already_absent'])
            ->toBe([])
            ->and($result['networks_reaped'])
            ->toBe([])
            ->and($result['released'])
            ->toEqualCanonicalizing([
                'stopped:'.$target->instance('gateway'),
                'stopped:'.$target->instance('app-dev'),
                'stopped:'.$target->instance('app-prod'),
                'deleted:'.$target->instance('gateway'),
                'deleted:'.$target->instance('app-dev'),
                'deleted:'.$target->instance('app-prod'),
                'deleted:'.$target->network(),
            ])
            ->and($state->hasAttempt())
            ->toBeFalse()
            ->and($state->proof()['status'] ?? null)
            ->toBe('proved')
            ->and(array_values(array_filter($commands, static fn (string $command): bool => str_starts_with(
                $command,
                'delete',
            ))))
            ->toBe([
                'delete local:'.$target->instance('app-prod'),
                'delete local:'.$target->instance('app-dev'),
                'delete local:'.$target->instance('gateway'),
            ]);
    });

    it('refuses a VM that another attempt owns and names an absent attempt', function () {
        $worktree = temporaryPath('orbit-release-worktree-', 4);
        mkdir($worktree, 0700);
        $paths = new StatePaths(temporaryPath('orbit-release-host-', 4));
        $attempt = new AttemptId(str_repeat('a', 32));
        $target = TopologyTarget::feature('NCK-12', $attempt);
        $commands = [];

        expect(fn () => releaserForTest($paths)->release(new TopologyRequest('NCK-12', $worktree)))
            ->toThrow(RuntimeException::class, 'NCK-12 has no active attempt.');

        IssueState::forWorktree('NCK-12', $worktree)
            ->writeAttempt($attempt, AttemptPurpose::Discovery, new OperationId(str_repeat('c', 32)));
        fakeReleaseHost(
            $target,
            ['user.orbit.e2e.issue' => 'NCK-12', 'user.orbit.e2e.attempt' => str_repeat('f', 32)],
            $commands,
        );

        expect(fn () => releaserForTest($paths)->release(new TopologyRequest('NCK-12', $worktree)))
            ->toThrow(RuntimeException::class, 'ownership does not match the issue attempt')
            ->and(array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'delete')))
            ->toBe([])
            ->and(IssueState::forWorktree('NCK-12', $worktree)->hasAttempt())
            ->toBeTrue();
    });
});
