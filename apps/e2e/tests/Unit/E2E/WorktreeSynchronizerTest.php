<?php

declare(strict_types=1);

use App\E2E\GuestTransport;
use App\E2E\Value\DirtyOverlay;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\SyncMode;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

final class WorktreeSynchronizerGuestFake implements GuestTransport
{
    /** @var list<array{instance:string, command:GuestCommand}> */
    public array $execs = [];

    /** @var list<array{instance:string, source:string, destination:string}> */
    public array $pushes = [];

    public function __construct(
        private string $sha,
        private ?string $markerHash = null,
        private ?string $failure = null,
        private ?string $installedScriptsHash = null,
    ) {}

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        $this->execs[] = ['instance' => $instance, 'command' => $command];
        $argv = $command->command;
        if (in_array('rev-parse', $argv, true)) {
            return new GuestCommandResult($this->sha."\n", '', 0);
        }
        if (($argv[0] ?? null) === 'cat') {
            return $this->markerHash === null
                ? new GuestCommandResult('', '', 1)
                : new GuestCommandResult($this->markerHash."\n", '', 0);
        }
        if (($argv[0] ?? null) === 'sha256sum') {
            return $this->installedScriptsHash === null
                ? new GuestCommandResult('', '', 1)
                : new GuestCommandResult($this->installedScriptsHash."\n", '', 0);
        }
        if (($argv[0] ?? null) === 'runuser' && in_array('/usr/local/bin/receive-source.sh', $argv, true)) {
            if ($this->failure === 'source-receive') {
                return new GuestCommandResult('', '', 1);
            }

            return new GuestCommandResult(
                json_encode(['sha' => $this->sha, 'tree_hash' => $argv[array_key_last($argv)]], JSON_THROW_ON_ERROR),
                '',
                0,
            );
        }

        return new GuestCommandResult('', '', 0);
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        $this->pushes[] = compact('instance', 'source', 'destination');
    }
}

function synchronizerGit(string $path, array $arguments): array
{
    $command = ['git', '-C', $path, ...$arguments];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start git for synchronizer tests.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$stdout === false ? '' : $stdout, $exitCode];
}

function removeSynchronizerFixture(string $path): void
{
    $prefix = sys_get_temp_dir().'/orbit-';

    if (! str_starts_with($path, $prefix)) {
        throw new RuntimeException('Refusing to remove an unsafe synchronizer fixture.');
    }

    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        removeSynchronizerFixture($path.'/'.$entry);
    }

    rmdir($path);
}

function createSynchronizerRepositoryFixture(string $issue): array
{
    $root = sys_get_temp_dir().'/orbit-sync-repo-'.bin2hex(random_bytes(5));
    $worktree = $root.'/child';

    mkdir($root, 0700, true);
    file_put_contents($root.'/README.md', "fixture\n");

    synchronizerGit($root, ['init', '-q']);
    synchronizerGit($root, ['add', 'README.md']);
    synchronizerGit($root, [
        '-c',
        'user.name=Test',
        '-c',
        'user.email=test@example.test',
        'commit',
        '-q',
        '-m',
        'fixture',
    ]);
    synchronizerGit($root, ['branch', '-m', "{$issue}-sync"]);
    synchronizerGit($root, ['worktree', 'add', '-q', '-b', "{$issue}-child", $worktree, 'HEAD']);

    mkdir($worktree.'/apps/e2e/resources/guest', 0700, true);
    foreach (glob(dirname(__DIR__, 3).'/resources/guest/*.sh') ?: [] as $script) {
        copy($script, $worktree.'/apps/e2e/resources/guest/'.basename($script));
        chmod($worktree.'/apps/e2e/resources/guest/'.basename($script), fileperms($script) & 0777);
    }
    synchronizerGit($worktree, ['add', 'apps/e2e/resources/guest']);
    synchronizerGit($worktree, [
        '-c',
        'user.name=Test',
        '-c',
        'user.email=test@example.test',
        'commit',
        '-q',
        '-m',
        'guest scripts',
    ]);

    return [$root, $worktree];
}

function destroySynchronizerRepositoryFixture(string $root, string $worktree): void
{
    if (is_dir($worktree)) {
        removeSynchronizerFixture($worktree.'/apps');
        [$output, $exitCode] = synchronizerGit($root, ['worktree', 'remove', '--force', $worktree]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Failed to remove synchronizer worktree: {$output}");
        }
    }

    removeSynchronizerFixture($root);
}

function createSynchronizerPrimaryFixture(string $issue): string
{
    $root = sys_get_temp_dir().'/orbit-sync-primary-'.bin2hex(random_bytes(5));
    mkdir($root, 0700, true);
    file_put_contents($root.'/README.md', "fixture\n");
    synchronizerGit($root, ['init', '-q']);
    synchronizerGit($root, ['add', 'README.md']);
    synchronizerGit($root, [
        '-c',
        'user.name=Test',
        '-c',
        'user.email=test@example.test',
        'commit',
        '-q',
        '-m',
        'fixture',
    ]);
    synchronizerGit($root, ['branch', '-m', "{$issue}-sync"]);

    mkdir($root.'/apps/e2e/resources/guest', 0700, true);
    foreach (glob(dirname(__DIR__, 3).'/resources/guest/*.sh') ?: [] as $script) {
        copy($script, $root.'/apps/e2e/resources/guest/'.basename($script));
        chmod($root.'/apps/e2e/resources/guest/'.basename($script), fileperms($script) & 0777);
    }
    synchronizerGit($root, ['add', 'apps/e2e/resources/guest']);
    synchronizerGit($root, [
        '-c',
        'user.name=Test',
        '-c',
        'user.email=test@example.test',
        'commit',
        '-q',
        '-m',
        'guest scripts',
    ]);

    return $root;
}

function synchronizerRequiredGuestScriptNames(): array
{
    return [
        'converge-app-dev.sh',
        'converge-app-prod-internal-tls.sh',
        'converge-gateway.sh',
        'converge-sample-app.sh',
        'hydrate-orbit.sh',
        'prepare-node.sh',
        'receive-source.sh',
        'verify-topology.sh',
    ];
}

/** @return list<array{instance:string,path:string}> */
function synchronizerInstalledScripts(WorktreeSynchronizerGuestFake $guest): array
{
    return array_values(array_map(
        static fn (array $exec): array => ['instance' => $exec['instance'], 'path' => $exec['command']->command[8]],
        array_filter(
            $guest->execs,
            static fn (array $exec): bool => (
                ($exec['command']->command[0] ?? null) === 'install'
                && ($exec['command']->command[6] ?? null) === '0755'
            ),
        ),
    ));
}

function synchronizerScriptContentHashes(string $worktree): string
{
    $lines = [];
    foreach (synchronizerRequiredGuestScriptNames() as $name) {
        $path = '/usr/local/bin/'.$name;
        $source = $worktree.'/apps/e2e/resources/guest/'.$name;
        $lines[] = hash_file('sha256', $source).'  '.$path;
    }

    return implode("\n", $lines);
}

describe('worktree synchronization values', function () {
    it('records exact dirty source and operation evidence', function () {
        $state = new SourceState(
            str_repeat('a', 40),
            str_repeat('a', 40),
            true,
            str_repeat('b', 64),
            ['apps/cli/app/Commands/StatusCommand.php'],
            str_repeat('c', 32),
        );

        expect(SourceState::fromArray($state->toArray()))
            ->toEqual($state)
            ->and($state->toArray()['overlay_paths'])
            ->toBe(['apps/cli/app/Commands/StatusCommand.php']);
    });

    it('rejects unsafe overlay path segments before transfer', function (string $path) {
        expect(fn () => new DirtyOverlay([$path], str_repeat('a', 64)))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'absolute' => '/etc/passwd',
        'parent' => 'apps/../.env',
        'duplicate separator' => 'apps//cli',
    ]);

    it('rejects inconsistent source evidence', function () {
        expect(fn () => new SourceState(
            str_repeat('a', 40),
            str_repeat('a', 40),
            false,
            null,
            ['README.md'],
        ))
            ->toThrow(InvalidArgumentException::class, 'Clean source');
    });
});

describe('WorktreeSynchronizer', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });
    it('skips script transfer when every guest marker matches', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('LUNA-125');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $scripts = array_map(
            fn (string $name): string => $worktree.'/apps/e2e/resources/guest/'.$name,
            synchronizerRequiredGuestScriptNames(),
        );
        $hash = hash('sha256', implode('', array_map(
            static fn (string $p): string => (
                basename($p)."\0".sprintf('%o', fileperms($p) & 07777)."\0".file_get_contents($p)."\0"
            ),
            $scripts,
        )));
        $guest = new WorktreeSynchronizerGuestFake($sha, $hash, null, synchronizerScriptContentHashes($worktree));
        try {
            new WorktreeSynchronizer($guest, $root)->sync(new TopologyTarget('LUNA-125'), $worktree, SyncMode::Full);
            expect(array_filter($guest->pushes, fn (array $push): bool => str_contains(
                $push['destination'],
                '/scripts/',
            )))->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('reinstalls scripts when the marker matches but installed content drifts', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('LUNA-126');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $scripts = array_map(
            fn (string $name): string => $worktree.'/apps/e2e/resources/guest/'.$name,
            synchronizerRequiredGuestScriptNames(),
        );
        $markerHash = hash('sha256', implode('', array_map(
            static fn (string $p): string => (
                basename($p)."\0".sprintf('%o', fileperms($p) & 07777)."\0".file_get_contents($p)."\0"
            ),
            $scripts,
        )));
        $drifted = str_replace(
            '/usr/local/bin/receive-source.sh',
            '/usr/local/bin/receive-source.sh',
            synchronizerScriptContentHashes($worktree),
        );
        $drifted = preg_replace('/\A[0-9a-f]+/', str_repeat('0', 64), $drifted, 1);
        $guest = new WorktreeSynchronizerGuestFake($sha, $markerHash, null, $drifted);
        try {
            new WorktreeSynchronizer($guest, $root)->sync(new TopologyTarget('LUNA-126'), $worktree, SyncMode::Full);
            expect(synchronizerInstalledScripts($guest))->toHaveCount(24);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('installs scripts before hydration and cleans staging', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('LUNA-123');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha);
        try {
            $state = new WorktreeSynchronizer($guest, $root)->sync(
                new TopologyTarget('LUNA-123'),
                $worktree,
                SyncMode::Full,
            );
            $scriptInstalls = synchronizerInstalledScripts($guest);
            $installIndexes = array_keys(array_filter(
                $guest->execs,
                static fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'install'
                    && ($exec['command']->command[6] ?? null) === '0755'
                ),
            ));
            $receiveIndexes = array_keys(array_filter(
                $guest->execs,
                static fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'runuser'
                    && (
                        in_array('/usr/local/bin/receive-source.sh', $exec['command']->command, true)
                        || in_array('/usr/local/bin/hydrate-orbit.sh', $exec['command']->command, true)
                    )
                ),
            ));
            expect($scriptInstalls)
                ->toHaveCount(24)
                ->and(array_column($scriptInstalls, 'path'))
                ->each
                ->toStartWith('/usr/local/bin/')
                ->and(max($installIndexes))
                ->toBeLessThan(min($receiveIndexes))
                ->and(array_filter(
                    $guest->pushes,
                    fn (array $push): bool => (
                        str_contains($push['destination'], '/scripts/')
                        && ! str_ends_with($push['destination'], '/guest-scripts.sha256')
                    ),
                ))
                ->toHaveCount(24)
                ->and(array_filter(
                    $guest->execs,
                    fn (array $exec): bool => ($exec['command']->command[0] ?? null) === 'rm',
                ))
                ->toHaveCount(5)
                ->and($state->guestSha)
                ->toBe($sha);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('fails inventory before guest mutation', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('LUNA-127');
        try {
            unlink($worktree.'/apps/e2e/resources/guest/receive-source.sh');
            $guest = new WorktreeSynchronizerGuestFake(str_repeat('a', 40));
            expect(fn () => new WorktreeSynchronizer($guest, $root)->sync(
                new TopologyTarget('LUNA-127'),
                $worktree,
                SyncMode::Full,
            ))
                ->toThrow(RuntimeException::class, 'Guest script inventory is invalid.');
            expect($guest->execs)->toBeEmpty()->and($guest->pushes)->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('rejects the primary checkout for feature synchronization before guest mutation', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('LUNA-129');
        $guest = new WorktreeSynchronizerGuestFake(str_repeat('a', 40));
        try {
            expect(fn () => new WorktreeSynchronizer($guest, $root)->sync(
                new TopologyTarget('LUNA-129'),
                $root,
                SyncMode::Full,
            ))
                ->toThrow(InvalidArgumentException::class, 'linked worktree');
            expect($guest->execs)->toBeEmpty()->and($guest->pushes)->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('accepts the primary checkout for standby validation', function () {
        $root = createSynchronizerPrimaryFixture('LUNA-130');
        $sha = trim(synchronizerGit($root, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha);
        try {
            $state = new WorktreeSynchronizer($guest, $root)->sync(
                TopologyTarget::standby(),
                $root,
                SyncMode::Full,
            );
            expect($state->hostSha)->toBe($sha)->and($guest->execs)->not->toBeEmpty();
        } finally {
            removeSynchronizerFixture($root);
        }
    });

    it('cleans source staging after receive failure', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('LUNA-128');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha, null, 'source-receive');
        try {
            expect(fn () => new WorktreeSynchronizer($guest, $root)->sync(
                new TopologyTarget('LUNA-128'),
                $worktree,
                SyncMode::Full,
            ))
                ->toThrow(RuntimeException::class);
            $cleanups = array_values(array_filter(
                $guest->execs,
                fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'rm'
                    && $exec['instance'] === 'orbit-e2e-luna-128-gateway'
                    && str_contains(
                        (string) $exec['command']->command[array_key_last($exec['command']->command)],
                        '/source/',
                    )
                ),
            ));
            $sourcePush = array_values(array_filter($guest->pushes, fn (array $push): bool => str_contains(
                $push['destination'],
                '/source/',
            )))[0];
            $cleanup = $cleanups[array_key_last($cleanups)];
            expect($cleanups)
                ->toHaveCount(1)
                ->and($cleanup['instance'])
                ->toBe('orbit-e2e-luna-128-gateway')
                ->and($cleanup['command']->command[array_key_last($cleanup['command']->command)])
                ->toBe(dirname((string) $sourcePush['destination']));
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });
});
