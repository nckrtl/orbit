<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use PHPUnit\Framework\Assert;
use Tests\Live\Support\LiveHarness;
use Tests\Live\Support\PhaseTimings;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The topology-led lifecycle of one isolated issue, end to end through the
 * public wrappers: a mounted discovery attempt is used, released, and proved
 * as an exact commit on fresh attempts. Every phase asserts the machine-readable
 * evidence the harness records, and cleanup only ever names the exact attempt.
 *
 * @mago-expect lint:cyclomatic-complexity,halstead,kan-defect Live acceptance keeps the ordered evidence chain visible.
 * @mago-expect analysis:non-documented-method,mixed-assignment,mixed-argument,mixed-array-access,mixed-method-access,impossible-condition Pest phase callbacks preserve their concrete runtime values.
 */
it('proves the topology-led lifecycle through public wrappers', function (): void {
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
        'XDG_STATE_HOME',
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
    $stateRoot = rtrim($inputs['XDG_STATE_HOME'], '/').'/orbit/e2e';

    Assert::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $candidateSha);
    Assert::assertSame($candidateSha, LiveHarness::git($featureWorktree, ['rev-parse', '--verify', 'HEAD^{commit}']));
    Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
    $candidateTree = LiveHarness::git($featureWorktree, ['rev-parse', '--verify', 'HEAD^{tree}']);
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
        $initialStandby = LiveHarness::jsonWrapper('standby', 'status');
        Assert::assertSame('promoted', $initialStandby['state'] ?? null);
        Assert::assertTrue($initialStandby['stopped'] ?? false);
        Assert::assertIsArray($initialStandby['generation'] ?? null);
        $initialInventory = LiveHarness::inventoryFingerprint();
        Assert::assertSame('absent', LiveHarness::jsonWrapper('topology', 'status', $issue)['state'] ?? null);
        Assert::assertFileDoesNotExist("{$stateRoot}/leases/{$issue}.json");

        // Phase: acquire discovery on the mounted worktree.
        $acquire = LiveHarness::jsonPhase(
            'acquire discovery',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'acquire',
                $issue,
                $featureWorktree,
            ),
            $timings,
        );
        Assert::assertSame('discovery', $acquire['state'] ?? null);
        $discoveryAttempt = lifecycleAttemptId($acquire);
        $discovery = TopologyTarget::feature($issue, new AttemptId($discoveryAttempt));
        $discoveryManifest = "{$stateRoot}/topologies/{$issue}/{$discoveryAttempt}.json";
        lifecycleAssertTopology($acquire['topology'] ?? null, $discovery, 'discovery', $candidateSha);
        Assert::assertTrue($acquire['topology']['source']['mounted'] ?? false);
        Assert::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string) ($acquire['topology']['source']['git_pointer_sha256'] ?? ''),
        );
        $mounts = $acquire['topology']['mounts'] ?? null;
        Assert::assertIsArray($mounts);
        Assert::assertSame(TopologyProfile::CHECKOUT_ROLES, array_keys($mounts));
        foreach ($mounts as $mount) {
            Assert::assertSame(FeatureTopology::SOURCE_DEVICE, $mount['device'] ?? null);
            Assert::assertSame(realpath($featureWorktree), realpath((string) ($mount['source'] ?? '')));
        }
        Assert::assertSame($acquire['topology'], LiveHarness::jsonFile($discoveryManifest));
        Assert::assertSame(
            ['schema' => 2, 'issue' => $issue, 'attempt' => $discoveryAttempt],
            LiveHarness::jsonFile("{$stateRoot}/topologies/{$issue}/active.json"),
        );
        Assert::assertSame(
            $discoveryAttempt,
            LiveHarness::jsonFile("{$stateRoot}/leases/{$issue}.json")['attempt'] ?? null,
        );
        $timings->mergeJournal(
            'acquire discovery',
            LiveHarness::journalEntries($stateRoot, (string) $acquire['operation_id']),
            'topology.acquire.phases',
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
                fn (): array => LiveHarness::jsonWrapper(
                    'topology',
                    'sync',
                    $issue,
                    $discoveryAttempt,
                    $featureWorktree,
                ),
                $timings,
            );
            Assert::assertSame('ready', $dirty['state'] ?? null);
            Assert::assertSame($discoveryAttempt, $dirty['attempt_id'] ?? null);
            Assert::assertTrue($dirty['source']['dirty'] ?? false);
            Assert::assertSame([$overlayPath], $dirty['source']['overlay_paths'] ?? null);
            Assert::assertMatchesRegularExpression(
                '/\A[a-f0-9]{64}\z/D',
                (string) ($dirty['source']['tree_hash'] ?? ''),
            );
            Assert::assertSame($candidateSha, $dirty['source']['host_sha'] ?? null);
            Assert::assertSame($candidateSha, $dirty['source']['guest_sha'] ?? null);
            Assert::assertTrue($dirty['source']['mounted'] ?? false);
            Assert::assertSame($dirty['source'], LiveHarness::jsonFile($discoveryManifest)['source'] ?? null);
        } finally {
            Assert::assertTrue(unlink($overlayFile));
        }
        Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
        $clean = LiveHarness::jsonPhase(
            'sync clean tree',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'sync',
                $issue,
                $discoveryAttempt,
                $featureWorktree,
            ),
            $timings,
        );
        Assert::assertSame('ready', $clean['state'] ?? null);
        Assert::assertFalse($clean['source']['dirty'] ?? true);
        Assert::assertArrayHasKey('tree_hash', $clean['source']);
        Assert::assertNull($clean['source']['tree_hash']);
        Assert::assertSame([], $clean['source']['overlay_paths'] ?? null);
        Assert::assertSame($clean['source'], LiveHarness::jsonFile($discoveryManifest)['source'] ?? null);

        // Phase: run one arbitrary discovery command as the orbit user.
        $executed = LiveHarness::jsonPhase(
            'exec discovery command',
            fn (): array => lifecycleExec($issue, $discoveryAttempt, $discoveryCommand),
            $timings,
        );
        Assert::assertSame(0, $executed['exit_code'] ?? null);
        Assert::assertIsArray(LiveHarness::json((string) ($executed['stdout'] ?? '')));

        // Phase: release discovery and verify exact absence.
        $discoveryReleased = [];
        foreach (TopologyProfile::ROLES as $role) {
            $discoveryReleased[] = 'stopped:'.$discovery->instance($role);
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $discoveryReleased[] = 'deleted:'.$discovery->instance($role);
            if (in_array($role, TopologyProfile::CHECKOUT_ROLES, true)) {
                $discoveryReleased[] = 'device:'.$discovery->instance($role).':'.FeatureTopology::SOURCE_DEVICE;
            }
        }
        $discoveryReleased[] = 'deleted:'.$discovery->network();
        $release = LiveHarness::jsonPhase(
            'release discovery',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'release',
                $issue,
                $discoveryAttempt,
            ),
            $timings,
        );
        $releasedAttempts[] = $discoveryAttempt;
        lifecycleAssertRelease($release, $discovery, 'discovery', $discoveryReleased, $stateRoot);

        // Phase: prove the exact commit on a new attempt.
        $proved = LiveHarness::jsonPhase(
            'prove candidate',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'prove',
                $issue,
                $featureWorktree,
                "--candidate-sha={$candidateSha}",
                "--proof-plan-file={$proofPlanFile}",
            ),
            $timings,
        );
        Assert::assertSame('proved', $proved['state'] ?? null);
        $proofAttempt = lifecycleAttemptId($proved);
        Assert::assertNotSame($discoveryAttempt, $proofAttempt);
        $proof = TopologyTarget::feature($issue, new AttemptId($proofAttempt));
        lifecycleAssertProof($proved, $proof, 'proved', $candidateSha, $candidateTree, $plan, $stateRoot);
        $timings->mergeJournal(
            'prove candidate',
            LiveHarness::journalEntries($stateRoot, (string) $proved['operation_id']),
            'topology.prove.phases',
        );
        $proofStatus = LiveHarness::jsonWrapper('topology', 'status', $issue);
        Assert::assertSame('proof', $proofStatus['state'] ?? null);
        Assert::assertSame($proofAttempt, $proofStatus['attempt_id'] ?? null);
        lifecycleAssertTopology($proofStatus['topology'] ?? null, $proof, 'proof', $candidateSha);
        Assert::assertSame([], $proofStatus['topology']['mounts'] ?? null);
        Assert::assertFalse($proofStatus['topology']['source']['mounted'] ?? true);
        Assert::assertArrayHasKey('git_pointer_sha256', $proofStatus['topology']['source']);
        Assert::assertNull($proofStatus['topology']['source']['git_pointer_sha256']);
        foreach (TopologyProfile::ROLES as $role) {
            $instance = LiveHarness::incusResource('instance', $proof->instance($role));
            Assert::assertSame('RUNNING', strtoupper((string) ($instance['status'] ?? '')));
            Assert::assertArrayNotHasKey(FeatureTopology::SOURCE_DEVICE, $instance['expanded_devices'] ?? []);
        }
        LiveHarness::assertIncusAbsent(lifecycleResourceNames($discovery));

        // Phase: reject mutation after proof; read-only commands still work.
        LiveHarness::voidPhase(
            'reject mutation after proof',
            function () use ($issue, $proofAttempt, $featureWorktree, $discoveryCommand): void {
                $rejectedSync = LiveHarness::failedJsonWrapper(
                    'topology',
                    'sync',
                    $issue,
                    $proofAttempt,
                    $featureWorktree,
                );
                Assert::assertStringContainsString('proved and cannot be changed', (string) $rejectedSync['error']);
                $argvFile = lifecycleArgvFile($discoveryCommand);
                $rejectedExec = LiveHarness::failedJsonWrapper(
                    'topology',
                    'exec',
                    $issue,
                    $proofAttempt,
                    $discoveryCommand['node'],
                    "--argv-file={$argvFile}",
                );
                Assert::assertStringContainsString('proved and cannot be changed', (string) $rejectedExec['error']);
                $verified = LiveHarness::jsonWrapper('topology', 'verify', $issue, $proofAttempt);
                Assert::assertSame('verified', $verified['state'] ?? null);
                Assert::assertTrue($verified['verification']['passed'] ?? false);
            },
            $timings,
        );
        Assert::assertSame(
            'proved',
            LiveHarness::jsonFile("{$stateRoot}/evidence/proofs/{$issue}/{$proofAttempt}.json")['status'] ?? null,
        );

        // Phase: move the proof to diagnosis, exec again, and release it.
        $diagnosed = LiveHarness::jsonPhase(
            'diagnose proof',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'diagnose',
                $issue,
                $proofAttempt,
            ),
            $timings,
        );
        Assert::assertSame('diagnosis', $diagnosed['state'] ?? null);
        Assert::assertSame($proofAttempt, $diagnosed['attempt_id'] ?? null);
        lifecycleAssertProof($diagnosed, $proof, 'diagnosis', $candidateSha, $candidateTree, $plan, $stateRoot);
        Assert::assertSame(
            array_diff_key($proved['proof'], ['status' => true, 'recorded_at' => true]),
            array_diff_key($diagnosed['proof'], ['status' => true, 'recorded_at' => true]),
        );
        $diagnosisStatus = LiveHarness::jsonWrapper('topology', 'status', $issue, $proofAttempt);
        Assert::assertSame('proof', $diagnosisStatus['state'] ?? null);
        Assert::assertFalse($diagnosisStatus['topology']['verification']['passed'] ?? true);
        $reopened = LiveHarness::jsonPhase(
            'exec after diagnosis',
            fn (): array => lifecycleExec($issue, $proofAttempt, $discoveryCommand),
            $timings,
        );
        Assert::assertSame(0, $reopened['exit_code'] ?? null);
        $diagnosisRelease = LiveHarness::jsonPhase(
            'release diagnosis',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'release',
                $issue,
                $proofAttempt,
            ),
            $timings,
        );
        $releasedAttempts[] = $proofAttempt;
        lifecycleAssertRelease($diagnosisRelease, $proof, 'proof', lifecycleProofReleased($proof), $stateRoot);
        Assert::assertSame(
            $diagnosed['proof'],
            LiveHarness::jsonFile("{$stateRoot}/evidence/proofs/{$issue}/{$proofAttempt}.json"),
        );

        // Phase: prove the same unchanged candidate on another fresh attempt.
        Assert::assertSame($candidateSha, LiveHarness::git($featureWorktree, [
            'rev-parse',
            '--verify',
            'HEAD^{commit}',
        ]));
        Assert::assertSame([], LiveHarness::gitStatus($featureWorktree));
        $reproved = LiveHarness::jsonPhase(
            'prove candidate again',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'prove',
                $issue,
                $featureWorktree,
                "--candidate-sha={$candidateSha}",
                "--proof-plan-file={$proofPlanFile}",
            ),
            $timings,
        );
        Assert::assertSame('proved', $reproved['state'] ?? null);
        $secondProofAttempt = lifecycleAttemptId($reproved);
        Assert::assertNotContains($secondProofAttempt, [$discoveryAttempt, $proofAttempt]);
        $secondProof = TopologyTarget::feature($issue, new AttemptId($secondProofAttempt));
        lifecycleAssertProof($reproved, $secondProof, 'proved', $candidateSha, $candidateTree, $plan, $stateRoot);
        Assert::assertSame($proved['proof']['candidate_tree'], $reproved['proof']['candidate_tree']);
        Assert::assertSame($proved['proof']['guest_script_hash'], $reproved['proof']['guest_script_hash']);
        $timings->mergeJournal(
            'prove candidate again',
            LiveHarness::journalEntries($stateRoot, (string) $reproved['operation_id']),
            'topology.prove.phases',
        );
        LiveHarness::assertIncusAbsent(lifecycleResourceNames($proof));

        // Phase: release the successful proof; its record stays proved.
        $proofRelease = LiveHarness::jsonPhase(
            'release proof',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'release',
                $issue,
                $secondProofAttempt,
            ),
            $timings,
        );
        $releasedAttempts[] = $secondProofAttempt;
        lifecycleAssertRelease($proofRelease, $secondProof, 'proof', lifecycleProofReleased($secondProof), $stateRoot);
        Assert::assertSame(
            $reproved['proof'],
            LiveHarness::jsonFile("{$stateRoot}/evidence/proofs/{$issue}/{$secondProofAttempt}.json"),
        );

        // Phase: standby and unrelated resources are unchanged.
        LiveHarness::voidPhase(
            'verify host unchanged',
            function () use (
                $initialStandby,
                $initialInventory,
                $issue,
                $stateRoot,
                $featureWorktree,
                $candidateSha,
                $mainWorktree,
                $initialMainSha,
            ): void {
                Assert::assertSame($initialStandby, LiveHarness::jsonWrapper('standby', 'status'));
                Assert::assertSame($initialInventory, LiveHarness::inventoryFingerprint());
                Assert::assertSame('absent', LiveHarness::jsonWrapper('topology', 'status', $issue)['state'] ?? null);
                Assert::assertFileDoesNotExist("{$stateRoot}/leases/{$issue}.json");
                Assert::assertFileDoesNotExist("{$stateRoot}/topologies/{$issue}/active.json");
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
            lifecycleCleanup($issue, $releasedAttempts, $overlayFile, $timings);
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
 * Release whatever attempt of the issue is still active, by its exact identity,
 * and prove every attempt this run touched is gone. Nothing is matched by prefix.
 *
 * @param list<string> $releasedAttempts
 */
function lifecycleCleanup(string $issue, array $releasedAttempts, string $overlayFile, PhaseTimings $timings): void
{
    if (file_exists($overlayFile)) {
        Assert::assertTrue(unlink($overlayFile));
    }
    $status = LiveHarness::jsonWrapper('topology', 'status', $issue);
    $activeAttempt = $status['attempt_id'] ?? null;
    if (($status['state'] ?? null) !== 'absent' && is_string($activeAttempt)) {
        $release = LiveHarness::jsonPhase(
            'cleanup release active attempt',
            fn (): array => LiveHarness::jsonWrapper(
                'topology',
                'release',
                $issue,
                $activeAttempt,
            ),
            $timings,
        );
        Assert::assertSame('released', $release['state'] ?? null);
        Assert::assertSame($activeAttempt, $release['attempt_id'] ?? null);
        $releasedAttempts[] = $activeAttempt;
    }
    Assert::assertSame('absent', LiveHarness::jsonWrapper('topology', 'status', $issue)['state'] ?? null);
    $names = [];
    foreach (array_unique($releasedAttempts) as $attempt) {
        $names = [...$names, ...lifecycleResourceNames(TopologyTarget::feature($issue, new AttemptId($attempt)))];
    }
    LiveHarness::assertIncusAbsent($names);
}

/** @param array<array-key, mixed> $payload */
function lifecycleAttemptId(array $payload): string
{
    $attempt = $payload['attempt_id'] ?? null;
    Assert::assertIsString($attempt);
    Assert::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $attempt);
    Assert::assertSame($attempt, $payload['topology']['attempt_id'] ?? $payload['proof']['attempt_id'] ?? null);

    return $attempt;
}

/** @return list<string> */
function lifecycleResourceNames(TopologyTarget $target): array
{
    return [...array_map($target->instance(...), TopologyProfile::ROLES), $target->network()];
}

/** @return list<string> */
function lifecycleProofReleased(TopologyTarget $target): array
{
    $released = [];
    foreach (TopologyProfile::ROLES as $role) {
        $released[] = 'stopped:'.$target->instance($role);
    }
    foreach (array_reverse(TopologyProfile::ROLES) as $role) {
        $released[] = 'deleted:'.$target->instance($role);
    }
    $released[] = 'deleted:'.$target->network();

    return $released;
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
function lifecycleExec(string $issue, string $attempt, array $command): array
{
    $argvFile = lifecycleArgvFile($command);
    $executed = LiveHarness::jsonWrapper(
        'topology',
        'exec',
        $issue,
        $attempt,
        $command['node'],
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
    Assert::assertSame([], $topology['source']['overlay_paths'] ?? null);
}

/**
 * @param array<array-key, mixed> $payload
 * @mago-expect lint:excessive-parameter-list,cyclomatic-complexity The proof record is checked against every input that shaped it.
 */
function lifecycleAssertProof(
    array $payload,
    TopologyTarget $target,
    string $status,
    string $candidateSha,
    string $candidateTree,
    ProofPlan $plan,
    string $stateRoot,
): void {
    $attempt = $target->requireAttempt()->value;
    Assert::assertSame($target->issue, $payload['issue'] ?? null);
    Assert::assertSame($attempt, $payload['attempt_id'] ?? null);
    $record = $payload['proof'] ?? null;
    Assert::assertIsArray($record);
    Assert::assertSame($status, $record['status'] ?? null);
    Assert::assertSame($target->issue, $record['issue'] ?? null);
    Assert::assertSame($attempt, $record['attempt_id'] ?? null);
    Assert::assertSame($candidateSha, $record['candidate_sha'] ?? null);
    Assert::assertSame($candidateTree, $record['candidate_tree'] ?? null);
    Assert::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', (string) ($record['guest_script_hash'] ?? ''));
    Assert::assertSame(TopologyProfile::NAME, $record['profile'] ?? null);
    Assert::assertSame($candidateSha, $record['source']['host_sha'] ?? null);
    Assert::assertSame($candidateSha, $record['source']['guest_sha'] ?? null);
    Assert::assertFalse($record['source']['dirty'] ?? true);
    Assert::assertFalse($record['source']['mounted'] ?? true);
    // The wrapper returns the proof summary: the persisted record minus the
    // plan, and no failed_action once the proof passed.
    Assert::assertArrayNotHasKey('plan', $record);
    Assert::assertArrayNotHasKey('failed_action', $payload);
    Assert::assertSame($plan->postDeploymentActions, $record['post_deployment_actions'] ?? null);
    Assert::assertTrue($record['verification']['passed'] ?? false);
    foreach (['setup' => $plan->setup, 'acceptance' => $plan->acceptance] as $section => $actions) {
        $results = $record["{$section}_results"] ?? null;
        Assert::assertIsArray($results);
        Assert::assertCount(count($actions), $results);
        foreach ($actions as $index => $action) {
            Assert::assertSame($action['id'], $results[$index]['id'] ?? null);
            Assert::assertSame($action['node'], $results[$index]['node'] ?? null);
            Assert::assertSame($action['argv'], $results[$index]['argv'] ?? null);
            Assert::assertSame(0, $results[$index]['exit_code'] ?? null);
        }
    }
    $persisted = LiveHarness::jsonFile("{$stateRoot}/evidence/proofs/{$target->issue}/{$attempt}.json");
    Assert::assertSame(['setup' => $plan->setup, 'acceptance' => $plan->acceptance], $persisted['plan'] ?? null);
    Assert::assertSame($record, array_diff_key($persisted, ['plan' => true]));
}

/**
 * @param array<array-key, mixed> $release
 * @param list<string> $expectedReleased
 */
function lifecycleAssertRelease(
    array $release,
    TopologyTarget $target,
    string $purpose,
    array $expectedReleased,
    string $stateRoot,
): void {
    $issue = $target->issue;
    $attempt = $target->requireAttempt()->value;
    Assert::assertSame('released', $release['state'] ?? null);
    Assert::assertSame($issue, $release['issue'] ?? null);
    Assert::assertSame($attempt, $release['attempt_id'] ?? null);
    Assert::assertSame($purpose, $release['purpose'] ?? null);
    Assert::assertSame($expectedReleased, $release['released'] ?? null);
    Assert::assertSame([], $release['already_absent'] ?? null);
    Assert::assertSame(lifecycleResourceNames($target), $release['verified_absent'] ?? null);
    Assert::assertSame($release, LiveHarness::jsonFile("{$stateRoot}/evidence/releases/{$issue}/{$attempt}.json"));
    Assert::assertFileDoesNotExist("{$stateRoot}/topologies/{$issue}/{$attempt}.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/topologies/{$issue}/active.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/leases/{$issue}.json");
    Assert::assertSame('absent', LiveHarness::jsonWrapper('topology', 'status', $issue)['state'] ?? null);
    LiveHarness::assertIncusAbsent(lifecycleResourceNames($target));
}
