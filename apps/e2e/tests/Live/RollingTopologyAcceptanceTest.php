<?php

declare(strict_types=1);

use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class);

it('rejects an unknown public E2E wrapper action outside the repository', function (): void {
    $root = dirname(__DIR__, 4);
    $outside = sys_get_temp_dir().'/orbit-e2e-wrapper-'.bin2hex(random_bytes(8));
    mkdir($outside, 0700);

    foreach (['legacy', 'standby', 'topology'] as $tool) {
        $wrapper = $root.'/bin/e2e-'.$tool;
        Assert::assertFileExists($wrapper);
        $result = Process::path($outside)->run([$wrapper, 'invalid-action']);

        Assert::assertSame(64, $result->exitCode());
        Assert::assertStringContainsString('unknown '.$tool.' action: invalid-action', $result->errorOutput());
    }
});

/** @mago-expect lint:cyclomatic-complexity,halstead,kan-defect Live acceptance keeps the ordered evidence chain visible. */
/** @mago-expect analysis:non-documented-method,mixed-assignment,mixed-argument,mixed-array-access,mixed-method-access,impossible-condition Pest phase callbacks preserve their concrete runtime values. */
it('proves the rolling topology contract through public wrappers', function (): void {
    if (getenv('ORBIT_LIVE_INCUS') !== '1') {
        test()->markTestSkipped('Set ORBIT_LIVE_INCUS=1 to run.');
    }

    $inputs = liveInputs([
        'ORBIT_LIVE_PROFILE',
        'ORBIT_LIVE_ISSUE',
        'ORBIT_LIVE_MAIN_WORKTREE',
        'ORBIT_LIVE_FEATURE_WORKTREE',
        'ORBIT_LIVE_CANDIDATE_SHA',
        'ORBIT_LIVE_BASE_SHA',
        'ORBIT_LIVE_ROLLING_SHA',
        'ORBIT_LIVE_FAILURE_SHA',
        'ORBIT_LIVE_FAILURE_MIGRATION',
        'XDG_STATE_HOME',
    ]);
    Assert::assertSame(TopologyProfile::NAME, $inputs['ORBIT_LIVE_PROFILE']);
    $repositoryRoot = dirname(__DIR__, 4);
    Assert::assertSame(realpath($repositoryRoot), realpath($inputs['ORBIT_LIVE_MAIN_WORKTREE']));
    Assert::assertNotSame(realpath($repositoryRoot), realpath($inputs['ORBIT_LIVE_FEATURE_WORKTREE']));

    $issue = $inputs['ORBIT_LIVE_ISSUE'];
    $target = new TopologyTarget($issue);
    $mainWorktree = $inputs['ORBIT_LIVE_MAIN_WORKTREE'];
    $featureWorktree = $inputs['ORBIT_LIVE_FEATURE_WORKTREE'];
    $candidateSha = $inputs['ORBIT_LIVE_CANDIDATE_SHA'];
    $baseSha = $inputs['ORBIT_LIVE_BASE_SHA'];
    $rollingSha = $inputs['ORBIT_LIVE_ROLLING_SHA'];
    $failureSha = $inputs['ORBIT_LIVE_FAILURE_SHA'];
    $stateRoot = rtrim($inputs['XDG_STATE_HOME'], '/').'/orbit/e2e';
    $migrationFile = $inputs['ORBIT_LIVE_FAILURE_MIGRATION'];

    foreach ([$candidateSha, $baseSha, $rollingSha, $failureSha] as $sha) {
        Assert::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $sha);
    }
    Assert::assertSame($candidateSha, liveGit($featureWorktree, ['rev-parse', '--verify', 'HEAD^{commit}']));
    Assert::assertSame([], liveGitStatus($featureWorktree));
    foreach ([$baseSha, $rollingSha, $failureSha] as $sha) {
        Assert::assertSame($sha, liveGit($mainWorktree, ['rev-parse', '--verify', "{$sha}^{commit}"]));
    }
    Assert::assertNotSame($baseSha, $rollingSha);
    Assert::assertNotSame($rollingSha, $failureSha);
    $migration = liveJsonFile($migrationFile);
    Assert::assertSame(['fingerprint', 'steps'], array_keys($migration));
    Assert::assertSame([[
        'role' => 'gateway',
        'argv' => ['/bin/false'],
        'stdin' => '',
    ]], $migration['steps']);

    $initialMainSha = liveGit($mainWorktree, ['rev-parse', '--verify', 'HEAD^{commit}']);
    $acquired = false;
    $primaryFailure = null;

    try {
        $initial = liveJsonPhase('standby status', fn (): array => liveJsonWrapper('standby', 'status'));
        Assert::assertContains($initial['state'] ?? null, ['missing', 'promoted']);
        Assert::assertIsBool($initial['stopped'] ?? null);
        Assert::assertSame($initial['state'] === 'missing', $initial['generation'] === null);

        if ($initial['state'] === 'missing') {
            liveCheckout($mainWorktree, $baseSha);
            $bootstrap = liveJsonPhase('bootstrap standby generation', fn (): array => liveJsonWrapper(
                'standby',
                'refresh',
                "--main-sha={$baseSha}",
                '--allow-cold',
            ));
            liveAssertRefresh($bootstrap, 'promoted');
        }

        $standby = liveJsonWrapper('standby', 'status');
        liveAssertStandbyStatus($standby, 'promoted');
        Assert::assertSame($baseSha, $standby['generation']['main_sha'] ?? null);
        $acquire = liveJsonPhase('acquire topology', fn (): array => liveJsonWrapper(
            'topology',
            'acquire',
            $issue,
            $featureWorktree,
        ));
        $acquired = true;
        Assert::assertSame('ready', $acquire['state'] ?? null);
        Assert::assertSame($acquire['operation_id'] ?? null, $acquire['evidence_id'] ?? null);
        liveAssertTopology($acquire['topology'] ?? null, $target, $candidateSha);

        $manifestPath = "{$stateRoot}/topologies/{$issue}.json";
        Assert::assertSame($acquire['topology'], liveJsonFile($manifestPath));
        liveVoidPhase('verify services', function () use ($issue): void {
            $verified = liveJsonWrapper('topology', 'verify', $issue);
            Assert::assertSame('verified', $verified['state'] ?? null);
            Assert::assertTrue($verified['verification']['passed'] ?? false);
            Assert::assertNotContains(false, $verified['verification']['probes'] ?? []);
        });

        $overlayPath = 'apps/e2e/.live-overlay-'.$issue.'.txt';
        $overlayFile = $featureWorktree.'/'.$overlayPath;
        Assert::assertFileDoesNotExist($overlayFile);
        file_put_contents($overlayFile, "harmless live overlay\n", LOCK_EX);
        try {
            $dirty = liveJsonPhase('dirty source sync', fn (): array => liveJsonWrapper(
                'topology',
                'sync',
                $issue,
                $featureWorktree,
            ));
            Assert::assertSame('ready', $dirty['state'] ?? null);
            $dirtyManifest = liveJsonFile($manifestPath);
            Assert::assertTrue($dirtyManifest['source']['dirty'] ?? false);
            Assert::assertSame([$overlayPath], $dirtyManifest['source']['overlay_paths'] ?? null);
            Assert::assertMatchesRegularExpression(
                '/\A[a-f0-9]{64}\z/D',
                (string) ($dirtyManifest['source']['tree_hash'] ?? ''),
            );
            Assert::assertSame($candidateSha, $dirtyManifest['source']['host_sha'] ?? null);
            Assert::assertSame($candidateSha, $dirtyManifest['source']['guest_sha'] ?? null);
            Assert::assertSame($dirty['operation_id'] ?? null, $dirtyManifest['source']['operation_id'] ?? null);
        } finally {
            Assert::assertTrue(unlink($overlayFile));
        }
        Assert::assertSame([], liveGitStatus($featureWorktree));

        $clean = liveJsonPhase('clean source sync', fn (): array => liveJsonWrapper(
            'topology',
            'sync',
            $issue,
            $featureWorktree,
        ));
        $cleanManifest = liveJsonFile($manifestPath);
        Assert::assertSame('ready', $clean['state'] ?? null);
        Assert::assertFalse($cleanManifest['source']['dirty'] ?? true);
        Assert::assertNull($cleanManifest['source']['tree_hash'] ?? 'missing');
        Assert::assertSame([], $cleanManifest['source']['overlay_paths'] ?? null);
        Assert::assertSame($candidateSha, $cleanManifest['source']['host_sha'] ?? null);
        Assert::assertSame($candidateSha, $cleanManifest['source']['guest_sha'] ?? null);

        $proof = liveJsonPhase('prove exact candidate', fn (): array => liveJsonWrapper(
            'topology',
            'prove',
            $issue,
            $featureWorktree,
            "--candidate-sha={$candidateSha}",
        ));
        Assert::assertSame('proved', $proof['state'] ?? null);
        Assert::assertSame($candidateSha, $proof['candidate_sha'] ?? null);
        Assert::assertSame(
            liveGit($featureWorktree, ['rev-parse', '--verify', 'HEAD^{tree}']),
            $proof['candidate_tree'] ?? null,
        );
        Assert::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', (string) ($proof['tree_hash'] ?? ''));
        Assert::assertTrue($proof['verification']['passed'] ?? false);
        Assert::assertSame($proof, liveJsonFile("{$stateRoot}/proof/{$issue}.json"));

        liveCheckout($mainWorktree, $rollingSha);
        $rollingFingerprint = liveJsonWrapper('standby', 'fingerprint', "--main-sha={$rollingSha}");
        $rolling = liveJsonPhase('rolling standby refresh', fn (): array => liveJsonWrapper(
            'standby',
            'refresh',
            "--main-sha={$rollingSha}",
        ));
        liveAssertRefresh($rolling, 'promoted');
        Assert::assertNotSame($standby['generation']['id'] ?? null, $rolling['generation_id']);
        $promoted = liveJsonWrapper('standby', 'status');
        liveAssertStandbyStatus($promoted, 'promoted');
        Assert::assertSame($rolling['generation_id'], $promoted['generation']['id'] ?? null);
        Assert::assertSame($rollingSha, $promoted['generation']['main_sha'] ?? null);
        Assert::assertSame(
            $rollingFingerprint['fingerprint'] ?? null,
            $promoted['generation']['prepared_fingerprint'] ?? null,
        );

        liveCheckout($mainWorktree, $failureSha);
        $failureFingerprint = liveJsonWrapper('standby', 'fingerprint', "--main-sha={$failureSha}");
        Assert::assertNotSame($rollingFingerprint['fingerprint'] ?? null, $failureFingerprint['fingerprint'] ?? null);
        Assert::assertSame($failureFingerprint['fingerprint'] ?? null, $migration['fingerprint'] ?? null);
        $failedResult = liveProcessPhase('injected migration failure', fn (): ProcessResult => liveWrapper(
            'standby',
            'refresh',
            "--main-sha={$failureSha}",
            "--migration-file={$migrationFile}",
        ));
        Assert::assertFalse($failedResult->successful());
        $failed = liveJson($failedResult->output());
        liveAssertRefresh($failed, 'failed');
        Assert::assertSame($rolling['generation_id'], $failed['generation_id'] ?? null);
        $failureEvidence = liveJsonFile("{$stateRoot}/standby/failures/{$failed['evidence_id']}.json");
        Assert::assertSame(1, $failureEvidence['schema'] ?? null);
        Assert::assertSame($failureSha, $failureEvidence['main_sha'] ?? null);
        Assert::assertSame('A standby migration step failed.', $failureEvidence['message'] ?? null);
        Assert::assertSame([
            'schema' => 1,
            'recovered' => true,
            'stopped' => true,
            'generation_id' => $rolling['generation_id'],
        ], liveJsonFile("{$stateRoot}/standby/recovery/{$failed['evidence_id']}.json"));
        $recovered = liveJsonWrapper('standby', 'status');
        liveAssertStandbyStatus($recovered, 'promoted');
        Assert::assertSame($rolling['generation_id'], $recovered['generation']['id'] ?? null);

        liveCheckout($mainWorktree, $rollingSha);
        $unchanged = liveJsonPhase('no-op standby refresh', fn (): array => liveJsonWrapper(
            'standby',
            'refresh',
            "--main-sha={$rollingSha}",
        ));
        liveAssertRefresh($unchanged, 'unchanged');
        Assert::assertSame($rolling['generation_id'], $unchanged['generation_id']);
        $unchangedStatus = liveJsonWrapper('standby', 'status');
        liveAssertStandbyStatus($unchangedStatus, 'promoted');
        Assert::assertSame($rolling['generation_id'], $unchangedStatus['generation']['id'] ?? null);

        $lease = liveJsonFile("{$stateRoot}/leases/{$issue}.json");
        $sourceOperations = $lease['source_operation_ids'] ?? null;
        Assert::assertIsArray($sourceOperations);
        Assert::assertNotSame([], $sourceOperations);
        $expectedReleased = [];
        foreach (TopologyProfile::ROLES as $role) {
            foreach ($sourceOperations as $operation) {
                $expectedReleased[] = "source:{$target->instance($role)}:{$operation}";
            }
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $expectedReleased[] = 'stopped:'.$target->instance($role);
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $expectedReleased[] = 'deleted:'.$target->instance($role);
        }
        $expectedReleased[] = 'deleted:'.$target->network();

        $release = liveJsonPhase('release exact topology', fn (): array => liveJsonWrapper(
            'topology',
            'release',
            $issue,
        ));
        Assert::assertSame('released', $release['state'] ?? null);
        Assert::assertSame($expectedReleased, $release['released'] ?? null);
        Assert::assertSame([], $release['already_absent'] ?? null);
        Assert::assertSame($release, liveJsonFile("{$stateRoot}/releases/{$issue}.json"));
        Assert::assertFileDoesNotExist($manifestPath);
        Assert::assertFileDoesNotExist("{$stateRoot}/leases/{$issue}.json");
        $repeated = liveJsonWrapper('topology', 'release', $issue);
        Assert::assertSame('released', $repeated['state'] ?? null);
        Assert::assertSame([], $repeated['released'] ?? null);
        Assert::assertSame($expectedReleased, $repeated['already_absent'] ?? null);
        Assert::assertSame($release['evidence_id'] ?? null, $repeated['evidence_id'] ?? null);
        Assert::assertNotSame($release['operation_id'] ?? null, $repeated['operation_id'] ?? null);
        $acquired = false;
    } catch (Throwable $exception) {
        $primaryFailure = $exception;
    } finally {
        $cleanupFailure = null;
        if ($acquired) {
            try {
                $cleanup = liveProcessPhase('cleanup topology release', fn (): ProcessResult => liveWrapper(
                    'topology',
                    'release',
                    $issue,
                ));
                Assert::assertTrue($cleanup->successful(), $cleanup->errorOutput() ?: $cleanup->output());
            } catch (Throwable $exception) {
                $cleanupFailure = $exception;
            }
        }
        try {
            liveCheckout($mainWorktree, $initialMainSha);
        } catch (Throwable $exception) {
            $cleanupFailure ??= $exception;
        }
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

/** @param list<string> $names
 * @return array<string, string>
 */
function liveInputs(array $names): array
{
    $inputs = [];
    foreach ($names as $name) {
        $value = getenv($name);
        Assert::assertNotFalse($value, "Missing required live input: {$name}");
        Assert::assertNotSame('', $value, "Missing required live input: {$name}");
        $inputs[$name] = $value;
    }

    return $inputs;
}

function liveWrapper(string $tool, string $action, string ...$arguments): ProcessResult
{
    return Process::timeout(3_600)->run([dirname(__DIR__, 4).'/bin/e2e-'.$tool, $action, ...$arguments, '--json']);
}

/** @return array<array-key, mixed> */
function liveJsonWrapper(string $tool, string $action, string ...$arguments): array
{
    $result = liveWrapper($tool, $action, ...$arguments);
    Assert::assertTrue($result->successful(), $result->errorOutput() ?: $result->output());

    return liveJson($result->output());
}

/** @return array<array-key, mixed> */
/** @mago-expect analysis:mixed-assignment Decoded JSON is asserted as an array immediately. */
function liveJson(string $json): array
{
    $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    Assert::assertIsArray($value);

    return $value;
}

/** @return array<array-key, mixed> */
function liveJsonFile(string $path): array
{
    Assert::assertFileExists($path);

    return liveJson((string) file_get_contents($path));
}

/** @param callable(): array<array-key, mixed> $action
 * @return array<array-key, mixed>
 */
function liveJsonPhase(string $name, callable $action): array
{
    $started = microtime(true);
    try {
        return $action();
    } finally {
        /** @mago-expect analysis:non-documented-method Pest exposes notes through its test proxy. */
        test()->note(sprintf('%s: %.3fs', $name, microtime(true) - $started));
    }
}

/** @param callable(): ProcessResult $action */
function liveProcessPhase(string $name, callable $action): ProcessResult
{
    $started = microtime(true);
    try {
        return $action();
    } finally {
        test()->note(sprintf('%s: %.3fs', $name, microtime(true) - $started));
    }
}

/** @param callable(): void $action */
function liveVoidPhase(string $name, callable $action): void
{
    $started = microtime(true);
    try {
        $action();
    } finally {
        test()->note(sprintf('%s: %.3fs', $name, microtime(true) - $started));
    }
}

/** @param list<string> $arguments */
function liveGit(string $worktree, array $arguments): string
{
    $result = Process::path($worktree)->run(['git', ...$arguments]);
    Assert::assertTrue($result->successful(), $result->errorOutput() ?: $result->output());

    return strtolower(trim($result->output()));
}

/** @return list<string> */
function liveGitStatus(string $worktree): array
{
    $status = liveGit($worktree, ['status', '--porcelain=v1', '--untracked-files=all']);

    return $status === '' ? [] : explode("\n", $status);
}

function liveCheckout(string $worktree, string $sha): void
{
    Assert::assertSame([], liveGitStatus($worktree));
    Assert::assertSame('', liveGit($worktree, ['checkout', '--quiet', '--detach', $sha]));
    Assert::assertSame($sha, liveGit($worktree, ['rev-parse', '--verify', 'HEAD^{commit}']));
}

/** @param array<array-key, mixed> $payload */
/** @mago-expect analysis:mixed-argument Exact scalar schemas are asserted before use by the live process. */
function liveAssertRefresh(array $payload, string $state): void
{
    Assert::assertSame(['state', 'operation_id', 'evidence_id', 'generation_id'], array_keys($payload));
    Assert::assertSame($state, $payload['state']);
    Assert::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $payload['operation_id']);
    Assert::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $payload['evidence_id']);
    Assert::assertIsString($payload['generation_id']);
}

/** @param array<array-key, mixed> $payload */
function liveAssertStandbyStatus(array $payload, string $state): void
{
    Assert::assertSame(['state', 'stopped', 'generation'], array_keys($payload));
    Assert::assertSame($state, $payload['state']);
    Assert::assertTrue($payload['stopped']);
    Assert::assertIsArray($payload['generation']);
}

function liveAssertTopology(mixed $topology, TopologyTarget $target, string $candidateSha): void
{
    Assert::assertIsArray($topology);
    Assert::assertSame(1, $topology['schema'] ?? null);
    Assert::assertSame($target->issue, $topology['issue'] ?? null);
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
    Assert::assertTrue($topology['verification']['passed'] ?? false);
}
