<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use PHPUnit\Framework\Assert;
use Tests\Live\Support\LiveHarness;
use Tests\Live\Support\PhaseTimings;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The simple flow of one isolated issue, end to end through the public
 * wrappers: a mounted discovery attempt is used and released, the worktree
 * HEAD is proved on a fresh attempt, the proved topology refuses mutation and
 * stays alive until release, the same commit proves again, that proved
 * topology is promoted to the standby generation, a discovery attempt clones
 * the promoted generation, and a plan declaring an end state it did not bring
 * about is refused. Every phase asserts the state under `<worktree>/.e2e/`,
 * and cleanup only ever names the exact attempt.
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
        $initialStandby = LiveHarness::jsonWrapper('standby', 'status');
        Assert::assertSame('promoted', $initialStandby['state'] ?? null);
        Assert::assertTrue($initialStandby['stopped'] ?? false);
        Assert::assertIsArray($initialStandby['generation'] ?? null);
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

        // Phase: release discovery and verify exact absence.
        $release = LiveHarness::jsonPhase(
            'release discovery',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption),
            $timings,
        );
        $releasedAttempts[] = $discoveryAttempt;
        lifecycleAssertRelease($release, $discovery, $stateRoot, $worktreeOption);

        // Phase: prove the worktree HEAD on a new attempt.
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
        Assert::assertSame('proof', $proofStatus['state'] ?? null);
        Assert::assertSame($proofAttempt, $proofStatus['attempt_id'] ?? null);
        Assert::assertTrue($proofStatus['proved'] ?? false);
        lifecycleAssertTopology($proofStatus['topology'] ?? null, $proof, 'proof', $candidateSha);
        Assert::assertSame([], $proofStatus['topology']['mounts'] ?? null);
        Assert::assertFalse($proofStatus['topology']['source']['mounted'] ?? true);
        Assert::assertTrue($proofStatus['topology']['verification']['passed'] ?? false);
        foreach (TopologyProfile::ROLES as $role) {
            $instance = LiveHarness::incusResource('instance', $proof->instance($role));
            Assert::assertSame('RUNNING', strtoupper((string) ($instance['status'] ?? '')));
            Assert::assertArrayNotHasKey(FeatureTopology::SOURCE_DEVICE, $instance['expanded_devices'] ?? []);
        }
        LiveHarness::assertIncusAbsent(lifecycleResourceNames($discovery));

        // Phase: a proved topology refuses mutation; read-only commands still work.
        LiveHarness::voidPhase(
            'reject mutation after proof',
            function () use ($issue, $worktreeOption, $discoveryCommand): void {
                $rejectedSync = LiveHarness::failedJsonWrapper('topology', 'sync', $issue, $worktreeOption);
                Assert::assertStringContainsString('is proved; release it', (string) $rejectedSync['error']);
                $argvFile = lifecycleArgvFile($discoveryCommand);
                $rejectedExec = LiveHarness::failedJsonWrapper(
                    'topology',
                    'exec',
                    $issue,
                    $discoveryCommand['node'],
                    $worktreeOption,
                    "--argv-file={$argvFile}",
                );
                Assert::assertStringContainsString('is proved; release it', (string) $rejectedExec['error']);
                $verified = LiveHarness::jsonWrapper('topology', 'verify', $issue, $worktreeOption);
                Assert::assertSame('verified', $verified['state'] ?? null);
                Assert::assertTrue($verified['verification']['passed'] ?? false);
            },
            $timings,
        );

        // Phase: release the proved topology; its result stays in the worktree.
        $proofRelease = LiveHarness::jsonPhase(
            'release proof',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption),
            $timings,
        );
        $releasedAttempts[] = $proofAttempt;
        lifecycleAssertRelease($proofRelease, $proof, $stateRoot, $worktreeOption);
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
        // Phase: promote the proved topology to the standby generation; promote releases it.
        $promote = LiveHarness::jsonPhase(
            'promote proved topology',
            fn (): array => LiveHarness::jsonWrapper(
                'standby',
                'promote',
                $issue,
                $worktreeOption,
                "--plan={$proofPlanFile}",
            ),
            $timings,
        );
        $releasedAttempts[] = $secondProofAttempt;
        $generationId = lifecycleAssertPromotion(
            $promote,
            $secondProof,
            $candidateSha,
            $initialStandby,
            $stateRoot,
            $worktreeOption,
        );
        Assert::assertSame($reproved, LiveHarness::jsonFile("{$stateRoot}/proof.json"));
        $promotedStandby = LiveHarness::jsonWrapper('standby', 'status');
        Assert::assertSame('promoted', $promotedStandby['state'] ?? null);
        Assert::assertTrue($promotedStandby['stopped'] ?? false);
        Assert::assertSame($generationId, $promotedStandby['generation']['id'] ?? null);
        Assert::assertSame($candidateSha, $promotedStandby['generation']['main_sha'] ?? null);
        Assert::assertSame(
            $initialStandby['generation']['laravel_pin'] ?? null,
            $promotedStandby['generation']['laravel_pin'] ?? null,
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
        lifecycleAssertRelease($promotedRelease, $promotedDiscovery, $stateRoot, $worktreeOption);

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
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption),
            $timings,
        );
        $releasedAttempts[] = $declaredAttempt;
        lifecycleAssertRelease($declaredRelease, $declaredTopology, $stateRoot, $worktreeOption);

        // Phase: only the standby changed, and only by promotion.
        LiveHarness::voidPhase(
            'verify host',
            function () use (
                $promotedStandby,
                $initialInventory,
                $issue,
                $stateRoot,
                $worktreeOption,
                $featureWorktree,
                $candidateSha,
                $mainWorktree,
                $initialMainSha,
            ): void {
                Assert::assertSame($promotedStandby, LiveHarness::jsonWrapper('standby', 'status'));
                Assert::assertSame(
                    [],
                    lifecycleUnexpectedInventoryChanges(
                        $initialInventory,
                        LiveHarness::inventoryFingerprint(),
                        $issue,
                    ),
                );
                $standby = lifecycleStandbyTarget();
                foreach (TopologyProfile::ROLES as $role) {
                    $instance = LiveHarness::incusResource('instance', $standby->instance($role));
                    Assert::assertSame('STOPPED', strtoupper((string) ($instance['status'] ?? '')));
                    Assert::assertSame(
                        $standby->network(),
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
 * Release whatever attempt of the issue is still live, and prove every attempt
 * this run touched is gone. Nothing is matched by prefix.
 *
 * @param list<string> $releasedAttempts
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
    $activeAttempt = $status['attempt_id'] ?? null;
    if (($status['state'] ?? null) !== 'absent' && is_string($activeAttempt)) {
        $release = LiveHarness::jsonPhase(
            'cleanup release active attempt',
            fn (): array => LiveHarness::jsonWrapper('topology', 'release', $issue, $worktreeOption),
            $timings,
        );
        Assert::assertSame('released', $release['state'] ?? null);
        Assert::assertSame($activeAttempt, $release['attempt_id'] ?? null);
        $releasedAttempts[] = $activeAttempt;
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
    Assert::assertArrayNotHasKey('failed_action', $payload);
    Assert::assertArrayNotHasKey('error', $payload);
    $expected = [];
    foreach ([...$plan->setup, ...$plan->acceptance] as $action) {
        $expected[] = ['id' => $action['id'], 'node' => $action['node'], 'exit_code' => 0];
    }
    Assert::assertSame($expected, $payload['actions'] ?? null);
    Assert::assertSame($payload, LiveHarness::jsonFile("{$stateRoot}/proof.json"));

    return $attempt;
}

/** @param array<array-key, mixed> $release */
function lifecycleAssertRelease(array $release, TopologyTarget $target, string $stateRoot, string $worktreeOption): void
{
    $issue = $target->issue;
    Assert::assertSame('released', $release['state'] ?? null);
    Assert::assertSame($issue, $release['issue'] ?? null);
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
    foreach (StandbyIdentity::known() as $standby) {
        Assert::assertNotContains($standby->network(), $release['networks_reaped']);
    }
    Assert::assertFileDoesNotExist("{$stateRoot}/attempt.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/topology.json");
    Assert::assertSame(
        'absent',
        LiveHarness::jsonWrapper('topology', 'status', $issue, $worktreeOption)['state'] ?? null,
    );
    LiveHarness::assertIncusAbsent(lifecycleResourceNames($target));
}

/**
 * The promotion verdict: the proved attempt became the standby generation of
 * the candidate and was released, and nothing of it remains on the host.
 *
 * @param array<array-key, mixed> $promote
 * @param array<array-key, mixed> $initialStandby
 * @mago-expect lint:excessive-parameter-list The promotion verdict names every input it checks.
 */
function lifecycleAssertPromotion(
    array $promote,
    TopologyTarget $target,
    string $candidateSha,
    array $initialStandby,
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
    $initialId = $initialStandby['generation']['id'] ?? null;
    Assert::assertSame(
        $generationId === $initialId ? $initialStandby['generation']['previous_generation_id'] ?? null : $initialId,
        $promote['previous_generation_id'] ?? null,
    );
    $expected = [];
    foreach (TopologyProfile::ROLES as $role) {
        $expected[] = 'deleted:'.$target->instance($role);
    }
    $expected[] = 'deleted:'.$target->network();
    Assert::assertEqualsCanonicalizing($expected, $promote['released'] ?? null);
    Assert::assertIsArray($promote['networks_reaped'] ?? null);
    foreach (StandbyIdentity::known() as $standby) {
        Assert::assertNotContains($standby->network(), $promote['networks_reaped']);
    }
    Assert::assertFileDoesNotExist("{$stateRoot}/attempt.json");
    Assert::assertFileDoesNotExist("{$stateRoot}/topology.json");
    Assert::assertSame(
        'absent',
        LiveHarness::jsonWrapper('topology', 'status', $target->issue, $worktreeOption)['state'] ?? null,
    );
    LiveHarness::assertIncusAbsent(lifecycleResourceNames($target));

    return $generationId;
}

/** The standby this checkout owns; the validation clone owns its own, not the primary's. */
function lifecycleStandbyTarget(): TopologyTarget
{
    return TopologyTarget::standby(app(StandbyIdentity::class));
}

/**
 * Every inventory entry that changed and is this run's to account for.
 *
 * The run must leave the host exactly as it found it, except for its own
 * standby instances, which promotion replaces. Resources of another issue and
 * of a standby another checkout owns change while other sessions work; they
 * are not this run's evidence, so they are named and skipped rather than
 * failing the suite.
 *
 * @param array{instances: array<string, array<string, mixed>>, networks: array<string, array<string, mixed>>} $before
 * @param array{instances: array<string, array<string, mixed>>, networks: array<string, array<string, mixed>>} $after
 * @return list<string>
 */
function lifecycleUnexpectedInventoryChanges(array $before, array $after, string $issue): array
{
    $mine = app(StandbyIdentity::class);
    $replaced = $mine->instances();
    $foreignStandby = [];
    foreach (StandbyIdentity::known() as $standby) {
        if ($standby->namespace !== $mine->namespace) {
            $foreignStandby = [...$foreignStandby, $standby->network(), ...$standby->instances()];
        }
    }

    $unexpected = [];
    foreach (['instances', 'networks'] as $kind) {
        foreach (array_keys($before[$kind] + $after[$kind]) as $name) {
            $was = $before[$kind][$name] ?? null;
            $now = $after[$kind][$name] ?? null;
            if ($was === $now || in_array($name, $replaced, true) || in_array($name, $foreignStandby, true)) {
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
