<?php

declare(strict_types=1);

use App\Infrastructure\Files\ProtectedFileWriter;
use Symfony\Component\Process\Process;

$repository = $argv[1] ?? '/home/orbit/orbit';
require $repository . '/apps/gateway/vendor/autoload.php';

function requireProof(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function proofCandidates(string $path): array
{
    $candidates = glob($path . '.candidate.*');

    if (!is_array($candidates)) {
        return [];
    }

    sort($candidates);

    return $candidates;
}

/** @return list<string> */
function proofSiblings(string $path): array
{
    $siblings = glob($path . '.*');

    if (!is_array($siblings)) {
        return [];
    }

    sort($siblings);

    return $siblings;
}

function waitForProof(Closure $condition, string $message, float $timeoutSeconds = 10.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (!$condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }

        usleep(1_000);
    }
}

function openProcessCandidate(Process $process, string $path): ?string
{
    $processId = $process->getPid();

    if (!is_int($processId)) {
        return null;
    }

    $descriptors = glob("/proc/{$processId}/fd/*");

    if (!is_array($descriptors)) {
        return null;
    }

    foreach ($descriptors as $descriptor) {
        $target = readlink($descriptor);

        if (is_string($target) && str_starts_with($target, $path . '.candidate.')) {
            return $target;
        }
    }

    return null;
}

/** @param list<Process> $processes */
function holdWriterCandidate(array $processes, string $path): array
{
    requireProof(function_exists('posix_kill'), 'The proof requires POSIX process signals.');
    $deadline = microtime(true) + 10;

    while (microtime(true) < $deadline) {
        foreach ($processes as $process) {
            $candidate = openProcessCandidate($process, $path);
            $processId = $process->getPid();

            if ($candidate === null || !is_int($processId)) {
                continue;
            }

            if (!posix_kill($processId, 19)) {
                continue;
            }

            usleep(10_000);

            if (is_file($candidate)) {
                return [$processId, $candidate];
            }

            posix_kill($processId, 18);
        }

        usleep(100);
    }

    throw new RuntimeException('No writer could be held while its candidate was open.');
}

function writerProcess(
    string $path,
    string $character,
    int $bytes,
    string $readyPath,
    string $startPath,
    string $repository,
): Process {
    $script = <<<'PHP'
        require $argv[6].'/apps/gateway/vendor/autoload.php';

        file_put_contents($argv[3], 'ready');

        while (! is_file($argv[4])) {
            usleep(1_000);
        }

        new App\Infrastructure\Files\ProtectedFileWriter()->put(
            $argv[1],
            str_repeat($argv[2], (int) $argv[5]),
            0o644,
        );
        PHP;
    $process = new Process([
        PHP_BINARY,
        '-r',
        $script,
        $path,
        $character,
        $readyPath,
        $startPath,
        (string) $bytes,
        $repository,
    ]);
    $process->setTimeout(30);

    return $process;
}

function removeProofDirectory(string $directory): void
{
    requireProof(
        str_starts_with($directory, sys_get_temp_dir() . '/orb-156-proof-'),
        'The proof cleanup directory is outside the fixture boundary.',
    );

    if (!is_dir($directory)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entryPath = $entry->getPathname();

        $entry->isDir() && !$entry->isLink() ? rmdir($entryPath) : unlink($entryPath);
    }

    rmdir($directory);
}

$temporary = tempnam(sys_get_temp_dir(), 'orb-156-proof-');

if ($temporary === false || !unlink($temporary) || !mkdir($temporary, 0o700)) {
    fwrite(STDERR, "Could not create the protected-writer proof directory.\n");
    exit(1);
}

$processes = [];
$proofExitCode = 0;

try {
    $gatewayPath = $temporary . '/generated/gateway/Caddyfile';
    $markerDirectory = $temporary . '/markers';
    $startPath = $markerDirectory . '/start';
    $characters = ['A', 'B', 'C', 'D'];
    $bytes = 32 * 1024 * 1024;
    $observedModes = [];
    $heldProcessId = null;
    mkdir($markerDirectory, permissions: 0o700);

    foreach ($characters as $index => $character) {
        $readyPath = "{$markerDirectory}/ready-{$index}";
        $process = writerProcess($gatewayPath, $character, $bytes, $readyPath, $startPath, $repository);
        $process->start();
        $processes[] = $process;
    }

    foreach (array_keys($characters) as $index) {
        waitForProof(static fn(): bool => is_file(
            "{$markerDirectory}/ready-{$index}",
        ), "Writer {$index} did not reach the contention barrier.");
    }

    file_put_contents($startPath, 'start');
    [$heldProcessId, $heldCandidate] = holdWriterCandidate($processes, $gatewayPath);
    $observedModes[$heldCandidate] = fileperms($heldCandidate) & 0o777;
    waitForProof(function () use ($gatewayPath, &$observedModes): bool {
        foreach (proofCandidates($gatewayPath) as $candidate) {
            if (!array_key_exists($candidate, $observedModes) && is_file($candidate)) {
                $observedModes[$candidate] = fileperms($candidate) & 0o777;
            }
        }

        return count($observedModes) >= 2;
    }, 'Concurrent writers did not expose distinct sibling candidates.');
    posix_kill($heldProcessId, 18);
    $heldProcessId = null;

    foreach ($processes as $process) {
        $exitCode = $process->wait();
        requireProof($exitCode === 0, "A contending writer exited {$exitCode}: {$process->getErrorOutput()}");
    }

    $contents = file_get_contents($gatewayPath);
    requireProof(is_string($contents) && $contents !== '', 'The contended Gateway file is missing.');
    requireProof(in_array($contents[0], $characters, true), 'The final Gateway file has an unknown writer.');
    requireProof($contents === str_repeat($contents[0], $bytes), 'The final Gateway file is not one complete input.');
    requireProof(proofCandidates($gatewayPath) === [], 'A successful writer left a candidate behind.');
    requireProof((fileperms($gatewayPath) & 0o777) === 0o644, 'The Gateway file mode is not 0644.');
    requireProof((fileperms(dirname($gatewayPath)) & 0o777) === 0o700, 'The Gateway directory mode is not 0700.');

    foreach ($observedModes as $mode) {
        requireProof($mode === 0o600, 'A candidate was not created with mode 0600.');
    }

    $vpnPath = $temporary . '/generated/wireguard/orbit.conf';
    new ProtectedFileWriter()->put($vpnPath, "vpn-private\n");
    requireProof(file_get_contents($vpnPath) === "vpn-private\n", 'The VPN file bytes changed.');
    requireProof((fileperms($vpnPath) & 0o777) === 0o600, 'The VPN file mode is not 0600.');
    requireProof((fileperms(dirname($vpnPath)) & 0o777) === 0o700, 'The VPN directory mode is not 0700.');

    $refusedPath = $temporary . '/refused/protected.conf';
    $unrelatedCandidate = $refusedPath . '.candidate.other-invocation';
    mkdir($refusedPath, permissions: 0o700, recursive: true);
    file_put_contents($refusedPath . '/original', 'original');
    file_put_contents($unrelatedCandidate, 'other');
    chmod($unrelatedCandidate, 0o600);
    $publicationFailed = false;
    set_error_handler(static fn(): bool => true);

    try {
        new ProtectedFileWriter()->put($refusedPath, 'replacement');
    } catch (RuntimeException $exception) {
        $publicationFailed = $exception->getMessage() === "Could not install protected file [{$refusedPath}].";
    } finally {
        restore_error_handler();
    }

    requireProof($publicationFailed, 'The refused publication did not report its install failure.');
    requireProof(file_get_contents($refusedPath . '/original') === 'original', 'The refused destination changed.');
    requireProof(proofSiblings($refusedPath) === [$unrelatedCandidate], 'Refusal cleaned the wrong candidate.');
    requireProof(file_get_contents($unrelatedCandidate) === 'other', 'The unrelated candidate changed.');
    requireProof((fileperms($unrelatedCandidate) & 0o777) === 0o600, 'The unrelated candidate mode changed.');

    fwrite(STDOUT, json_encode([
        'state' => 'passed',
        'contention_checkpoint' => 'writer-stopped-with-open-candidate',
        'distinct_candidates' => count($observedModes),
        'candidate_modes' => array_values($observedModes),
        'final_writer' => $contents[0],
        'final_bytes' => strlen($contents),
        'final_sha256' => hash('sha256', $contents),
        'gateway_mode' => decoct(fileperms($gatewayPath) & 0o777),
        'vpn_mode' => decoct(fileperms($vpnPath) & 0o777),
        'refused_destination' => 'preserved',
        'owned_candidate' => 'removed',
        'unrelated_candidate' => 'preserved',
    ], JSON_THROW_ON_ERROR)
        . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "ORB-156 proof failed: {$exception->getMessage()}\n");
    $proofExitCode = 1;
} finally {
    if (is_int($heldProcessId ?? null)) {
        posix_kill($heldProcessId, 18);
    }

    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(0.1, 9);
        }
    }

    removeProofDirectory($temporary);
}

exit($proofExitCode);
