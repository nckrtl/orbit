<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\IncusHost;
use App\E2E\TopologySnapshotAvailability;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

$root = dirname(__DIR__, 2);
$e2e = $root.'/apps/e2e';
require $e2e.'/vendor/autoload.php';

/** @var Application $app */
$app = require $e2e.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$candidate = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
if (preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
    throw new RuntimeException('Candidate SHA is unavailable.');
}

$manifests = $app->make(TopologySnapshotManifestStore::class);
$generation = $manifests->promoted() ?? throw new RuntimeException('Promoted generation is absent.');
$constructor = $app->make(ColdTopologyConstructor::class);
$host = $app->make(IncusHost::class);
$attempt = AttemptId::generate();
$operation = new OperationId(bin2hex(random_bytes(16)));
$target = TopologyTarget::disposableCold(
    'ORB-231',
    $attempt,
    TopologyRecipe::registered($generation->baseImageAlias),
);
$before = $generation->toArray();
$construction = null;
$readiness = null;
$proof = null;
$reviewMarker = null;
$cleanup = null;

try {
    $construction = $constructor->constructReplacement(new ColdTopologyPlan(
        $target,
        $root,
        $candidate,
        [$generation->baseImageAlias => $generation->baseImageFingerprint],
        $generation->laravel,
        $operation,
        [
            'user.orbit.e2e.operation' => $operation->value,
            'user.orbit.e2e.issue' => 'ORB-231',
            'user.orbit.e2e.attempt' => $attempt->value,
        ],
        snapshotReplacement: true,
    ));
    $source = new SourceState($candidate, $candidate, operationId: $operation->value);
    $verifier = $app->make(TopologyVerifier::class);
    $readiness = $verifier->verify(
        $target,
        VerificationMode::Readiness,
        $source,
        requiredAssignments: TopologyProfile::ASSIGNMENTS,
        nativeSamplesOnly: true,
    );
    $proof = $verifier->verify(
        $target,
        VerificationMode::Proof,
        $source,
        requiredAssignments: TopologyProfile::ASSIGNMENTS,
        nativeSamplesOnly: true,
    );
    if (! $readiness->passed || ! $proof->passed) {
        throw new RuntimeException('The disposable replacement failed strict verification.');
    }
    $reviewMarker = $host->exec(
        $target->instance('app-dev'),
        GuestCommand::asOrbitUser(['sh', '-lc', 'printf reviewed > /tmp/orb-231-review-marker && test -f /tmp/orb-231-review-marker']),
    );
    if (! $reviewMarker->successful()) {
        throw new RuntimeException('The disposable review-isolation marker failed.');
    }
} finally {
    $cleanup = $constructor->cleanup($target, $operation);
}

if (! $cleanup->successful()) {
    throw new RuntimeException('Exact rehearsal cleanup failed: '.implode('; ', $cleanup->refused));
}
$after = $manifests->promoted()?->toArray();
if ($after !== $before) {
    throw new RuntimeException('The promoted generation changed during the disposable rehearsal.');
}
$app->make(TopologySnapshotAvailability::class)->assertAvailable($generation);
$remainingInstances = $host->instances(array_map($target->instance(...), TopologyProfile::ROLES));
$remainingNetwork = $host->network($target->network());
if ($remainingInstances !== [] || $remainingNetwork !== null) {
    throw new RuntimeException('Disposable replacement resources remain after exact cleanup.');
}

echo json_encode([
    'schema' => 1,
    'candidate' => $candidate,
    'attempt_id' => $attempt->value,
    'operation_id' => $operation->value,
    'promoted_generation_before' => $before,
    'construction' => $construction?->toArray(),
    'strict_readiness' => $readiness?->toArray(),
    'strict_proof' => $proof?->toArray(),
    'review_marker_exit' => $reviewMarker?->exitCode,
    'promoted_generation_after' => $after,
    'cleanup' => [
        'removed' => $cleanup->removed,
        'absent' => $cleanup->absent,
        'refused' => $cleanup->refused,
    ],
    'remaining_instances' => array_keys($remainingInstances),
    'remaining_network' => $remainingNetwork?->name,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
