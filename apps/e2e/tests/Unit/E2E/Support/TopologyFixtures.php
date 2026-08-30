<?php

declare(strict_types=1);

/**
 * Shared Incus process fakes and repository fixtures for the topology lifecycle tests.
 *
 * The acquirer and the proof runner exercise the same promoted generation, the same
 * pinned feature worktrees, and the same guest command answers; each test file
 * loads this file once with `require_once`.
 */

use App\E2E\Git\GitRepository;
use App\E2E\PreparedStateFingerprint;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyTarget;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;

/** One promoted generation for the fixture repository, so discovery acquisition can start. */
function promoteDiscoveryGeneration(string $repositoryRoot, StatePaths $paths): void
{
    $store = new AtomicJsonStore($paths);
    $prepared = topologyFinalPreparedFingerprint($repositoryRoot);
    $mainSha = new GitRepository($repositoryRoot)->commit();
    $structural = new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($mainSha);
    new StandbyManifestStore($store, $paths, new TopologyManifestStore($store, $paths))->promote(new StandbyGeneration(
        substr($mainSha, 0, 12).'-'.substr($prepared->value, 0, 12),
        $mainSha,
        ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
        $prepared->value,
        str_repeat('b', 64),
        topologyPromotedLaravel(),
        $structural->value,
        $structural->manifest['schema'],
        $structural->manifest['cold_epoch'],
        $structural->manifest['base_image_alias'],
        $structural->manifest['topology']['profile'],
        $structural->manifest['topology']['roles'],
        $structural->manifest['topology']['checkout_roles'],
    ));
}

/** @param list<string> $command */
function topologyFirewallResult(array $command): ?\Illuminate\Contracts\Process\ProcessResult
{
    if (
        ($command[0] ?? null) === 'python3'
        && str_ends_with((string) ($command[1] ?? ''), '/resources/host/reconcile-firewall.py')
    ) {
        return Process::result(json_encode(['changed' => false], JSON_THROW_ON_ERROR));
    }

    if (array_slice($command, 0, 5) !== ['sudo', '-n', 'iptables', '-w', '5']) {
        return null;
    }

    return in_array('-C', $command, true) ? Process::result('', '', 1) : Process::result();
}

function preparedTopologyRepository(): string
{
    $root = temporaryPath('orbit-prepared-topology-', 8);
    $e2e = dirname(__DIR__, 4);
    $manifestPath = 'apps/e2e/resources/prepared-state.json';
    mkdir($root.'/'.dirname($manifestPath), 0700, true);
    copy($e2e.'/resources/prepared-state.json', $root.'/'.$manifestPath);
    $manifest = json_decode((string) file_get_contents($root.'/'.$manifestPath), true, 512, JSON_THROW_ON_ERROR);

    foreach ($manifest['paths'] as $pattern) {
        if ($pattern === $manifestPath) {
            continue;
        }
        $path = str_replace(['**/', '*'], ['nested/', 'placeholder'], $pattern);
        $directory = $root.'/'.dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($root.'/'.$path, 'prepared');
    }
    $guestSource = $e2e.'/resources/guest';
    $guestTarget = $root.'/apps/e2e/resources/guest';
    foreach (glob($guestSource.'/*.sh') ?: [] as $script) {
        copy($script, $guestTarget.'/'.basename($script));
        chmod($guestTarget.'/'.basename($script), 0755);
    }
    // Vendor trees and the gateway environment stay out of the tree, as in Orbit itself.
    file_put_contents($root.'/.gitignore', "/vendor/\nvendor/\n.env\n");
    hydrateFixtureVendor($root);

    foreach ([
        ['git', 'init', '-q', '-b', 'feature/NCK-123', $root],
        ['git', '-C', $root, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $root, 'config', 'user.name', 'Orbit Developer'],
        ['git', '-C', $root, 'add', '.'],
        ['git', '-C', $root, 'commit', '-q', '-m', 'Prepared state'],
        ['git', '-C', $root, 'branch', 'main'],
    ] as $command) {
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare the topology fixture repository.');
        }
    }

    return $root;
}

/** Host `bin/bootstrap` owns vendor; discovery requires every autoload before any Incus mutation. */
function hydrateFixtureVendor(string $worktree): void
{
    foreach (['apps/gateway', 'apps/cli', 'packages/php-sdk'] as $project) {
        if (! is_dir($worktree.'/'.$project.'/vendor')) {
            mkdir($worktree.'/'.$project.'/vendor', 0700, true);
        }
        file_put_contents($worktree.'/'.$project.'/vendor/autoload.php', "<?php\n");
    }
}

function standbyVmInventoryJson(): string
{
    $roles = \App\E2E\Value\TopologyProfile::ROLES;
    $instances = array_merge(
        array_map(static fn (string $role): string => TopologyTarget::standby()->instance($role), $roles),
        array_map(static fn (string $role): string => featureTarget('NCK-123')->instance($role), $roles),
    );

    return json_encode(array_map(
        static fn (string $name): array => [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'status_code' => 102,
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            'devices' => [
                'root' => ['pool' => 'default'],
                'eth0' => ['network' => TopologyTarget::standby()->network()],
            ],
        ],
        $instances,
    ), JSON_THROW_ON_ERROR);
}

function standbySnapshotInventoryJson(string $instance, bool $include = true, string $owner = 'orbit-e2e'): string
{
    $role = str_replace('orbit-e2e-standby-', '', $instance);
    $snapshot = match ($role) {
        'gateway' => 'main-gateway',
        'app-dev' => 'main-app-dev',
        'app-prod' => 'main-app-prod',
        default => throw new RuntimeException('Unexpected standby fixture instance.'),
    };

    return json_encode(
        $include
            ? [[
                'name' => $snapshot,
                'config' => ['user.orbit.e2e.owner' => $owner],
            ]] : [],
        JSON_THROW_ON_ERROR,
    );
}

function topologyVmJson(
    string $name,
    array $metadata = ['user.orbit.e2e.owner' => 'orbit-e2e'],
    ?string $network = null,
): string {
    $devices = ['root' => ['pool' => 'default']];
    if ($network !== null) {
        $role = match (true) {
            str_ends_with($name, '-gateway') => 'gateway',
            str_ends_with($name, '-app-dev') => 'app-dev',
            default => 'app-prod',
        };
        $devices['eth0'] = [
            'network' => $network,
            'hwaddr' => '00:16:3e:'.implode(':', str_split(substr(sha1($network.':'.$role), 0, 6), 2)),
            'ipv4.address' => TopologyTarget::ipv4For(2, $role),
        ];
    }

    return json_encode([[
        'name' => $name,
        'type' => 'virtual-machine',
        'status' => 'Stopped',
        'status_code' => 102,
        'config' => $metadata,
        'devices' => $devices,
    ]], JSON_THROW_ON_ERROR);
}

function preparedBaseImageJson(string $fingerprint): string
{
    return json_encode([[
        'type' => 'virtual-machine',
        'fingerprint' => $fingerprint,
        'aliases' => [['name' => 'orbit-base-ubuntu-26.04-runtime']],
    ]], JSON_THROW_ON_ERROR);
}

function preparedGenerationId(string $repositoryRoot, string $fingerprint): string
{
    return substr(new GitRepository($repositoryRoot)->commit(), 0, 12).'-'.substr($fingerprint, 0, 12);
}

function topologyPromotedLaravel(): LaravelRelease
{
    return new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0');
}

function topologyFinalPreparedFingerprint(
    string $repositoryRoot,
    string $commit = 'HEAD',
): \App\E2E\Value\PreparedFingerprint {
    $release = topologyPromotedLaravel();

    return new PreparedStateFingerprint(new GitRepository($repositoryRoot))->forCommit($commit, $release);
}

function pinnedFeatureWorktree(string $repositoryRoot, string $suffix): string
{
    $worktree = temporaryPath('orbit-worktree-'.$suffix.'-');
    $sourcePath = $worktree.'/feature-source-'.$suffix.'.txt';
    foreach ([
        ['git', '-C', $repositoryRoot, 'worktree', 'add', '-q', '-b', 'feature/NCK-123-'.$suffix, $worktree, 'HEAD'],
        ['git', '-C', $worktree, 'config', 'user.email', 'developer@example.com'],
        ['git', '-C', $worktree, 'config', 'user.name', 'Orbit Developer'],
    ] as $index => $command) {
        if (! Process::run($command)->successful()) {
            throw new RuntimeException('Unable to prepare a feature worktree.');
        }
    }
    file_put_contents($sourcePath, "feature source {$suffix}\n");
    if (! Process::run(['git', '-C', $worktree, 'add', $sourcePath])->successful()) {
        throw new RuntimeException('Unable to stage the feature fixture.');
    }
    if (! Process::run(['git', '-C', $worktree, 'commit', '-q', '-m', 'Pin Laravel'])->successful()) {
        throw new RuntimeException('Unable to commit the feature fixture.');
    }
    hydrateFixtureVendor($worktree);

    return $worktree;
}

/**
 * @param list<string> $command
 * @mago-expect lint:cyclomatic-complexity The fake inventories each exact Incus resource kind.
 */
function pinnedWorktreeInventoryResult(
    array $command,
    TopologyTarget $target,
): ?\Illuminate\Contracts\Process\ProcessResult {
    if (($firewall = topologyFirewallResult($command)) !== null) {
        return $firewall;
    }
    if (in_array('image', $command, true) && in_array('list', $command, true)) {
        return Process::result(preparedBaseImageJson(str_repeat('b', 64)));
    }
    if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
        return Process::result(json_encode([[
            'name' => $target->network(),
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24'],
        ]], JSON_THROW_ON_ERROR));
    }
    if (($command[3] ?? null) === 'list') {
        if (($command[4] ?? null) === 'local:') {
            $featureInstances = array_map(
                static fn (string $role): array => json_decode(
                    topologyVmJson(
                        $target->instance($role),
                        ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        $target->network(),
                    ),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                )[0],
                \App\E2E\Value\TopologyProfile::ROLES,
            );

            return Process::result(json_encode(array_merge(
                array_values(array_filter(
                    json_decode(standbyVmInventoryJson(), true, 16, JSON_THROW_ON_ERROR),
                    static fn (array $vm): bool => ! str_contains((string) $vm['name'], 'nck-123'),
                )),
                $featureInstances,
            ), JSON_THROW_ON_ERROR));
        }
        $name = preg_replace('/\A[^:]+:/', '', (string) ($command[4] ?? ''));

        return Process::result(
            $name === $target->network()
                ? '[]'
                : topologyVmJson($name, ['user.orbit.e2e.owner' => 'orbit-e2e']),
        );
    }
    if (($command[3] ?? null) === 'snapshot' && ($command[4] ?? null) === 'list') {
        $instance = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));

        return Process::result(standbySnapshotInventoryJson($instance));
    }

    return null;
}

/** @param list<string> $guest */
function pinnedWorktreeGuestCommandResult(array $guest): \Illuminate\Contracts\Process\ProcessResult
{
    if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
        $guest = array_slice($guest, 6);
    }
    if (
        $guest === [
            'sh',
            '-c',
            'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ] && ip -4 -o addr show dev "$interface" scope global',
        ]
    ) {
        return Process::result("2: enp5s0    inet 10.44.0.10/24 scope global enp5s0\n");
    }
    if (($guest[0] ?? null) === '/usr/local/bin/receive-source.sh') {
        $sha = collect($guest)->first(
            static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1,
        );
        $treeHash = collect($guest)->first(
            static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1,
        );

        return Process::result(json_encode([
            'sha' => $sha,
            'tree_hash' => $treeHash,
        ], JSON_THROW_ON_ERROR));
    }
    if (($guest[0] ?? null) === '/usr/local/bin/verify-topology.sh') {
        return Process::result(json_encode([
            'probe' => $guest[1],
            'passed' => true,
            'identity' => $guest[3],
            'checked_at' => '2026-08-29T12:34:56+00:00',
            'expected' => 'healthy',
            'observed' => 'healthy',
            'evidence_ref' => 'incus://'.$guest[4].'/'.$guest[1],
        ], JSON_THROW_ON_ERROR));
    }
    if (in_array('ssh-keygen', $guest, true)) {
        return Process::result('ssh-ed25519 '.str_repeat('A', 43)."=\n");
    }
    if ($guest === ['uname', '-m']) {
        return Process::result("x86_64\n");
    }

    return Process::result();
}

/** @param list<string> $command */
function pinnedWorktreeGuestResult(array $command): \Illuminate\Contracts\Process\ProcessResult
{
    return pinnedWorktreeGuestCommandResult(array_slice($command, 6));
}

/**
 * @param null|list<array<array-key, mixed>> $events
 * @param null|Closure(list<string>): (?\Illuminate\Contracts\Process\ProcessResult) $guestOverride
 */
function pinnedWorktreeBatchResult(
    \Illuminate\Process\PendingProcess $process,
    ?array &$events = null,
    ?Closure $guestOverride = null,
): ?\Illuminate\Contracts\Process\ProcessResult {
    $command = $process->command;
    if (
        ($command[0] ?? null) !== 'python3'
        || ! str_ends_with((string) ($command[1] ?? ''), '/resources/host/exec-all.py')
    ) {
        return null;
    }

    $payload = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($payload['requests'] as $request) {
        $guest = $request['argv'];
        if ($events !== null) {
            $events[] = [
                'incus',
                '--project',
                'default',
                'exec',
                $request['instance'],
                '--',
                ...$guest,
            ];
        }
        $normalizedGuest = array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']
            ? array_slice($guest, 6)
            : $guest;
        $result = $guestOverride?->__invoke($normalizedGuest) ?? pinnedWorktreeGuestCommandResult($guest);
        $results[] = [
            'label' => $request['label'],
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
}

/**
 * @param list<array<array-key, mixed>> $events
 * @param null|Closure(list<string>): void $observe
 */
function fakePinnedWorktreeProcesses(TopologyTarget $target, array &$events, ?Closure $observe = null): void
{
    $realProcess = new ProcessFactory;
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (
        &$events,
        $realProcess,
        $target,
        $observe,
    ) {
        $command = $process->command;
        $observe?->__invoke($command);
        if (($command[0] ?? null) === 'git') {
            return $realProcess
                ->path((string) ($process->path ?: getcwd()))
                ->input($process->input)
                ->run($command);
        }
        if (($batch = pinnedWorktreeBatchResult($process, $events)) !== null) {
            return $batch;
        }
        $events[] = $command;

        return pinnedWorktreeInventoryResult($command, $target) ?? pinnedWorktreeGuestResult($command);
    });
}
