<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IssueState;
use App\E2E\ProofReviewService;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/**
 * @return array{
 *   worktree:string,
 *   request:TopologyRequest,
 *   state:IssueState,
 *   capture:CapturedProof,
 *   hostPaths:StatePaths,
 *   service:ProofReviewService
 * }
 */
function proofReviewServiceFixture(bool $proofFlow = true): array
{
    $worktree = temporaryPath('orbit-proof-review-', 6);
    mkdir($worktree, 0700, true);
    if ($proofFlow) {
        mkdir($worktree.'/.loop', 0700);
        file_put_contents($worktree.'/.loop/flow.json', "{\"schema\":1,\"flow\":\"proof\"}\n");
    }
    $attempt = new AttemptId(str_repeat('a', 32));
    $candidate = str_repeat('b', 40);
    $target = TopologyTarget::feature('ORB-230', $attempt, TopologyRecipe::extendedAppProd());
    $generation = new TopologySnapshotGeneration(
        'fixture-generation',
        str_repeat('c', 40),
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        str_repeat('d', 64),
        str_repeat('e', 64),
        new LaravelRelease('v13.10.1', str_repeat('f', 40)),
        str_repeat('1', 64),
        2,
        'ubuntu-26.04-amd64-v1',
        TopologyRecipe::BASE_IMAGE,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
    );
    $construction = TopologyConstructionInputs::create(
        $target,
        $generation,
        2,
        TopologyExtension::AppProd,
        str_repeat('2', 64),
    );
    $topology = new FeatureTopology(
        $construction,
        AttemptPurpose::Proof,
        $generation,
        new SourceState($candidate, $candidate),
        new VerificationReport(true, ['ready' => verificationProbeFixture()]),
    );
    $manifest = new ProofInputManifest(
        4,
        $candidate,
        str_repeat('3', 40),
        [],
        [],
        '.loop/proof/ORB-230.json',
        [],
        $construction,
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
    $plan = str_repeat('4', 64);
    $proof = [
        'status' => 'proved',
        'issue' => 'ORB-230',
        'attempt_id' => $attempt->value,
        'candidate_sha' => $candidate,
        'plan_sha256' => $plan,
        'manifest_sha256' => $manifest->fingerprint(),
        'actions' => [],
    ];
    $capture = new CapturedProof(
        'ORB-230',
        $attempt,
        $candidate,
        $plan,
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T10:00:00Z',
    );
    $state = IssueState::forWorktree('ORB-230', $worktree);
    $state->writeAttempt($attempt, AttemptPurpose::Proof, new OperationId(str_repeat('5', 32)), TopologyExtension::AppProd);
    $state->writeTopology($topology);
    $state->writeProof($proof);
    $state->captureProof($capture);
    $hostPaths = new StatePaths(temporaryPath('orbit-proof-review-host-', 6));
    new AtomicJsonStore($hostPaths)->write(
        'proof-evidence/ORB-230/'.$attempt->value.'.json',
        $capture->toArray(),
    );
    $tick = 0;
    $service = new ProofReviewService(
        new IncusHost,
        $hostPaths,
        new OperationId(str_repeat('6', 32)),
        new SecretRedactor(['top-secret']),
        function () use (&$tick): string {
            return sprintf('2026-09-10T10:00:%02dZ', ++$tick);
        },
    );

    return compact('worktree', 'state', 'capture', 'hostPaths', 'service') + [
        'request' => new TopologyRequest('ORB-230', $worktree),
    ];
}

/** @param list<array-key> $commands */
function fakeProofReviewHost(array &$commands, ?string $wrongOwner = null): void
{
    Process::fake(function (PendingProcess $process) use (&$commands, $wrongOwner) {
        $command = $process->command;
        assert(is_array($command));
        $commands[] = $command;
        $name = str_starts_with((string) ($command[4] ?? ''), 'local:')
            ? substr((string) $command[4], 6)
            : '';
        if (($command[3] ?? null) === 'list') {
            $role = str_ends_with($name, '-app-prod-2') ? 'app-prod-2' : 'gateway';

            return Process::result(json_encode([[
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => 'Running',
                'status_code' => 103,
                'config' => [
                    'user.orbit.e2e.owner' => $wrongOwner ?? 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'ORB-230',
                    'user.orbit.e2e.attempt' => str_repeat('a', 32),
                    'user.orbit.e2e.role' => $role,
                ],
                'devices' => [
                    'root' => ['pool' => 'default'],
                    'eth0' => ['network' => 'oe-'.substr(hash('sha256', 'ORB-230:'.str_repeat('a', 32)), 0, 12)],
                ],
            ]], JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'exec') {
            return Process::result(str_repeat('x', 5_000).' top-secret', 'Bearer top-secret', 0);
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
    });
}

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

it('records an incomplete action before exec and then stores a redacted bounded result', function (): void {
    $fixture = proofReviewServiceFixture();
    $commands = [];
    $incompleteObserved = false;
    Process::fake(function (PendingProcess $process) use (&$commands, &$incompleteObserved, $fixture) {
        $command = $process->command;
        assert(is_array($command));
        $commands[] = $command;
        $name = substr((string) ($command[4] ?? ''), 6);
        if (($command[3] ?? null) === 'list') {
            return Process::result(json_encode([[
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => 'Running',
                'status_code' => 103,
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'ORB-230',
                    'user.orbit.e2e.attempt' => str_repeat('a', 32),
                ],
                'devices' => [
                    'root' => ['pool' => 'default'],
                    'eth0' => ['network' => $fixture['capture']->topology->network],
                ],
            ]], JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'exec') {
            $incompleteObserved = $fixture['state']->reviewRecord()?->action('inspect-extra')?->status === 'incomplete';

            return Process::result(str_repeat('x', 5_000).' top-secret', 'Bearer top-secret', 0);
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $command));
    });

    $result = $fixture['service']->execute(
        $fixture['request'],
        'inspect-extra',
        'app-prod-2',
        ['printf', '--token=top-secret'],
        true,
    );
    $action = $fixture['state']->reviewRecord()?->action('inspect-extra');
    $archived = new AtomicJsonStore($fixture['hostPaths'])->read(
        'proof-review/ORB-230/'.str_repeat('a', 32).'.json',
    );

    expect(rtrim($result->stdout))->toEndWith('top-secret')
        ->and($incompleteObserved)->toBeTrue()
        ->and($action?->node)->toBe('app-prod-2')
        ->and($action?->status)->toBe('passed')
        ->and($action?->argv)->toBe(['printf', '--token=[REDACTED]'])
        ->and($action?->stdinSha256)->toBeNull()
        ->and($action?->stdout)->not->toContain('top-secret')
        ->and(strlen((string) $action?->stdout))->toBeLessThanOrEqual(4_096)
        ->and(rtrim((string) $action?->stderr))->toBe('Bearer [REDACTED]')
        ->and($fixture['state']->reviewEvaluation()?->status)->toBe('ready')
        ->and($archived)->toBe($fixture['state']->reviewRecord()?->toArray())
        ->and($fixture['state']->capturedProof()?->toArray())->toBe($fixture['capture']->toArray());
});

it('records an immutable stdin fingerprint without retaining stdin bytes', function (): void {
    $fixture = proofReviewServiceFixture();
    $commands = [];
    fakeProofReviewHost($commands);

    $fixture['service']->execute(
        $fixture['request'],
        'inspect-stdin',
        'gateway',
        ['sh'],
        true,
        'top-secret input',
    );
    $action = $fixture['state']->reviewRecord()?->action('inspect-stdin');

    expect($action?->stdinSha256)
        ->toBe(hash('sha256', 'top-secret input'))
        ->and(json_encode($action?->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('top-secret input');
});

it('keeps a required shell incomplete until its redacted finding is completed', function (): void {
    $fixture = proofReviewServiceFixture();
    $commands = [];
    fakeProofReviewHost($commands);

    $instance = $fixture['service']->beginShell($fixture['request'], 'manual-check', 'gateway', true);

    expect($instance)->toBe($fixture['capture']->topology->target->instance('gateway'))
        ->and($fixture['state']->reviewRecord()?->action('manual-check')?->status)->toBe('incomplete')
        ->and($fixture['state']->reviewEvaluation()?->requiredIncomplete)->toBe(['manual-check']);

    $evaluation = $fixture['service']->complete(
        $fixture['request'],
        'manual-check',
        'failed',
        'Authorization: Bearer top-secret',
    );

    expect($evaluation->status)->toBe('blocked')
        ->and($evaluation->requiredFailed)->toBe(['manual-check'])
        ->and($fixture['state']->reviewRecord()?->action('manual-check')?->finding)
        ->toBe('Authorization: [REDACTED]');
});

it('reports an exploratory failure separately without blocking readiness', function (): void {
    $fixture = proofReviewServiceFixture();
    Process::fake(function (PendingProcess $process) use ($fixture) {
        $command = $process->command;
        assert(is_array($command));
        $name = substr((string) ($command[4] ?? ''), 6);
        if (($command[3] ?? null) === 'list') {
            return Process::result(json_encode([[
                'name' => $name,
                'type' => 'virtual-machine',
                'status' => 'Running',
                'status_code' => 103,
                'config' => [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.issue' => 'ORB-230',
                    'user.orbit.e2e.attempt' => str_repeat('a', 32),
                ],
                'devices' => [
                    'root' => ['pool' => 'default'],
                    'eth0' => ['network' => $fixture['capture']->topology->network],
                ],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result(errorOutput: 'diagnostic mismatch', exitCode: 7);
    });

    $fixture['service']->execute($fixture['request'], 'explore', 'app-dev', ['false'], false);
    $evaluation = $fixture['service']->evaluate($fixture['request']);

    expect($evaluation->status)->toBe('ready')
        ->and($evaluation->requiredFailed)->toBe([])
        ->and($evaluation->exploratoryFailed)->toBe(['explore']);
});

it('leaves the recorded action incomplete when physical ownership validation fails', function (): void {
    $fixture = proofReviewServiceFixture();
    $commands = [];
    fakeProofReviewHost($commands, 'somebody-else');

    expect(fn () => $fixture['service']->beginShell($fixture['request'], 'identity-check', 'gateway', true))
        ->toThrow(RuntimeException::class, 'identity does not match');

    expect($fixture['state']->reviewRecord()?->action('identity-check')?->status)->toBe('incomplete')
        ->and($fixture['state']->reviewEvaluation()?->status)->toBe('blocked');
});

it('refuses the lifecycle when the worktree selected discovery', function (): void {
    $fixture = proofReviewServiceFixture(false);

    expect(fn () => $fixture['service']->evaluate($fixture['request']))
        ->toThrow(RuntimeException::class, 'The discovery flow disables proof, capture, review');
});
