#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\E2E\HostRelativeDeleter;
use App\E2E\LegacyIncusRevalidator;
use App\E2E\LegacyRetirement;
use App\E2E\LegacyRetirementHost;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use App\E2E\Value\QuarantineManifest;
use App\E2E\Value\RetirementInventory;
use App\E2E\Value\RetirementResult;
use Illuminate\Contracts\Console\Kernel;

$repositoryRoot = is_file('/home/orbit/orbit/apps/e2e/vendor/autoload.php')
    ? '/home/orbit/orbit'
    : dirname(__DIR__, 2);
define('ORB171_REPOSITORY_ROOT', $repositoryRoot);
require $repositoryRoot.'/apps/e2e/vendor/autoload.php';
$laravel = require $repositoryRoot.'/apps/e2e/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-171 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @param array<string, mixed> $value */
function orb171WriteJson(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

function orb171RemoveTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path.'/'.$entry;
        if (is_dir($child) && ! is_link($child)) {
            orb171RemoveTree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

/** @param callable(): array<string, list<array<string, mixed>>> $observe @param callable(string, array<string, mixed>): void $mutate @param (callable(?array): array<string, list<array<string, mixed>>>)|null $current */
function orb171Retirement(
    callable $observe,
    callable $mutate,
    string $now,
    string $lockRoot,
    string $operation,
    ?callable $current,
): LegacyRetirement {
    return new LegacyRetirement(
        $observe(...),
        $mutate(...),
        static fn (): DateTimeImmutable => new DateTimeImmutable($now),
        new OperationLock(new StatePaths($lockRoot)),
        new OperationId($operation),
        $current === null ? null : $current(...),
    );
}

/** @return array<string, list<array<string, mixed>>> */
function orb171Observation(
    string $candidate,
    string $safeRoot,
    string $remote,
    string $project,
    string $pool,
    string $fingerprint,
): array {
    return [
        'manifests' => [[
            'path' => $candidate,
            'safe_root' => $safeRoot,
            'filesystem_type' => 'file',
            'classification' => 'legacy',
            'content_sha256' => hash_file('sha256', $candidate),
        ]],
        'base_images' => [[
            'name' => 'orbit-base-ubuntu-26.04-runtime',
            'remote' => $remote,
            'project' => $project,
            'fingerprint' => $fingerprint,
            'classification' => 'preserve',
        ]],
        'pools' => [[
            'name' => $pool,
            'identity' => 'display-only-storage-pool',
            'remote' => $remote,
            'project' => $project,
            'classification' => 'preserve',
        ]],
    ];
}

/** @param callable(): mixed $operation */
function orb171ExpectRefusal(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|RuntimeException) {
        return;
    }

    throw new RuntimeException("{$label} was accepted.");
}

/** @param array<string, mixed> $value */
function orb171AssertSchemaTwoRefusal(
    string $root,
    string $label,
    array $value,
    callable $decode,
): void {
    $value['version'] = 2;
    $path = $root.'/'.$label.'-v2.json';
    $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    file_put_contents($path, $bytes);
    chmod($path, 0600);
    orb171ExpectRefusal(static fn (): mixed => $decode(LegacyRetirement::readProtectedJson($path)), $label);
    if (file_get_contents($path) !== $bytes) {
        throw new RuntimeException("{$label} schema 2 bytes changed.");
    }
}

function orb171Guest(): void
{
    $root = sys_get_temp_dir().'/orb-171-guest-'.bin2hex(random_bytes(8));
    $safeRoot = $root.'/safe';
    $candidate = $safeRoot.'/candidate.json';
    $freeze = $root.'/freeze.json';
    $quarantineJournal = $root.'/quarantine-journal.json';
    $deleteJournal = $root.'/delete-journal.json';
    mkdir($safeRoot, 0700, true);
    file_put_contents($candidate, 'ORB-171 reviewed temporary candidate');
    file_put_contents($freeze, 'ORB-171 immutable freeze evidence');
    chmod($candidate, 0600);
    chmod($freeze, 0600);
    $observed = orb171Observation($candidate, $safeRoot, 'local', 'default', 'orbit-e2e', str_repeat('b', 64));
    $deleter = new HostRelativeDeleter(ORB171_REPOSITORY_ROOT.'/apps/e2e/resources/host/delete-relative.py');

    try {
        $early = orb171Retirement(
            static fn (): array => $observed,
            static function (string $operation, array $resource) use ($deleter): void {
                if ($operation !== 'delete_manifests') {
                    throw new RuntimeException('The guest fixture reached an unexpected mutation.');
                }
                $deleter->delete('manifests', (string) $resource['safe_root'], (string) $resource['path']);
            },
            '2026-09-01T00:00:00+00:00',
            $root.'/locks',
            str_repeat('a', 32),
            static fn (?array $_requested): array => $observed,
        );
        $inventory = $early->inventory();
        $serialized = $inventory->toArray();
        if (
            $serialized['version'] !== 3
            || $inventory->preserved['pools'][0]['name'] !== 'orbit-e2e'
            || $inventory->preserved['pools'][0]['identity'] !== 'display-only-storage-pool'
            || $inventory->preserved['base_images'][0]['fingerprint'] !== str_repeat('b', 64)
            || RetirementInventory::fromArray($serialized)->sha256() !== $inventory->sha256()
        ) {
            throw new RuntimeException('Schema 3 exact-reference serialization failed.');
        }

        foreach ([
            'pool selector' => static function (array $value): array {
                $value['preserved']['pools'][0]['name'] = 'invalid/pool';

                return $value;
            },
            'image fingerprint' => static function (array $value): array {
                $value['preserved']['base_images'][0]['fingerprint'] = str_repeat('B', 64);

                return $value;
            },
        ] as $label => $alter) {
            orb171ExpectRefusal(
                static fn (): RetirementInventory => RetirementInventory::fromArray($alter($serialized)),
                $label,
            );
        }

        $manifest = $early->quarantine($inventory, $inventory->sha256(), $freeze, $quarantineJournal);
        $later = orb171Retirement(
            static fn (): array => $observed,
            static function (string $operation, array $resource) use ($deleter): void {
                if ($operation !== 'delete_manifests') {
                    throw new RuntimeException('The guest fixture reached an unexpected mutation.');
                }
                $deleter->delete('manifests', (string) $resource['safe_root'], (string) $resource['path']);
            },
            '2026-09-09T00:00:00+00:00',
            $root.'/locks',
            str_repeat('b', 32),
            static fn (?array $_requested): array => $observed,
        );
        $result = $later->delete($manifest, $manifest->sha256(), $deleteJournal);
        if (file_exists($candidate) || ! $result->successful || $result->toArray()['version'] !== 3) {
            throw new RuntimeException('The reviewed temporary-file deletion failed.');
        }

        orb171AssertSchemaTwoRefusal(
            $root,
            'inventory',
            $inventory->toArray(),
            static fn (array $value): RetirementInventory => RetirementInventory::fromArray($value),
        );
        orb171AssertSchemaTwoRefusal(
            $root,
            'quarantine',
            $manifest->toArray(),
            static fn (array $value): QuarantineManifest => QuarantineManifest::fromArray($value),
        );
        orb171AssertSchemaTwoRefusal(
            $root,
            'result',
            $result->toArray(),
            static fn (array $value): RetirementResult => RetirementResult::fromArray($value),
        );

        foreach ([$quarantineJournal, $deleteJournal] as $journal) {
            $value = LegacyRetirement::readProtectedJson($journal);
            $value['version'] = 2;
            $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            file_put_contents($journal, $bytes);
            chmod($journal, 0600);
            orb171ExpectRefusal(
                $journal === $quarantineJournal
                    ? static fn (): QuarantineManifest => $early->quarantine(
                        $inventory,
                        $inventory->sha256(),
                        $freeze,
                        $journal,
                    )
                    : static fn (): RetirementResult => $later->delete($manifest, $manifest->sha256(), $journal),
                basename($journal),
            );
            if (file_get_contents($journal) !== $bytes) {
                throw new RuntimeException('A schema 2 journal changed.');
            }
        }

        orb171WriteJson([
            'schema' => 3,
            'status' => 'passed',
            'inventory_sha256' => $inventory->sha256(),
            'quarantine_sha256' => $manifest->sha256(),
            'pool_selector' => $inventory->preserved['pools'][0]['name'],
            'pool_display_identity' => $inventory->preserved['pools'][0]['identity'],
            'image_fingerprint' => $inventory->preserved['base_images'][0]['fingerprint'],
            'temporary_candidate_deleted' => true,
            'schema_2_files_unchanged' => 5,
        ]);
    } finally {
        orb171RemoveTree($root);
    }
}

function orb171Host(array $arguments): void
{
    if (count($arguments) !== 7) {
        exit(64);
    }
    [$scenario, $root, $remote, $project, $pool, $fingerprint, $expected] = $arguments;
    if (! in_array($scenario, ['matching', 'changed-project', 'changed-pool', 'changed-fingerprint'], true)) {
        exit(64);
    }
    $safeRoot = $root.'/safe';
    $candidate = $safeRoot.'/candidate.json';
    $freeze = $root.'/freeze.json';
    $observationPath = $root.'/observation.json';
    mkdir($safeRoot, 0700, true);
    file_put_contents($candidate, $expected);
    file_put_contents($freeze, 'ORB-171 host freeze evidence');
    chmod($candidate, 0600);
    chmod($freeze, 0600);
    $observed = orb171Observation($candidate, $safeRoot, $remote, $project, $pool, $fingerprint);
    file_put_contents($observationPath, json_encode($observed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    chmod($observationPath, 0600);
    putenv('ORBIT_E2E_LEGACY_OBSERVATION='.$observationPath);
    $host = new LegacyRetirementHost(
        new LegacyIncusRevalidator,
        new HostRelativeDeleter(ORB171_REPOSITORY_ROOT.'/apps/e2e/resources/host/delete-relative.py'),
    );

    try {
        $early = orb171Retirement(
            $host->observe(...),
            $host->mutate(...),
            '2026-09-01T00:00:00+00:00',
            $root.'/locks',
            str_repeat('c', 32),
            $host->observeCurrent(...),
        );
        $inventory = $early->inventory();
        $manifest = $early->quarantine($inventory, $inventory->sha256(), $freeze);

        if ($scenario === 'changed-project') {
            $observed['pools'][0]['project'] = 'orb-171-wrong-project';
        } elseif ($scenario === 'changed-pool') {
            $observed['pools'][0]['name'] = 'orb-171-wrong-pool';
        } elseif ($scenario === 'changed-fingerprint') {
            $observed['base_images'][0]['fingerprint'] = str_repeat('f', 64);
        }
        file_put_contents($observationPath, json_encode($observed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $later = orb171Retirement(
            $host->observe(...),
            $host->mutate(...),
            '2026-09-09T00:00:00+00:00',
            $root.'/locks',
            str_repeat('d', 32),
            $host->observeCurrent(...),
        );
        $refusalReason = null;
        try {
            $later->delete($manifest, $manifest->sha256());
        } catch (RuntimeException $exception) {
            $refusalReason = $exception->getMessage();
        }
        $refused = $refusalReason !== null;
        $exists = file_exists($candidate);
        $afterSha = $exists ? hash_file('sha256', $candidate) : null;
        $expectedSha = hash('sha256', $expected);
        if (
            $scenario === 'matching'
                ? $refused || $exists
                : $refusalReason !== 'A preserved resource drifted before deletion.'
                    || ! $exists
                    || $afterSha !== $expectedSha
        ) {
            throw new RuntimeException('The host scenario produced the wrong mutation outcome.');
        }

        orb171WriteJson([
            'scenario' => $scenario,
            'status' => 'passed',
            'candidate' => [
                'path' => $candidate,
                'before_sha256' => $expectedSha,
                'exists_after' => $exists,
                'after_sha256' => $afterSha,
            ],
            'incus_scope' => ['remote' => $remote, 'project' => $project],
            'pool' => ['name' => $pool, 'display_identity' => 'display-only-storage-pool'],
            'image_fingerprint' => $fingerprint,
            'refused' => $refused,
            'refusal_reason' => $refusalReason,
        ]);
    } finally {
        putenv('ORBIT_E2E_LEGACY_OBSERVATION');
        orb171RemoveTree($root);
    }
}

$mode = $argv[1] ?? 'guest';
if ($mode === 'guest') {
    orb171Guest();
} elseif ($mode === 'host') {
    orb171Host(array_slice($argv, 2));
} else {
    exit(64);
}
