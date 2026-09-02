<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;
use PHPUnit\Framework\Assert;
use Tests\Live\Support\LiveHarness;
use Tests\Live\Support\PhaseTimings;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The simple flow of one isolated issue, end to end through the public
 * wrappers: a mounted discovery attempt remains active while the worktree HEAD
 * is proved on a fresh topology, the proved topology refuses mutation while
 * discovery remains usable, the same commit proves again, promotion releases
 * both topologies, a discovery attempt clones the promoted generation, and a
 * plan declaring an end state it did not bring about is refused. Every phase
 * asserts the state under `<worktree>/.e2e/`, and cleanup only ever names the
 * exact attempt.
 *
 * @mago-expect lint:cyclomatic-complexity,halstead,kan-defect Live acceptance keeps the ordered evidence chain visible.
 * @mago-expect analysis:non-documented-method,mixed-assignment,mixed-argument,mixed-array-access,mixed-method-access,impossible-condition Pest phase callbacks preserve their concrete runtime values.
 */
it('proves the simple flow through public wrappers', function (): void {
    if (getenv('ORBIT_LIVE_INCUS') !== '1') {
        test()->markTestSkipped('Set ORBIT_LIVE_INCUS=1 to run.');
    }

    $inputs = LiveHarness::inputs([
        'ORBIT_LIVE_PROFILE',
        'ORBIT_LIVE_ISSUE',
        'ORBIT_LIVE_MAIN_WORKTREE',
        'ORBIT_LIVE_FEATURE_WORKTREE',
        'ORBIT_LIVE_CANDIDATE_SHA',
        'ORBIT_LIVE_PROOF_PLAN',
    ]);
    Assert::assertSame(TopologyProfile::NAME, $inputs['ORBIT_LIVE_PROFILE']);
    $repositoryRoot = LiveHarness::repositoryRoot();
    Assert::assertSame(realpath($repositoryRoot), realpath($inputs['ORBIT_LIVE_MAIN_WORKTREE']));
    Assert::assertNotSame(realpath($repositoryRoot), realpath($inputs['ORBIT_LIVE_FEATURE_WORKTREE']));

    $issue = $inputs['ORBIT_LIVE_ISSUE'];
    TopologyTarget::assertIssue($issue);
    $mainWorktree = $inputs['ORBIT_LIVE_MAIN_WORKTREE'];
    $featureWorktree = $inputs['ORBIT_LIVE_FEATURE_WORKTREE'];
    $candidateSha = $inputs['ORBIT_LIVE_CANDIDATE_SHA'];
    $proofPlanFile = $inputs['ORBIT_LIVE_PROOF_PLAN'];
    $stateRoot = rtrim($featureWorktree, '/').'/.e2e';
    $worktreeOption = "--worktree={$featureWorktree}";

    Assert::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $candidateSha);
    Assert::assertSame($candidateSha, LiveHarness::git($featureWorktree, ['rev-parse', '--verify', 'HEAD^{commit}']));
    Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
    Assert::assertSame(
        LiveHarness::git($mainWorktree, ['rev-parse', '--path-format=absolute', '--git-common-dir']),
        LiveHarness::git($featureWorktree, ['rev-parse', '--path-format=absolute', '--git-common-dir']),
    );
    $initialMainSha = LiveHarness::git($mainWorktree, ['rev-parse', '--verify', 'HEAD^{commit}']);
    $plan = ProofPlan::fromFile($proofPlanFile);
    Assert::assertNotSame([], $plan->acceptance);
    // The discovery exec runs the first declared acceptance action, so both purposes prove one command.
    $discoveryCommand = $plan->acceptance[0];
    $overlayPath = 'apps/e2e/.live-overlay-'.$issue.'.txt';
    $overlayFile = $featureWorktree.'/'.$overlayPath;
    Assert::assertFileDoesNotExist($overlayFile);

    $timings = new PhaseTimings;
    $releasedAttempts = [];
    $primaryFailure = null;

    try {
        $initialTopologySnapshot = LiveHarness::jsonWrapper('topology-snapshot', 'status');
        Assert::assertSame('promoted', $initialTopologySnapshot['state'] ?? null);
        Assert::assertTrue($initialTopologySnapshot['stopped'] ?? false);
        Assert::assertIsArray($initialTopologySnapshot['generation'] ?? null);
        $initialInventory = LiveHarness::inventoryFingerprint();
        Assert::assertSame(
            'absent',
            LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption)['state'] ?? null,
        );
        Assert::assertFileDoesNotExist("{$stateRoot}/attempt.json");

        // Phase: acquire discovery on the mounted worktree.
        $acquire = LiveHarness::jsonPhase(
            'acquire discovery',
            fn (): array => LiveHarness::jsonWrapper('topology', 'acquire', $issue, $featureWorktree),
            $timings,
        );
        Assert::assertSame('discovery', $acquire['state'] ?? null);
        $discoveryAttempt = lifecycleAttemptId($acquire);
        $discovery = TopologyTarget::feature($issue, new AttemptId($discoveryAttempt));
        lifecycleAssertTopology($acquire['topology'] ?? null, $discovery, 'discovery', $candidateSha);
        Assert::assertTrue($acquire['topology']['source']['mounted'] ?? false);
        $mounts = $acquire['topology']['mounts'] ?? null;
        Assert::assertIsArray($mounts);
        Assert::assertSame(TopologyProfile::CHECKOUT_ROLES, array_keys($mounts));
        foreach ($mounts as $mount) {
            Assert::assertSame(FeatureTopology::SOURCE_DEVICE, $mount['device'] ?? null);
            Assert::assertSame(realpath($featureWorktree), realpath((string) ($mount['source'] ?? '')));
        }
        Assert::assertSame($acquire['topology'], LiveHarness::jsonFile("{$stateRoot}/topology.json"));
        $lease = LiveHarness::jsonFile("{$stateRoot}/attempt.json");
        Assert::assertSame($issue, $lease['issue'] ?? null);
        Assert::assertSame($discoveryAttempt, $lease['attempt_id'] ?? null);
        Assert::assertSame('discovery', $lease['purpose'] ?? null);
        Assert::assertStringContainsString(
            'topology:acquire attempt='.$discoveryAttempt,
            (string) file_get_contents("{$stateRoot}/log"),
        );
        foreach (TopologyProfile::ROLES as $role) {
            $instance = LiveHarness::incusResource('instance', $discovery->instance($role));
            Assert::assertSame('RUNNING', strtoupper((string) ($instance['status'] ?? '')));
            $hasMount = array_key_exists(FeatureTopology::SOURCE_DEVICE, $instance['expanded_devices'] ?? []);
            Assert::assertSame(in_array($role, TopologyProfile::CHECKOUT_ROLES, true), $hasMount);
        }
        LiveHarness::incusResource('network', $discovery->network());

        // Phase: sync one harmless dirty overlay, then the clean tree again.
        file_put_contents($overlayFile, "harmless live overlay\n", LOCK_EX);
        try {
            $dirty = LiveHarness::jsonPhase(
                'sync dirty overlay',
                fn (): array => LiveHarness::jsonWrapper('topology', 'sync', $issue, $worktreeOption),
                $timings,
            );
            Assert::assertSame('ready', $dirty['state'] ?? null);
            Assert::assertSame($discoveryAttempt, $dirty['attempt_id'] ?? null);
            Assert::assertTrue($dirty['source']['dirty'] ?? false);
            Assert::assertSame([$overlayPath], $dirty['source']['overlay_paths'] ?? null);
            Assert::assertSame($candidateSha, $dirty['source']['host_sha'] ?? null);
            Assert::assertSame($dirty['source'], LiveHarness::jsonFile("{$stateRoot}/topology.json")['source'] ?? null);
        } finally {
            Assert::assertTrue(unlink($overlayFile));
        }
        Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
        $clean = LiveHarness::jsonPhase(
            'sync clean tree',
            fn (): array => LiveHarness::jsonWrapper('topology', 'sync', $issue, $worktreeOption),
            $timings,
        );
        Assert::assertSame('ready', $clean['state'] ?? null);
        Assert::assertFalse($clean['source']['dirty'] ?? true);
        Assert::assertSame([], $clean['source']['overlay_paths'] ?? null);

        // Phase: run one arbitrary discovery command as the orbit user.
        $executed = LiveHarness::jsonPhase(
            'exec discovery command',
            fn (): array => lifecycleExec($issue, $worktreeOption, $discoveryCommand),
            $timings,
        );
        Assert::assertSame(0, $executed['exit_code'] ?? null);
        Assert::assertIsArray(LiveHarness::json((string) ($executed['stdout'] ?? '')));

        // Phase: prove the worktree HEAD on a separate attempt while discovery stays active.
        $proved = LiveHarness::jsonPhase(
            'prove candidate',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'prove',
                $issue,
                $worktreeOption,
                "--plan={$proofPlanFile}",
            ),
            $timings,
        );
        $proofAttempt = lifecycleAssertProof($proved, $issue, $candidateSha, $plan, $stateRoot);
        Assert::assertNotSame($discoveryAttempt, $proofAttempt);
        $proof = TopologyTarget::feature($issue, new AttemptId($proofAttempt));
        $proofStatus = LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption);
        Assert::assertSame('discovery+proof', $proofStatus['state'] ?? null);
        Assert::assertSame($discoveryAttempt, $proofStatus['attempt_id'] ?? null);
        Assert::assertSame($proofAttempt, $proofStatus['proof_attempt_id'] ?? null);
        Assert::assertTrue($proofStatus['proved'] ?? false);
        lifecycleAssertTopology($proofStatus['topology'] ?? null, $discovery, 'discovery', $candidateSha);
        lifecycleAssertTopology($proofStatus['proof_topology'] ?? null, $proof, 'proof', $candidateSha);
        Assert::assertSame([], $proofStatus['proof_topology']['mounts'] ?? null);
        Assert::assertFalse($proofStatus['proof_topology']['source']['mounted'] ?? true);
        Assert::assertTrue($proofStatus['proof_topology']['verification']['passed'] ?? false);
        foreach (TopologyProfile::ROLES as $role) {
            $instance = LiveHarness::incusResource('instance', $proof->instance($role));
            Assert::assertSame('RUNNING', strtoupper((string) ($instance['status'] ?? '')));
            Assert::assertArrayNotHasKey(FeatureTopology::SOURCE_DEVICE, $instance['expanded_devices'] ?? []);
            Assert::assertSame(
                'RUNNING',
                strtoupper(
                    (string) (LiveHarness::incusResource('instance', $discovery->instance($role))['status'] ?? ''),
                ),
            );
        }

        // Phase: discovery remains usable, while the proved topology refuses command execution.
        LiveHarness::voidPhase(
            'keep discovery and proof boundaries',
            function () use ($issue, $worktreeOption, $discoveryCommand): void {
                $synced = LiveHarness::jsonWrapper('topology', 'sync', $issue, $worktreeOption);
                Assert::assertSame('ready', $synced['state'] ?? null);
                $argvFile = lifecycleArgvFile($discoveryCommand);
                $executed = LiveHarness::jsonWrapper(
                    'topology',
                    'exec',
                    $issue,
                    $discoveryCommand['node'],
                    $worktreeOption,
                    "--argv-file={$argvFile}",
                );
                Assert::assertSame(0, $executed['exit_code'] ?? null);
                $rejectedExec = LiveHarness::failedJsonWrapper(
                    'topology',
                    'exec',
                    $issue,
                    $discoveryCommand['node'],
                    $worktreeOption,
                    "--argv-file={$argvFile}",
                    '--proof',
                );
                Assert::assertStringContainsString('is proved; release it', (string) $rejectedExec['error']);
                $verified = LiveHarness::jsonWrapper('topology', 'verify', $issue, $worktreeOption);
                Assert::assertSame('verified', $verified['state'] ?? null);
                Assert::assertTrue($verified['verification']['passed'] ?? false);
            },
            $timings,
        );

        // Phase: release only the proved topology; discovery and its result stay.
        $proofRelease = LiveHarness::jsonPhase(
            'release proof',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption, '--proof'),
            $timings,
        );
        $releasedAttempts[] = $proofAttempt;
        lifecycleAssertRelease($proofRelease, $proof, 'proof', $stateRoot, $worktreeOption, $discovery);
        Assert::assertSame($proved, LiveHarness::jsonFile("{$stateRoot}/proof.json"));

        // Phase: prove the same unchanged candidate on another fresh attempt.
        Assert::assertSame($candidateSha, LiveHarness::git($featureWorktree, [
            'rev-parse',
            '--verify',
            'HEAD^{commit}',
        ]));
        Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
        $reproved = LiveHarness::jsonPhase(
            'prove candidate again',
            fn (): array => LiveHarness::jsonWrapper('topology', 'prove', $issue, $worktreeOption),
            $timings,
        );
        $secondProofAttempt = lifecycleAssertProof($reproved, $issue, $candidateSha, $plan, $stateRoot);
        Assert::assertNotContains($secondProofAttempt, [$discoveryAttempt, $proofAttempt]);
        $secondProof = TopologyTarget::feature($issue, new AttemptId($secondProofAttempt));
        LiveHarness::assertIncusAbsent(lifecycleResourceNames($proof));
        // Phase: promote the proved topology to the topology snapshot generation; promote releases it.
        $promote = LiveHarness::jsonPhase(
            'promote proved topology',
            fn (): array => LiveHarness::jsonWrapper(
                'topology-snapshot',
                'promote',
                $issue,
                $worktreeOption,
                "--plan={$proofPlanFile}",
            ),
            $timings,
        );
        $releasedAttempts[] = $secondProofAttempt;
        $releasedAttempts[] = $discoveryAttempt;
        $generationId = lifecycleAssertPromotion(
            $promote,
            $secondProof,
            $discovery,
            $candidateSha,
            $initialTopologySnapshot,
            $stateRoot,
            $worktreeOption,
        );
        Assert::assertSame($reproved, LiveHarness::jsonFile("{$stateRoot}/proof.json"));
        $promotedTopologySnapshot = LiveHarness::jsonWrapper('topology-snapshot', 'status');
        Assert::assertSame('promoted', $promotedTopologySnapshot['state'] ?? null);
        Assert::assertTrue($promotedTopologySnapshot['stopped'] ?? false);
        Assert::assertSame($generationId, $promotedTopologySnapshot['generation']['id'] ?? null);
        Assert::assertSame($candidateSha, $promotedTopologySnapshot['generation']['main_sha'] ?? null);
        Assert::assertSame(
            $initialTopologySnapshot['generation']['laravel_pin'] ?? null,
            $promotedTopologySnapshot['generation']['laravel_pin'] ?? null,
        );

        // Phase: a discovery attempt clones the promoted generation and runs the same command.
        $promotedAcquire = LiveHarness::jsonPhase(
            'acquire from promoted generation',
            fn (): array => LiveHarness::jsonWrapper('topology', 'acquire', $issue, $featureWorktree),
            $timings,
        );
        Assert::assertSame('discovery', $promotedAcquire['state'] ?? null);
        $promotedAttempt = lifecycleAttemptId($promotedAcquire);
        Assert::assertNotContains($promotedAttempt, [$discoveryAttempt, $proofAttempt, $secondProofAttempt]);
        $promotedDiscovery = TopologyTarget::feature($issue, new AttemptId($promotedAttempt));
        lifecycleAssertTopology($promotedAcquire['topology'] ?? null, $promotedDiscovery, 'discovery', $candidateSha);
        Assert::assertSame($generationId, $promotedAcquire['topology']['generation']['id'] ?? null);
        $promotedExec = LiveHarness::jsonPhase(
            'exec on promoted generation',
            fn (): array => lifecycleExec($issue, $worktreeOption, $discoveryCommand),
            $timings,
        );
        Assert::assertSame(0, $promotedExec['exit_code'] ?? null);
        Assert::assertIsArray(LiveHarness::json((string) ($promotedExec['stdout'] ?? '')));
        $promotedRelease = LiveHarness::jsonPhase(
            'release promoted discovery',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption),
            $timings,
        );
        $releasedAttempts[] = $promotedAttempt;
        lifecycleAssertRelease($promotedRelease, $promotedDiscovery, 'discovery', $stateRoot, $worktreeOption);

        // Phase: a plan may declare the topology it ends with, and the harness
        // checks the declaration rather than taking it. app-prod is still
        // registered here, so declaring it absent must end in diagnosis, with
        // only its own probes skipped and named in the record.
        $endsWithPlanFile = dirname($proofPlanFile).'/'.$issue.'-ends-with.json';
        $endsWithPlan = ProofPlan::fromFile($endsWithPlanFile);
        Assert::assertSame(['gateway', 'app-dev'], $endsWithPlan->endsWith->nodes);
        Assert::assertTrue($endsWithPlan->mutates);
        $declared = LiveHarness::jsonPhase(
            'prove an end state the plan did not bring about',
            fn (): array => lifecycleProveDiagnosis($issue, $worktreeOption, $endsWithPlanFile),
            $timings,
        );
        $declaredAttempt = lifecycleAttemptId($declared);
        Assert::assertNotContains($declaredAttempt, $releasedAttempts);
        Assert::assertSame(['nodes' => ['gateway', 'app-dev']], $declared['ends_with'] ?? null);
        Assert::assertSame(
            ['vm.app-prod.running', 'role.app-prod', 'php-fpm.app-prod', 'caddy.app-prod', 'laravel.prod'],
            $declared['skipped_probes'] ?? null,
        );
        // The registry probe is never skipped, so it is what refuses the declaration.
        Assert::assertStringContainsString('role.assignments', (string) ($declared['error'] ?? ''));
        Assert::assertSame($declared, LiveHarness::jsonFile("{$stateRoot}/proof.json"));
        $declaredTopology = TopologyTarget::feature($issue, new AttemptId($declaredAttempt));
        $declaredRelease = LiveHarness::jsonPhase(
            'release the declared end state attempt',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption, '--proof'),
            $timings,
        );
        $releasedAttempts[] = $declaredAttempt;
        lifecycleAssertRelease($declaredRelease, $declaredTopology, 'proof', $stateRoot, $worktreeOption);

        // Phase: only the topology snapshot changed, and only by promotion.
        LiveHarness::voidPhase(
            'verify host',
            function () use (
                $promotedTopologySnapshot,
                $initialInventory,
                $issue,
                $stateRoot,
                $worktreeOption,
                $featureWorktree,
                $candidateSha,
                $mainWorktree,
                $initialMainSha,
            ): void {
                Assert::assertSame($promotedTopologySnapshot, LiveHarness::jsonWrapper('topology-snapshot', 'status'));
                Assert::assertSame(
                    [],
                    lifecycleUnexpectedInventoryChanges(
                        $initialInventory,
                        LiveHarness::inventoryFingerprint(),
                        $issue,
                    ),
                );
                $topologySnapshot = lifecycleTopologySnapshotTarget();
                foreach (TopologyProfile::ROLES as $role) {
                    $instance = LiveHarness::incusResource('instance', $topologySnapshot->instance($role));
                    Assert::assertSame('STOPPED', strtoupper((string) ($instance['status'] ?? '')));
                    Assert::assertSame(
                        $topologySnapshot->network(),
                        $instance['expanded_devices']['eth0']['network'] ?? null,
                    );
                    Assert::assertArrayNotHasKey('user.orbit.e2e.issue', $instance['config'] ?? []);
                    Assert::assertArrayNotHasKey('user.orbit.e2e.attempt', $instance['config'] ?? []);
                }
                Assert::assertSame(
                    'absent',
                    LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption)['state'] ?? null,
                );
                Assert::assertFileDoesNotExist("{$stateRoot}/attempt.json");
                Assert::assertFileDoesNotExist("{$stateRoot}/topology.json");
                Assert::assertSame($candidateSha, LiveHarness::git($featureWorktree, [
                    'rev-parse',
                    '--verify',
                    'HEAD^{commit}',
                ]));
                Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
                Assert::assertSame($initialMainSha, LiveHarness::git($mainWorktree, [
                    'rev-parse',
                    '--verify',
                    'HEAD^{commit}',
                ]));
            },
            $timings,
        );
    } catch (Throwable $exception) {
        $primaryFailure = $exception;
    } finally {
        $cleanupFailure = null;
        try {
            lifecycleCleanup($issue, $worktreeOption, $releasedAttempts, $overlayFile, $timings);
        } catch (Throwable $exception) {
            $cleanupFailure = $exception;
        }
        $summary = $timings->summary();
        LiveHarness::note($summary);
        fwrite(STDERR, PHP_EOL.$summary.PHP_EOL);
    }

    if ($primaryFailure !== null && $cleanupFailure !== null) {
        throw new RuntimeException(
            "Live acceptance failed: {$primaryFailure->getMessage()} Cleanup also failed: {$cleanupFailure->getMessage()}",
            previous: $primaryFailure,
        );
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
    if ($primaryFailure !== null) {
        throw $primaryFailure;
    }
})->group('incus-live');

/**
 * Release whichever proof and discovery attempts remain, and prove every
 * attempt this run touched is gone. Nothing is matched by prefix.
 *
 * @param list<string> $releasedAttempts
 * @mago-expect lint:cyclomatic-complexity Cleanup handles each valid combination of retained discovery and proof.
 */
function lifecycleCleanup(
    string $issue,
    string $worktreeOption,
    array $releasedAttempts,
    string $overlayFile,
    PhaseTimings $timings,
): void {
    if (file_exists($overlayFile)) {
        Assert::assertTrue(unlink($overlayFile));
    }
    $status = LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption);
    $proofAttempt =
        $status['proof_attempt_id']
        ?? (
            ($status['state'] ?? null) === 'proof'
                ? $status['attempt_id'] ?? null
                : null
        );
    if (is_string($proofAttempt)) {
        $release = LiveHarness::jsonPhase(
            'cleanup release proof',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption, '--proof'),
            $timings,
        );
        Assert::assertSame('released', $release['state'] ?? null);
        Assert::assertSame('proof', $release['purpose'] ?? null);
        Assert::assertSame($proofAttempt, $release['attempt_id'] ?? null);
        $releasedAttempts[] = $proofAttempt;
    }
    $status = LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption);
    $discoveryAttempt = ($status['state'] ?? null) === 'discovery'
        ? $status['attempt_id'] ?? null
        : null;
    if (is_string($discoveryAttempt)) {
        $release = LiveHarness::jsonPhase(
            'cleanup release discovery',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption),
            $timings,
        );
        Assert::assertSame('released', $release['state'] ?? null);
        Assert::assertSame('discovery', $release['purpose'] ?? null);
        Assert::assertSame($discoveryAttempt, $release['attempt_id'] ?? null);
        $releasedAttempts[] = $discoveryAttempt;
    }
    Assert::assertSame(
        'absent',
        LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption)['state'] ?? null,
    );
    $names = [];
    foreach (array_unique($releasedAttempts) as $attempt) {
        $names = [...$names, ...lifecycleResourceNames(TopologyTarget::feature($issue, new AttemptId($attempt)))];
    }
    LiveHarness::assertIncusAbsent($names);
}

/**
 * Prove with a plan that must not succeed, and return its diagnosis record.
 *
 * @return array<array-key, mixed>
 */
function lifecycleProveDiagnosis(string $issue, string $worktreeOption, string $planFile): array
{
    $result = LiveHarness::wrapper('topology', 'prove', $issue, $worktreeOption, "--plan={$planFile}");
    Assert::assertFalse($result->successful(), 'The proof succeeded but its declared end state is not the truth.');
    $payload = LiveHarness::json($result->output());
    Assert::assertSame('diagnosis', $payload['status'] ?? null);
    Assert::assertSame($issue, $payload['issue'] ?? null);

    return $payload;
}

/** @param array<array-key, mixed> $payload */
function lifecycleAttemptId(array $payload): string
{
    $attempt = $payload['attempt_id'] ?? null;
    Assert::assertIsString($attempt);
    Assert::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $attempt);

    return $attempt;
}

/** @return list<string> */
function lifecycleResourceNames(TopologyTarget $target): array
{
    return [...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()];
}

/** @param array{id:string,node:string,argv:list<string>,timeout_seconds:int} $command */
function lifecycleArgvFile(array $command): string
{
    $path = temporaryFile('orbit-e2e-argv-');
    file_put_contents($path, json_encode(['argv' => $command['argv'], 'stdin' => null], JSON_THROW_ON_ERROR), LOCK_EX);

    return $path;
}

/**
 * @param array{id:string,node:string,argv:list<string>,timeout_seconds:int} $command
 * @return array<array-key, mixed>
 */
function lifecycleExec(string $issue, string $worktreeOption, array $command): array
{
    $argvFile = lifecycleArgvFile($command);
    $executed = LiveHarness::jsonWrapper(
        'topology',
        'exec',
        $issue,
        $command['node'],
        $worktreeOption,
        "--argv-file={$argvFile}",
    );
    Assert::assertSame('executed', $executed['state'] ?? null);
    Assert::assertIsString($executed['stdout'] ?? null);
    Assert::assertIsString($executed['stderr'] ?? null);

    return $executed;
}

function lifecycleAssertTopology(mixed $topology, TopologyTarget $target, string $purpose, string $candidateSha): void
{
    Assert::assertIsArray($topology);
    Assert::assertSame(FeatureTopology::SCHEMA, $topology['schema'] ?? null);
    Assert::assertSame($target->issue, $topology['issue'] ?? null);
    Assert::assertSame($target->requireAttempt()->value, $topology['attempt_id'] ?? null);
    Assert::assertSame($purpose, $topology['purpose'] ?? null);
    Assert::assertSame(TopologyProfile::NAME, $topology['profile'] ?? null);
    Assert::assertSame($target->network(), $topology['network'] ?? null);
    Assert::assertSame([
        'gateway' => $target->instance('gateway'),
        'app-dev' => $target->instance('app-dev'),
        'app-prod' => $target->instance('app-prod'),
    ], $topology['instances'] ?? null);
    Assert::assertSame($candidateSha, $topology['source']['host_sha'] ?? null);
    Assert::assertSame($candidateSha, $topology['source']['guest_sha'] ?? null);
    Assert::assertFalse($topology['source']['dirty'] ?? true);
}

/**
 * The compact proof verdict: proved, every declared action at exit 0, and the
 * same object persisted as `<worktree>/.e2e/proof.json`.
 *
 * @param array<array-key, mixed> $payload
 */
function lifecycleAssertProof(
    array $payload,
    string $issue,
    string $candidateSha,
    ProofPlan $plan,
    string $stateRoot,
): string {
    Assert::assertSame('proved', $payload['status'] ?? null);
    Assert::assertSame($issue, $payload['issue'] ?? null);
    $attempt = lifecycleAttemptId($payload);
    Assert::assertSame($candidateSha, $payload['candidate_sha'] ?? null);
    Assert::assertSame($plan->fingerprint(), $payload['plan_sha256'] ?? null);
    Assert::assertArrayNotHasKey('failed_action', $payload);
    Assert::assertArrayNotHasKey('error', $payload);
    $expected = [];
    foreach ([...$plan->setup, ...$plan->acceptance] as $action) {
        $expected[] = ['id' => $action['id'], 'node' => $action['node'], 'exit_code' => 0];
    }
    Assert::assertSame($expected, $payload['actions'] ?? null);
    Assert::assertSame($payload, LiveHarness::jsonFile("{$stateRoot}/proof.json"));
    Assert::assertSame($attempt, LiveHarness::jsonFile("{$stateRoot}/proof-attempt.json")['attempt_id'] ?? null);
    Assert::assertSame($attempt, LiveHarness::jsonFile("{$stateRoot}/proof-topology.json")['attempt_id'] ?? null);

    return $attempt;
}

/**
 * @param array<array-key, mixed> $release
 * @mago-expect lint:excessive-parameter-list The assertion names the selected and optional retained topology explicitly.
 */
function lifecycleAssertRelease(
    array $release,
    TopologyTarget $target,
    string $purpose,
    string $stateRoot,
    string $worktreeOption,
    ?TopologyTarget $remaining = null,
): void {
    $issue = $target->issue;
    Assert::assertSame('released', $release['state'] ?? null);
    Assert::assertSame($issue, $release['issue'] ?? null);
    Assert::assertSame($purpose, $release['purpose'] ?? null);
    Assert::assertSame($target->requireAttempt()->value, $release['attempt_id'] ?? null);
    $expected = [];
    foreach (TopologyProfile::ROLES as $role) {
        $expected[] = 'stopped:'.$target->instance($role);
        $expected[] = 'deleted:'.$target->instance($role);
    }
    $expected[] = 'deleted:'.$target->network();
    Assert::assertEqualsCanonicalizing($expected, $release['released'] ?? null);
    Assert::assertSame([], $release['already_absent'] ?? null);
    Assert::assertIsArray($release['networks_reaped'] ?? null);
    foreach (TopologySnapshotIdentity::known() as $topologySnapshot) {
        Assert::assertNotContains($topologySnapshot->network(), $release['networks_reaped']);
    }
    $attemptFile = $purpose === 'proof' ? 'proof-attempt.json' : 'attempt.json';
    $topologyFile = $purpose === 'proof' ? 'proof-topology.json' : 'topology.json';
    Assert::assertFileDoesNotExist("{$stateRoot}/{$attemptFile}");
    Assert::assertFileDoesNotExist("{$stateRoot}/{$topologyFile}");
    $status = LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption);
    if ($remaining === null) {
        Assert::assertSame('absent', $status['state'] ?? null);
    } else {
        Assert::assertSame('discovery', $status['state'] ?? null);
        Assert::assertSame($remaining->requireAttempt()->value, $status['attempt_id'] ?? null);
    }
    LiveHarness::assertIncusAbsent(lifecycleResourceNames($target));
}

/**
 * The promotion verdict: the proved attempt became the topology snapshot generation of
 * the candidate and was released, and nothing of it remains on the host.
 *
 * @param array<array-key, mixed> $promote
 * @param array<array-key, mixed> $initialTopologySnapshot
 * @mago-expect lint:excessive-parameter-list The promotion verdict names every input it checks.
 */
function lifecycleAssertPromotion(
    array $promote,
    TopologyTarget $target,
    TopologyTarget $discovery,
    string $candidateSha,
    array $initialTopologySnapshot,
    string $stateRoot,
    string $worktreeOption,
): string {
    Assert::assertSame('promoted', $promote['state'] ?? null);
    Assert::assertSame($target->issue, $promote['issue'] ?? null);
    Assert::assertSame($target->requireAttempt()->value, $promote['attempt_id'] ?? null);
    Assert::assertSame($candidateSha, $promote['main_sha'] ?? null);
    $generationId = $promote['generation_id'] ?? null;
    Assert::assertIsString($generationId);
    Assert::assertMatchesRegularExpression('/\A[a-f0-9]{12}-[a-f0-9]{12}\z/D', $generationId);
    Assert::assertStringStartsWith(substr($candidateSha, 0, 12), $generationId);
    $initialId = $initialTopologySnapshot['generation']['id'] ?? null;
    Assert::assertSame(
        $generationId === $initialId
            ? $initialTopologySnapshot['generation']['previous_generation_id'] ?? null
            : $initialId,
        $promote['previous_generation_id'] ?? null,
    );
    $expected = [];
    foreach (TopologyProfile::ROLES as $role) {
        $expected[] = 'deleted:'.$target->instance($role);
        $expected[] = 'stopped:'.$discovery->instance($role);
        $expected[] = 'deleted:'.$discovery->instance($role);
    }
    $expected[] = 'deleted:'.$target->network();
    $expected[] = 'deleted:'.$discovery->network();
    Assert::assertEqualsCanonicalizing($expected, $promote['released'] ?? null);
    Assert::assertIsArray($promote['networks_reaped'] ?? null);
    foreach (TopologySnapshotIdentity::known() as $topologySnapshot) {
        Assert::assertNotContains($topologySnapshot->network(), $promote['networks_reaped']);
    }
    Assert::assertFileDoesNotExist("{$stateRoot}/attempt.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/topology.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/proof-attempt.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/proof-topology.json");
    Assert::assertSame(
        'absent',
        LiveHarness::jsonWrapper('topology', 'status', $target->issue, $worktreeOption)['state'] ?? null,
    );
    LiveHarness::assertIncusAbsent([
        ...lifecycleResourceNames($target),
        ...lifecycleResourceNames($discovery),
    ]);

    return $generationId;
}

/** The topology snapshot this checkout owns; the validation clone owns its own, not the primary's. */
function lifecycleTopologySnapshotTarget(): TopologyTarget
{
    return TopologyTarget::topologySnapshot(app(TopologySnapshotIdentity::class));
}

/**
 * Every inventory entry that changed and is this run's to account for.
 *
 * The run must leave the host exactly as it found it, except for its own
 * topology snapshot instances, which promotion replaces. Resources of another issue and
 * of a topology snapshot another checkout owns change while other sessions work; they
 * are not this run's evidence, so they are named and skipped rather than
 * failing the suite.
 *
 * @param array{instances: array<string, array<string, mixed>>, networks: array<string, array<string, mixed>>} $before
 * @param array{instances: array<string, array<string, mixed>>, networks: array<string, array<string, mixed>>} $after
 * @return list<string>
 */
function lifecycleUnexpectedInventoryChanges(array $before, array $after, string $issue): array
{
    $mine = app(TopologySnapshotIdentity::class);
    $replaced = $mine->instances();
    $foreignTopologySnapshot = [];
    foreach (TopologySnapshotIdentity::known() as $topologySnapshot) {
        if ($topologySnapshot->namespace !== $mine->namespace) {
            $foreignTopologySnapshot = [
                ...$foreignTopologySnapshot,
                $topologySnapshot->network(),
                ...$topologySnapshot->instances(),
            ];
        }
    }

    $unexpected = [];
    foreach (['instances', 'networks'] as $kind) {
        foreach (array_keys($before[$kind] + $after[$kind]) as $name) {
            $was = $before[$kind][$name] ?? null;
            $now = $after[$kind][$name] ?? null;
            if ($was === $now || in_array($name, $replaced, true) || in_array($name, $foreignTopologySnapshot, true)) {
                continue;
            }
            $owner = ($now ?? $was)['config']['user.orbit.e2e.issue'] ?? null;
            if (is_string($owner) && $owner !== '' && $owner !== $issue) {
                continue;
            }
            $unexpected[] = $kind.':'.$name;
        }
    }
    sort($unexpected, SORT_STRING);

    return $unexpected;
}
