#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRequest;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
$autoload = '/home/orbit/orbit/apps/e2e/vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname(__DIR__, 2).'/apps/e2e/vendor/autoload.php';
}
require $autoload;

/** Throw one concrete failed observation through owned-resource cleanup. */
function failFixture(string $message): never
{
    throw new RuntimeException($message);
}

/** Run one command and require success. */
function runFixtureCommand(array $command): string
{
    $result = Process::run($command);
    if ($result->failed()) {
        failFixture('Fixture command failed: '.implode(' ', $command));
    }

    return trim($result->output());
}

/** Remove only the fixture-owned temporary tree. */
function removeFixtureTree(string $root): void
{
    if (! str_starts_with($root, sys_get_temp_dir().'/orb-153-proof-')) {
        failFixture('The fixture cleanup root is invalid.');
    }
    if (! file_exists($root) && ! is_link($root)) {
        return;
    }
    if (! is_dir($root)) {
        unlink($root);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $entry->isDir() && ! $entry->isLink() ? rmdir($path) : unlink($path);
    }
    rmdir($root);
}

$container = new Container;
$container->instance(ProcessFactory::class, new ProcessFactory);
Facade::clearResolvedInstances();
Facade::setFacadeApplication($container);

$temporary = tempnam(sys_get_temp_dir(), 'orb-153-proof-');
if ($temporary === false) {
    fwrite(STDERR, "Unable to allocate the fixture root.\n");

    exit(1);
}

$failure = null;
$result = null;
try {
    if (! unlink($temporary) || ! mkdir($temporary, 0700)) {
        failFixture('Unable to create the fixture root.');
    }
    $worktree = $temporary.'/worktree';
    $hostRoot = $temporary.'/host';
    $binaryRoot = $temporary.'/bin';
    mkdir($worktree, 0700);
    mkdir($binaryRoot, 0700);
    $incusMarker = $temporary.'/incus-called';
    $sentinel = "#!/bin/sh\nprintf '%s\\n' called > ".escapeshellarg($incusMarker)."\nexit 97\n";
    file_put_contents($binaryRoot.'/incus', $sentinel);
    chmod($binaryRoot.'/incus', 0700);
    putenv('PATH='.$binaryRoot.':'.getenv('PATH'));

    file_put_contents($worktree.'/.gitignore', "/.e2e/\n");
    runFixtureCommand(['git', '-C', $worktree, 'init', '--quiet', '-b', 'orb-153-proof']);
    runFixtureCommand(['git', '-C', $worktree, 'config', 'user.email', 'orbit@example.test']);
    runFixtureCommand(['git', '-C', $worktree, 'config', 'user.name', 'Orbit proof']);
    runFixtureCommand(['git', '-C', $worktree, 'add', '.gitignore']);
    runFixtureCommand(['git', '-C', $worktree, 'commit', '--quiet', '-m', 'proof fixture']);

    $issue = 'ORB-153';
    $captured = new AttemptId(str_repeat('a', 32));
    $replacement = new AttemptId(str_repeat('b', 32));
    $absent = new AttemptId(str_repeat('c', 32));
    $state = IssueState::forWorktree($issue, $worktree);
    $state->writeAttempt($replacement, AttemptPurpose::Proof, new OperationId(str_repeat('d', 32)));
    $topologyPath = $worktree.'/.e2e/'.IssueState::PROOF_TOPOLOGY;
    file_put_contents($topologyPath, json_encode([
        'purpose' => AttemptPurpose::Proof->value,
        'attempt_id' => $replacement->value,
        'fixture' => 'replacement-state-must-remain-byte-identical',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    $state->writeProof([
        'status' => 'proved',
        'attempt_id' => $captured->value,
        'manifest_sha256' => str_repeat('e', 64),
    ]);

    $repository = new GitRepository($worktree);
    $proved = $repository->commit();
    $repository->pinProof($issue, $captured, $proved);
    $leasePath = $worktree.'/.e2e/'.IssueState::PROOF_ATTEMPT;
    $proofPath = $worktree.'/.e2e/'.IssueState::PROOF;
    $preserved = [
        $leasePath => file_get_contents($leasePath),
        $topologyPath => file_get_contents($topologyPath),
        $proofPath => file_get_contents($proofPath),
    ];

    $hostPaths = new StatePaths($hostRoot);
    $host = new IncusHost;
    $releaser = new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        $hostPaths,
        new OperationId(str_repeat('f', 32)),
    );
    $request = new TopologyRequest($issue, $worktree);

    try {
        $releaser->releaseExact($request, [AttemptPurpose::Proof->value => $captured]);
        failFixture('The replaced captured attempt was released.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), "was replaced by {$replacement->value}")) {
            throw $exception;
        }
    }

    try {
        $releaser->releaseExact($request, [AttemptPurpose::Discovery->value => $absent]);
        failFixture('The absent captured attempt was accepted.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), "Captured discovery attempt {$absent->value} is absent")) {
            throw $exception;
        }
    }

    foreach ($preserved as $path => $bytes) {
        if ($bytes === false || file_get_contents($path) !== $bytes) {
            failFixture("Captured refusal changed {$path}.");
        }
    }
    if (file_exists($incusMarker)) {
        failFixture('Exact refusal executed Incus.');
    }
    runFixtureCommand([
        'git',
        '-C',
        $worktree,
        'show-ref',
        '--verify',
        'refs/orbit/e2e-proof/orb-153/'.$captured->value,
    ]);
    $lock = new OperationLock($hostPaths);
    if (! $lock->acquire('topology-'.$issue, new OperationId(str_repeat('1', 32)))) {
        failFixture('Exact refusal did not release the issue lock.');
    }
    $lock->release();

    $result = [
        'state' => 'passed',
        'replaced_attempt' => 'refused',
        'absent_attempt' => 'refused',
        'incus_commands' => 0,
        'replacement_state' => 'preserved',
        'proof_ref' => 'preserved',
        'issue_lock' => 'released',
    ];
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        removeFixtureTree($temporary);
    } catch (Throwable $cleanupException) {
        $failure = $failure === null
            ? $cleanupException
            : new RuntimeException(
                $failure->getMessage().' Fixture cleanup also failed: '.$cleanupException->getMessage(),
                previous: $failure,
            );
    }
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage()."\n");

    exit(1);
}
if ($result === null) {
    fwrite(STDERR, "The fixture completed without a result.\n");

    exit(1);
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
