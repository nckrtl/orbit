#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\IssueState;
use App\E2E\State\StatePaths;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyRequest;

require '/home/orbit/orbit/apps/e2e/vendor/autoload.php';

$root = sys_get_temp_dir().'/orb-167-proof-'.bin2hex(random_bytes(8));
$worktree = $root.'/worktree';
$hostRoot = $root.'/host';
mkdir($worktree, 0700, true);
mkdir($hostRoot, 0700, true);

try {
    $attempt = new AttemptId(str_repeat('a', 32));
    $operation = new OperationId(str_repeat('b', 32));
    $state = IssueState::forWorktree('ORB-167', $worktree);
    $state->writeAttempt($attempt, AttemptPurpose::Discovery, $operation, TopologyExtension::AppProd);
    $lease = json_decode(
        (string) file_get_contents($worktree.'/.e2e/'.IssueState::ATTEMPT),
        true,
        8,
        JSON_THROW_ON_ERROR,
    );
    if (($lease['extension'] ?? null) !== 'app-prod') {
        throw new RuntimeException('The initial lease did not retain app-prod.');
    }

    unlink($worktree.'/.e2e/'.IssueState::ATTEMPT);
    file_put_contents($worktree.'/.e2e/'.IssueState::ATTEMPT, json_encode([
        'issue' => 'ORB-167',
        'attempt_id' => $attempt->value,
        'purpose' => AttemptPurpose::Discovery->value,
        'operation_id' => $operation->value,
        'acquired_at' => '2026-09-09T00:00:00Z',
    ], JSON_THROW_ON_ERROR));

    $host = new IncusHost;
    $releaser = new TopologyReleaser(
        $host,
        new IncusNetworkLifecycle($host),
        new StatePaths($hostRoot),
        $operation,
    );
    try {
        $releaser->release(new TopologyRequest('ORB-167', $worktree));
        throw new RuntimeException('Ambiguous legacy release was accepted.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'extension target is ambiguous')) {
            throw $exception;
        }
    }

    if (! file_exists($worktree.'/.e2e/'.IssueState::ATTEMPT)) {
        throw new RuntimeException('Pre-transport refusal mutated the lease.');
    }

    fwrite(STDOUT, "lease target persisted; ambiguous legacy release refused before transport\n");
} finally {
    $files = glob($worktree.'/.e2e/*') ?: [];
    foreach ($files as $file) {
        unlink($file);
    }
    @rmdir($worktree.'/.e2e');
    @rmdir($worktree);
    @rmdir($hostRoot);
    @rmdir($root);
}
