<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\Value\CandidateSync;
use App\E2E\Value\DirtyOverlay;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/**
 * @mago-expect lint:cyclomatic-complexity,kan-defect The fake models all guest transport outcomes at one test boundary.
 * @mago-expect lint:too-many-properties Each recorded interaction kind is asserted separately by the synchronizer tests.
 */
final class WorktreeSynchronizerGuestFake implements GuestTransport
{
    /** @var list<array{instance:string, command:GuestCommand}> */
    public array $execs = [];

    /** @var list<array{instance:string, source:string, destination:string}> */
    public array $pushes = [];

    /** @var list<array{instance:string, destination:string, header:string}> */
    public array $bundlePushes = [];

    /** @var list<list<string>> */
    public array $execBatches = [];

    /** @var list<list<string>> */
    public array $pushBatches = [];

    /** @var list<array{instance:string, command:GuestCommand}> */
    public array $directExecs = [];

    /** @var list<array{instance:string, source:string, destination:string}> */
    public array $directPushes = [];

    /** @var array<string, array{sha:string, tree:string}> */
    public array $sourceStates = [];

    /** @var array<string, string> */
    public array $hydratedShas = [];

    /** @var list<string> */
    public array $preservedEnvironments = [];

    /** @var array<string, array<string, mixed>> */
    public array $sourceMarkers = [];

    /** The commit each checkout received; later HEAD probes answer with it. @var array<string, string> */
    public array $receivedShas = [];

    /** Observe pushed host files while they still exist. @var ?Closure(string, string): void */
    public ?Closure $onPush = null;

    /** @param string|array<string, string> $sha */
    /** @mago-expect lint:excessive-parameter-list Explicit fake state keeps each transport outcome independently configurable. */
    public function __construct(
        private string|array $sha,
        private ?string $markerHash = null,
        /** @var string|list<string>|null */
        public string|array|null $failure = null,
        private ?string $installedScriptsHash = null,
        /** @var array<string, string> */
        private array $evidenceShas = [],
        /** @var array<string, string> */
        private array $guestStatuses = [],
        /** @var array<string, string> */
        private array $guestTrees = [],
    ) {}

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        $this->directExecs[] = ['instance' => $instance, 'command' => $command];

        return $this->execute($instance, $command);
    }

    /**
     * @param array<string, array{instance:string, command:GuestCommand}> $commands
     * @return array<string, GuestCommandResult>
     */
    public function execAll(array $commands): array
    {
        $this->execBatches[] = array_keys($commands);
        $results = [];
        foreach ($commands as $label => $request) {
            $results[$label] = $this->execute($request['instance'], $request['command']);
        }

        return $results;
    }

    private function execute(string $instance, GuestCommand $command): GuestCommandResult
    {
        $this->execs[] = ['instance' => $instance, 'command' => $command];
        $argv = $command->command;
        if (in_array('rev-parse', $argv, true) && in_array('HEAD^{tree}', $argv, true)) {
            $tree = $this->guestTrees[$instance] ?? '';

            return new GuestCommandResult($tree."\n", '', $tree === '' ? 1 : 0);
        }
        if (in_array('rev-parse', $argv, true)) {
            $sha = is_array($this->sha) ? $this->sha[$instance] ?? '' : $this->sha;
            if (isset($this->receivedShas[$instance])) {
                $sha = $this->fails('source-head', $instance) ? str_repeat('f', 40) : $this->receivedShas[$instance];
            }

            return new GuestCommandResult($sha."\n", '', $sha === '' ? 1 : 0);
        }
        if (in_array('git', $argv, true) && in_array('status', $argv, true)) {
            if (isset($this->receivedShas[$instance])) {
                return new GuestCommandResult($this->fails('source-status', $instance) ? " M drift.txt\n" : '', '', 0);
            }

            return new GuestCommandResult($this->guestStatuses[$instance] ?? '', '', 0);
        }
        if (in_array('git', $argv, true) && in_array('checkout', $argv, true) && in_array('--detach', $argv, true)) {
            return new GuestCommandResult('', '', $this->fails('source-detach', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'cat' && str_ends_with($argv[1] ?? '', '/.git/orbit-source-state')) {
            $state = $this->sourceStates[$instance] ?? null;
            if ($state !== null) {
                return new GuestCommandResult(json_encode($state, JSON_THROW_ON_ERROR)."\n", '', 0);
            }

            return $this->markerHash === null
                ? new GuestCommandResult('', '', 1)
                : new GuestCommandResult($this->markerHash."\n", '', 0);
        }
        if (($argv[0] ?? null) === 'cat' && str_ends_with($argv[1] ?? '', '/.git/orbit-hydrated.sha')) {
            $sha = $this->hydratedShas[$instance] ?? null;
            if ($sha !== null) {
                return new GuestCommandResult($sha."\n", '', 0);
            }

            return $this->markerHash === null
                ? new GuestCommandResult('', '', 1)
                : new GuestCommandResult($this->markerHash."\n", '', 0);
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
        if (
            ($argv[0] ?? null) === 'install'
            && ($argv[1] ?? null) === '-d'
            && str_contains(implode(' ', $argv), '/scripts/')
        ) {
            return new GuestCommandResult('', '', $this->fails('script-prepare', $instance) ? 1 : 0);
        }
        if (
            ($argv[0] ?? null) === 'install'
            && ($argv[6] ?? null) === '0755'
            && str_contains(implode(' ', $argv), '/scripts/')
        ) {
            return new GuestCommandResult('', '', $this->fails('script-install', $instance) ? 1 : 0);
        }
        if (
            ($argv[0] ?? null) === 'install'
            && ($argv[6] ?? null) === '0644'
            && str_contains(implode(' ', $argv), '/scripts/')
        ) {
            return new GuestCommandResult('', '', $this->fails('script-marker-install', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'runuser' && in_array('/usr/local/bin/receive-source.sh', $argv, true)) {
            if ($this->fails('source-receive', $instance)) {
                return new GuestCommandResult('', '', 1);
            }

            $this->sourceStates[$instance] = [
                'sha' => $argv[8],
                'tree' => $argv[array_key_last($argv)],
            ];
            $this->receivedShas[$instance] = $argv[8];

            return new GuestCommandResult(
                json_encode([
                    'sha' => $this->evidenceShas[$instance] ?? $argv[8],
                    'tree_hash' => $argv[array_key_last($argv)],
                ], JSON_THROW_ON_ERROR),
                '',
                0,
            );
        }
        if (
            ($argv[0] ?? null) === 'install'
            && ($argv[1] ?? null) === '-d'
            && str_contains(implode(' ', $argv), '/source/')
        ) {
            return new GuestCommandResult('', '', $this->fails('source-prepare', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'chown' && str_contains(implode(' ', $argv), '/source/')) {
            return new GuestCommandResult('', '', $this->fails('source-ownership', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'runuser' && in_array('/usr/local/bin/hydrate-orbit.sh', $argv, true)) {
            if ($this->fails('source-hydrate', $instance)) {
                return new GuestCommandResult('', '', 1);
            }

            $this->hydratedShas[$instance] = $argv[array_key_last($argv)];

            return new GuestCommandResult('', '', 0);
        }
        if (($argv[0] ?? null) === 'install' && in_array('/var/lib/orbit-e2e/gateway.env', $argv, true)) {
            $this->preservedEnvironments[] = $instance;

            return new GuestCommandResult('', '', $this->fails('source-preserve-env', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'sh' && in_array('/var/lib/orbit-e2e/source-state', $argv, true)) {
            $this->sourceMarkers[$instance] = json_decode((string) $argv[4], true, 8, JSON_THROW_ON_ERROR);

            return new GuestCommandResult('', '', $this->fails('source-marker', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'rm' && str_contains(implode(' ', $argv), '/source/')) {
            return new GuestCommandResult('', '', $this->fails('source-cleanup', $instance) ? 1 : 0);
        }
        if (($argv[0] ?? null) === 'rm' && str_contains(implode(' ', $argv), '/scripts/')) {
            return new GuestCommandResult('', '', $this->fails('script-cleanup', $instance) ? 1 : 0);
        }

        return new GuestCommandResult('', '', 0);
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        $this->directPushes[] = compact('instance', 'source', 'destination');
        $this->push($instance, $source, $destination);
    }

    /** @param array<string, array{instance:string, source:string, destination:string}> $files */
    public function pushFiles(array $files): void
    {
        $this->pushBatches[] = array_keys($files);
        foreach ($files as $request) {
            $this->push($request['instance'], $request['source'], $request['destination']);
        }
    }

    private function push(string $instance, string $source, string $destination): void
    {
        $this->pushes[] = compact('instance', 'source', 'destination');
        if ($this->onPush !== null) {
            ($this->onPush)($source, $destination);
        }
        if (str_ends_with($destination, '.bundle')) {
            $contents = file_get_contents($source);
            if ($contents === false) {
                throw new RuntimeException('Could not inspect the source bundle.');
            }
            $header = strstr($contents, "\n\n", true);
            $this->bundlePushes[] = [
                'instance' => $instance,
                'destination' => $destination,
                'header' => $header === false ? '' : $header,
            ];
        }
    }

    private function fails(string $phase, string $instance): bool
    {
        $failures = is_array($this->failure) ? $this->failure : [$this->failure];

        return in_array($phase, $failures, true) || in_array("{$phase}:{$instance}", $failures, true);
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
    $root = temporaryPath('orbit-sync-repo-', 5);
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
    $root = temporaryPath('orbit-sync-primary-', 5);
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
        'observe-php.sh',
        'prepare-node.sh',
        'receive-source.sh',
        'retarget-vpn.sh',
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
    it('uses one preflight batch and no guest mutation for hydrated clean source', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-130');
        try {
            $target = featureTarget('NCK-130');
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $tree = new GitRepository($worktree)->effectiveTreeHash();
            /** @mago-expect lint:cyclomatic-complexity The fixture callback keeps its exact hydration assertions together. */
            $scriptHash = hash('sha256', implode('', array_map(
                static fn (string $name): string => (
                    $name
                    ."\0"
                    .sprintf('%o', fileperms($worktree.'/apps/e2e/resources/guest/'.$name) & 07777)
                    ."\0"
                    .file_get_contents($worktree.'/apps/e2e/resources/guest/'.$name)
                    ."\0"
                ),
                synchronizerRequiredGuestScriptNames(),
            )));
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                $scriptHash,
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
                guestStatuses: [
                    $target->instance('gateway') => '',
                    $target->instance('app-dev') => '',
                ],
            );
            foreach (['gateway', 'app-dev'] as $role) {
                $guest->sourceStates[$target->instance($role)] = ['sha' => $sha, 'tree' => $tree];
                $guest->hydratedShas[$target->instance($role)] = $sha;
            }

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->sync($target, $worktree);

            // noop

            expect($state->dirty)
                ->toBeFalse()
                ->and($guest->execBatches)
                ->toHaveCount(1)
                ->and($guest->execBatches[0])
                ->toHaveCount(14)
                ->and($guest->pushBatches)
                ->toBeEmpty()
                ->and($guest->pushes)
                ->toBeEmpty()
                ->and($guest->directPushes)
                ->toBeEmpty()
                ->and($guest->directExecs)
                ->toBeEmpty()
                ->and($guest->sourceStates)
                ->toHaveCount(2)
                ->and($guest->hydratedShas)
                ->toHaveCount(2);
            expect(array_filter(
                $guest->execs,
                static fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'runuser'
                    && in_array('/usr/local/bin/receive-source.sh', $exec['command']->command, true)
                ),
            ))
                ->toBeEmpty()
                ->and(array_filter(
                    $guest->execs,
                    static fn (array $exec): bool => (
                        ($exec['command']->command[0] ?? null) === 'runuser'
                        && in_array('/usr/local/bin/hydrate-orbit.sh', $exec['command']->command, true)
                    ),
                ))
                ->toBeEmpty()
                ->and(array_filter(
                    $guest->execs,
                    static fn (array $exec): bool => ($exec['command']->command[0] ?? null) === 'install',
                ))
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('skips transfer when hydrated guests already match the dirty effective tree', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-141');
        try {
            file_put_contents($worktree.'/README.md', "dirty source\n");
            $target = featureTarget('NCK-141');
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $tree = new GitRepository($worktree)->effectiveTreeHash();
            $scriptHash = hash('sha256', implode('', array_map(
                static fn (string $name): string => (
                    $name
                    ."\0"
                    .sprintf('%o', fileperms($worktree.'/apps/e2e/resources/guest/'.$name) & 07777)
                    ."\0"
                    .file_get_contents($worktree.'/apps/e2e/resources/guest/'.$name)
                    ."\0"
                ),
                synchronizerRequiredGuestScriptNames(),
            )));
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                $scriptHash,
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
            );
            foreach (['gateway', 'app-dev'] as $role) {
                $guest->sourceStates[$target->instance($role)] = ['sha' => $sha, 'tree' => $tree];
                $guest->hydratedShas[$target->instance($role)] = $sha;
            }

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->sync($target, $worktree);

            expect($state->dirty)
                ->toBeTrue()
                ->and($guest->pushes)
                ->toBeEmpty()
                ->and(array_filter(
                    $guest->execs,
                    static fn (array $exec): bool => (
                        ($exec['command']->command[0] ?? null) === 'runuser'
                        && in_array('/usr/local/bin/receive-source.sh', $exec['command']->command, true)
                    ),
                ))
                ->toBeEmpty()
                ->and(array_filter(
                    $guest->execs,
                    static fn (array $exec): bool => (
                        ($exec['command']->command[0] ?? null) === 'runuser'
                        && in_array('/usr/local/bin/hydrate-orbit.sh', $exec['command']->command, true)
                    ),
                ))
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('transfers a dirty effective tree only to the checkout role that drifted', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-142');
        try {
            file_put_contents($worktree.'/README.md', "dirty source\n");
            $target = featureTarget('NCK-142');
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $tree = new GitRepository($worktree)->effectiveTreeHash();
            $scriptHash = hash('sha256', implode('', array_map(
                static fn (string $name): string => (
                    $name
                    ."\0"
                    .sprintf('%o', fileperms($worktree.'/apps/e2e/resources/guest/'.$name) & 07777)
                    ."\0"
                    .file_get_contents($worktree.'/apps/e2e/resources/guest/'.$name)
                    ."\0"
                ),
                synchronizerRequiredGuestScriptNames(),
            )));
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                $scriptHash,
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
            );
            $gateway = $target->instance('gateway');
            $guest->sourceStates[$gateway] = ['sha' => $sha, 'tree' => $tree];
            $guest->hydratedShas[$gateway] = $sha;

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->sync($target, $worktree);

            expect(array_unique(array_column($guest->pushes, 'instance')))
                ->toBe([$target->instance('app-dev')])
                ->and(array_values(array_unique(array_column(array_filter(
                    $guest->execs,
                    static fn (array $exec): bool => (
                        ($exec['command']->command[0] ?? null) === 'runuser'
                        && in_array('/usr/local/bin/receive-source.sh', $exec['command']->command, true)
                    ),
                ), 'instance'))))
                ->toBe([$target->instance('app-dev')]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('uses the injected operation identity in source evidence', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-129');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $state = new WorktreeSynchronizer(
                new WorktreeSynchronizerGuestFake($sha),
                $root,
                new OperationId(str_repeat('a', 32)),
            )->sync(featureTarget('NCK-129'), $worktree);
            expect($state->operationId)->toBe(str_repeat('a', 32));
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('reuses one prerequisite bundle for cloned guests at the same ancestor during full synchronization', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-131');
        try {
            $ancestor = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD^'])[0]);
            $guest = new WorktreeSynchronizerGuestFake([
                'orbit-e2e-nck-131-aaaaaaaa-gateway' => $ancestor,
                'orbit-e2e-nck-131-aaaaaaaa-app-dev' => $ancestor,
            ]);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-131'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and(array_column($guest->bundlePushes, 'header'))
                ->each
                ->toContain("-{$ancestor} ")
                ->and(array_unique(array_column($guest->bundlePushes, 'destination')))
                ->toHaveCount(1);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('falls back to a full bundle when the guest commit is a descendant of the host commit', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-160');
        try {
            $hostSha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            file_put_contents($worktree.'/later.txt', "later\n");
            synchronizerGit($worktree, ['add', 'later.txt']);
            synchronizerGit($worktree, [
                '-c',
                'user.name=Test',
                '-c',
                'user.email=test@example.test',
                'commit',
                '-q',
                '-m',
                'later',
            ]);
            $descendant = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            synchronizerGit($worktree, ['reset', '-q', '--hard', $hostSha]);
            $guest = new WorktreeSynchronizerGuestFake($descendant);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-160'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and(array_column($guest->bundlePushes, 'header'))
                ->each->not->toContain("-{$descendant} ");
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('does not push bundles when each guest already has the host commit', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-140');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake($sha);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-140'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toBeEmpty()
                ->and(array_values(array_filter(
                    $guest->pushes,
                    fn (array $push): bool => str_contains($push['destination'], '/source/'),
                )))
                ->toHaveCount(6);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('batches independent probes and source transfer phases in dependency order', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-137');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $scripts = array_map(
                fn (string $name): string => $worktree.'/apps/e2e/resources/guest/'.$name,
                synchronizerRequiredGuestScriptNames(),
            );
            $markerHash = hash('sha256', implode('', array_map(
                static fn (string $path): string => (
                    basename($path)."\0".sprintf('%o', fileperms($path) & 07777)."\0".file_get_contents($path)."\0"
                ),
                $scripts,
            )));
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                $markerHash,
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
            );

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-137'),
                $worktree,
            );

            expect($guest->execBatches)
                ->toBe([
                    [
                        'guest-sha.gateway',
                        'guest-marker.gateway',
                        'guest-status.gateway',
                        'guest-hydration.gateway',
                        'guest-sha.app-dev',
                        'guest-marker.app-dev',
                        'guest-status.app-dev',
                        'guest-hydration.app-dev',
                        'script-marker.gateway',
                        'script-content.gateway',
                        'script-marker.app-dev',
                        'script-content.app-dev',
                        'script-marker.app-prod',
                        'script-content.app-prod',
                    ],
                    ['source-prepare.gateway', 'source-prepare.app-dev'],
                    ['source-ownership.gateway', 'source-ownership.app-dev'],
                    ['source-receive.gateway', 'source-receive.app-dev'],
                    ['source-hydrate.gateway', 'source-hydrate.app-dev'],
                    ['source-orbit-cli.gateway', 'source-orbit-cli.app-dev'],
                    ['source-preserve-env.gateway'],
                    ['source-cleanup.gateway', 'source-cleanup.app-dev'],
                ])
                ->and($guest->pushBatches)
                ->toBe([[
                    'source-push.gateway.archive',
                    'source-push.gateway.manifest',
                    'source-push.gateway.deletions',
                    'source-push.app-dev.archive',
                    'source-push.app-dev.manifest',
                    'source-push.app-dev.deletions',
                ]])
                ->and($guest->directExecs)
                ->toBeEmpty()
                ->and($guest->directPushes)
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('creates only the distinct prerequisite bundles required by cloned guests', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-132');
        try {
            $earlier = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD^'])[0]);
            $later = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            file_put_contents($worktree.'/README.md', "new head\n");
            synchronizerGit($worktree, ['add', 'README.md']);
            synchronizerGit($worktree, [
                '-c',
                'user.name=Test',
                '-c',
                'user.email=test@example.test',
                'commit',
                '-q',
                '-m',
                'new head',
            ]);
            $guest = new WorktreeSynchronizerGuestFake([
                'orbit-e2e-nck-132-aaaaaaaa-gateway' => $earlier,
                'orbit-e2e-nck-132-aaaaaaaa-app-dev' => $later,
            ]);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-132'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and($guest->bundlePushes[0]['header'])
                ->toContain("-{$earlier} ")
                ->and($guest->bundlePushes[1]['header'])
                ->toContain("-{$later} ")
                ->and(array_unique(array_column($guest->bundlePushes, 'destination')))
                ->toHaveCount(2);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('falls back per guest when an incremental prerequisite cannot be proved', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-133');
        try {
            $ancestor = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD^'])[0]);
            $unknown = str_repeat('f', 40);
            $guest = new WorktreeSynchronizerGuestFake([
                'orbit-e2e-nck-133-aaaaaaaa-gateway' => $ancestor,
                'orbit-e2e-nck-133-aaaaaaaa-app-dev' => $unknown,
            ]);

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-133'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and($guest->bundlePushes[0]['header'])
                ->toContain("-{$ancestor} ")
                ->and($guest->bundlePushes[1]['header'])
                ->not
                ->toContain("-{$unknown} ")
                ->and(array_column($guest->bundlePushes, 'instance'))
                ->toBe([
                    'orbit-e2e-nck-133-aaaaaaaa-gateway',
                    'orbit-e2e-nck-133-aaaaaaaa-app-dev',
                ])
                ->and($state->guestSha)
                ->toBe($state->hostSha);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('uses a known divergent guest commit as a prerequisite and falls back for malformed state', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-134');
        try {
            file_put_contents($root.'/README.md', "diverged\n");
            synchronizerGit($root, ['add', 'README.md']);
            synchronizerGit($root, [
                '-c',
                'user.name=Test',
                '-c',
                'user.email=test@example.test',
                'commit',
                '-q',
                '-m',
                'diverged',
            ]);
            $diverged = trim(synchronizerGit($root, ['rev-parse', 'HEAD'])[0]);
            $feature = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $common = trim(synchronizerGit($root, ['merge-base', $diverged, $feature])[0]);
            $guest = new WorktreeSynchronizerGuestFake([
                'orbit-e2e-nck-134-aaaaaaaa-gateway' => $diverged,
                'orbit-e2e-nck-134-aaaaaaaa-app-dev' => 'invalid',
            ]);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-134'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and($guest->bundlePushes[0]['header'])
                ->toContain("-{$common} ")
                ->and($guest->bundlePushes[1]['header'])
                ->not
                ->toContain("\n-")
                ->and(array_unique(array_column($guest->bundlePushes, 'destination')))
                ->toHaveCount(2);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('falls back to a full bundle when a guest does not report a commit', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-135');
        try {
            $ancestor = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD^'])[0]);
            $guest = new WorktreeSynchronizerGuestFake([
                'orbit-e2e-nck-135-aaaaaaaa-gateway' => $ancestor,
            ]);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-135'),
                $worktree,
            );

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and($guest->bundlePushes[0]['header'])
                ->toContain("-{$ancestor} ")
                ->and($guest->bundlePushes[1]['header'])
                ->not->toContain("\n-");
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('rejects mismatched source evidence from each transferred guest', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-136');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                evidenceShas: ['orbit-e2e-nck-136-aaaaaaaa-app-dev' => str_repeat('f', 40)],
            );

            expect(fn () => new WorktreeSynchronizer(
                $guest,
                $root,
                new OperationId(str_repeat('a', 32)),
            )->sync(featureTarget('NCK-136'), $worktree))
                ->toThrow(RuntimeException::class, 'Guest source evidence does not match the host.');
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });
    it('skips script transfer when every guest marker matches', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-125');
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
            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-125'),
                $worktree,
            );
            expect(array_filter($guest->pushes, fn (array $push): bool => str_contains(
                $push['destination'],
                '/scripts/',
            )))->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('does not take the source no-op when the guest checkout has drifted', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-142');
        try {
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
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                $hash,
                null,
                synchronizerScriptContentHashes($worktree),
                [],
                [featureTarget('NCK-142')->instance('gateway') => " M README.md\n"],
            );
            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-142'),
                $worktree,
            );
            expect(array_filter($guest->pushes, fn (array $push): bool => str_contains(
                $push['destination'],
                '/source/',
            )))->not->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('retries hydration after source receive succeeded on the previous sync', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-144');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $target = featureTarget('NCK-144');
            $guest = new WorktreeSynchronizerGuestFake($sha, failure: 'source-hydrate');
            $synchronizer = new WorktreeSynchronizer(
                $guest,
                $root,
                new OperationId(str_repeat('a', 32)),
            );

            expect(fn () => $synchronizer->sync($target, $worktree))
                ->toThrow(RuntimeException::class, 'Guest source hydration failed.');
            expect($guest->sourceStates)
                ->toHaveKeys([
                    $target->instance('gateway'),
                    $target->instance('app-dev'),
                ])
                ->and($guest->hydratedShas)
                ->toBeEmpty();

            $guest->failure = null;
            $synchronizer->sync($target, $worktree);
            $receiveCount = count(array_filter(
                $guest->execs,
                static fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'runuser'
                    && in_array('/usr/local/bin/receive-source.sh', $exec['command']->command, true)
                ),
            ));
            $hydrateCount = count(array_filter(
                $guest->execs,
                static fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'runuser'
                    && in_array('/usr/local/bin/hydrate-orbit.sh', $exec['command']->command, true)
                ),
            ));

            expect($receiveCount)
                ->toBe(4)
                ->and($hydrateCount)
                ->toBe(4)
                ->and($guest->hydratedShas)
                ->toBe([
                    $target->instance('gateway') => $sha,
                    $target->instance('app-dev') => $sha,
                ]);

            $synchronizer->sync($target, $worktree);

            expect(array_filter(
                $guest->execs,
                static fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'runuser'
                    && in_array('/usr/local/bin/receive-source.sh', $exec['command']->command, true)
                ),
            ))
                ->toHaveCount(4)
                ->and(array_filter(
                    $guest->execs,
                    static fn (array $exec): bool => (
                        ($exec['command']->command[0] ?? null) === 'runuser'
                        && in_array('/usr/local/bin/hydrate-orbit.sh', $exec['command']->command, true)
                    ),
                ))
                ->toHaveCount(4);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('reinstalls scripts when the marker matches but installed content drifts', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-126');
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
            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-126'),
                $worktree,
            );
            expect(synchronizerInstalledScripts($guest))->toHaveCount(30);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('installs changed guest scripts through one ordered batch boundary', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-141');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake($sha);
            $roles = ['gateway', 'app-dev', 'app-prod'];
            $scriptNames = synchronizerRequiredGuestScriptNames();
            $pushLabels = [];
            $installLabels = [];
            foreach ($roles as $role) {
                foreach ($scriptNames as $name) {
                    $pushLabels[] = "script-push.{$role}.{$name}";
                    $installLabels[] = "script-install.{$role}.{$name}";
                }
                $pushLabels[] = "script-push.{$role}.marker";
            }

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-141'),
                $worktree,
            );

            expect(array_slice($guest->execBatches, 1, 4))
                ->toBe([
                    ['script-prepare.gateway', 'script-prepare.app-dev', 'script-prepare.app-prod'],
                    $installLabels,
                    [
                        'script-marker-install.gateway',
                        'script-marker-install.app-dev',
                        'script-marker-install.app-prod',
                    ],
                    ['script-cleanup.gateway', 'script-cleanup.app-dev', 'script-cleanup.app-prod'],
                ])
                ->and($guest->pushBatches[0])
                ->toBe($pushLabels)
                ->and($guest->directExecs)
                ->toBeEmpty()
                ->and($guest->directPushes)
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('does not publish script markers and cleans every changed role after an install failure', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-142');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake(
                $sha,
                failure: 'script-install:orbit-e2e-nck-142-aaaaaaaa-app-dev',
            );

            expect(fn () => new WorktreeSynchronizer(
                $guest,
                $root,
                new OperationId(str_repeat('a', 32)),
            )->sync(featureTarget('NCK-142'), $worktree))
                ->toThrow(
                    RuntimeException::class,
                    'Guest script installation failed. Failed operations: '
                    .'script-install.app-dev.converge-app-dev.sh',
                );

            expect(array_filter(
                $guest->execBatches,
                fn (array $batch): bool => str_starts_with($batch[0] ?? '', 'script-marker-install.'),
            ))
                ->toBeEmpty()
                ->and($guest->execBatches[array_key_last($guest->execBatches)])
                ->toBe(['script-cleanup.gateway', 'script-cleanup.app-dev', 'script-cleanup.app-prod'])
                ->and(array_filter(
                    $guest->execBatches,
                    fn (array $batch): bool => str_starts_with($batch[0] ?? '', 'source-'),
                ))
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('reports script installation and cleanup failures without masking either failure', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-143');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake($sha, failure: [
                'script-install:orbit-e2e-nck-143-aaaaaaaa-app-dev',
                'script-cleanup:orbit-e2e-nck-143-aaaaaaaa-app-prod',
            ]);

            try {
                new WorktreeSynchronizer(
                    $guest,
                    $root,
                    new OperationId(str_repeat('a', 32)),
                )->sync(featureTarget('NCK-143'), $worktree);
                $this->fail('Expected script installation to fail.');
            } catch (RuntimeException $exception) {
                expect($exception->getMessage())
                    ->toContain(
                        'Primary operation failed: Guest script installation failed. Failed operations: '
                        .'script-install.app-dev.converge-app-dev.sh',
                    )
                    ->toContain('cleanup also failed: Guest script staging cleanup failed. Failed operations: '
                    .'script-cleanup.app-prod.');
            }
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('installs scripts before hydration and cleans staging', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-123');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha);
        try {
            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-123'),
                $worktree,
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
                ->toHaveCount(30)
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
                ->toHaveCount(30)
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
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-127');
        try {
            unlink($worktree.'/apps/e2e/resources/guest/receive-source.sh');
            $guest = new WorktreeSynchronizerGuestFake(str_repeat('a', 40));
            expect(fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-127'),
                $worktree,
            ))
                ->toThrow(RuntimeException::class, 'Guest script inventory is invalid.');
            expect($guest->execs)->toBeEmpty()->and($guest->pushes)->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('rejects the primary checkout for feature synchronization before guest mutation', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-129');
        $guest = new WorktreeSynchronizerGuestFake(str_repeat('a', 40));
        try {
            expect(fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-129'),
                $root,
            ))
                ->toThrow(InvalidArgumentException::class, 'linked worktree');
            expect($guest->execs)->toBeEmpty()->and($guest->pushes)->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('accepts the primary checkout for topology snapshot validation', function () {
        $root = createSynchronizerPrimaryFixture('NCK-130');
        $sha = trim(synchronizerGit($root, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha);
        try {
            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                TopologyTarget::topologySnapshot(),
                $root,
            );
            expect($state->hostSha)->toBe($sha)->and($guest->execs)->not->toBeEmpty();
        } finally {
            removeSynchronizerFixture($root);
        }
    });

    it('cleans source staging after receive failure', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-128');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha, null, 'source-receive');
        try {
            expect(fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-128'),
                $worktree,
            ))
                ->toThrow(RuntimeException::class);
            $cleanups = array_values(array_filter(
                $guest->execs,
                fn (array $exec): bool => (
                    ($exec['command']->command[0] ?? null) === 'rm'
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
            expect($cleanups)
                ->toHaveCount(2)
                ->and(array_column($cleanups, 'instance'))
                ->toBe([
                    'orbit-e2e-nck-128-aaaaaaaa-gateway',
                    'orbit-e2e-nck-128-aaaaaaaa-app-dev',
                ])
                ->and(array_map(
                    fn (array $cleanup): mixed => $cleanup['command']->command[array_key_last(
                        $cleanup['command']->command,
                    )],
                    $cleanups,
                ))
                ->each
                ->toBe(dirname((string) $sourcePush['destination']))
                ->and(array_filter(
                    $guest->execBatches,
                    fn (array $batch): bool => in_array('source-hydrate.gateway', $batch, true),
                ))
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('reports the primary transfer failure and secondary batched cleanup failure', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-138');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake($sha, failure: [
            'source-receive:orbit-e2e-nck-138-aaaaaaaa-gateway',
            'source-cleanup:orbit-e2e-nck-138-aaaaaaaa-app-dev',
        ]);
        try {
            expect(fn () => new WorktreeSynchronizer(
                $guest,
                $root,
                new OperationId(str_repeat('a', 32)),
            )->sync(featureTarget('NCK-138'), $worktree))
                ->toThrow(
                    RuntimeException::class,
                    'Primary operation failed: Guest source installation failed. Failed operations: '
                    .'source-receive.gateway.; cleanup also failed: Guest source staging cleanup failed. '
                    .'Failed operations: source-cleanup.app-dev.',
                );
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('does not start dependent source phases after a batched prepare failure', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-139');
        $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
        $guest = new WorktreeSynchronizerGuestFake(
            $sha,
            failure: 'source-prepare:orbit-e2e-nck-139-aaaaaaaa-app-dev',
        );
        try {
            expect(fn () => new WorktreeSynchronizer(
                $guest,
                $root,
                new OperationId(str_repeat('a', 32)),
            )->sync(featureTarget('NCK-139'), $worktree))
                ->toThrow(RuntimeException::class, 'Guest source staging failed.');

            $sourceBatches = array_values(array_filter(
                $guest->execBatches,
                fn (array $batch): bool => str_starts_with($batch[0] ?? '', 'source-'),
            ));
            expect($sourceBatches)
                ->toBe([
                    ['source-prepare.gateway', 'source-prepare.app-dev'],
                    ['source-cleanup.gateway', 'source-cleanup.app-dev'],
                ])
                ->and(array_filter(
                    $guest->pushBatches,
                    fn (array $batch): bool => str_starts_with($batch[0] ?? '', 'source-push.'),
                ))
                ->toBeEmpty();
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });
    it('preserves the hydrated gateway environment outside the checkout as root', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-140');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake(
                ['orbit-e2e-nck-140-aaaaaaaa-gateway' => '', 'orbit-e2e-nck-140-aaaaaaaa-app-dev' => $sha],
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
            );
            $target = featureTarget('NCK-140');

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync($target, $worktree);

            $preserve = array_values(array_filter(
                $guest->execs,
                static fn (array $exec): bool => in_array(
                    '/var/lib/orbit-e2e/gateway.env',
                    $exec['command']->command,
                    true,
                ),
            ));
            expect($guest->preservedEnvironments)
                ->toBe([$target->instance('gateway')])
                ->and($preserve[0]['command']->command)
                ->toBe([
                    'install',
                    '-o',
                    'root',
                    '-g',
                    'root',
                    '-m',
                    '0600',
                    '--',
                    '/home/orbit/orbit/apps/gateway/.env',
                    '/var/lib/orbit-e2e/gateway.env',
                ]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('fails closed and cleans staging when the gateway environment cannot be preserved', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-141');
        try {
            $guest = new WorktreeSynchronizerGuestFake(
                '',
                failure: 'source-preserve-env',
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
            );

            expect(fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))->sync(
                featureTarget('NCK-141'),
                $worktree,
            ))
                ->toThrow(RuntimeException::class, 'gateway environment preservation failed')
                ->and(end($guest->execBatches))
                ->toBe(['source-cleanup.gateway', 'source-cleanup.app-dev']);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('records the host identity of a mounted worktree without transferring files', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-142');
        try {
            $sha = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
            $guest = new WorktreeSynchronizerGuestFake('');
            $target = featureTarget('NCK-142');
            $synchronizer = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)));

            $clean = $synchronizer->syncWorkingTree($target, $worktree);
            $cleanTree = new GitRepository($worktree)->effectiveTreeHash();
            $pointer = hash('sha256', (string) file_get_contents($worktree.'/.git'));
            $marker = ['sha' => $sha, 'tree' => $cleanTree, 'mounted' => true, 'git_pointer_sha256' => $pointer];

            expect($clean->toArray())
                ->toBe([
                    'host_sha' => $sha,
                    'guest_sha' => $sha,
                    'dirty' => false,
                    'tree_hash' => null,
                    'overlay_paths' => [],
                    'operation_id' => str_repeat('a', 32),
                    'mounted' => true,
                    'git_pointer_sha256' => $pointer,
                ])
                ->and($pointer)
                ->toMatch('/\A[0-9a-f]{64}\z/')
                ->and($guest->sourceMarkers)
                ->toBe([$target->instance('gateway') => $marker, $target->instance('app-dev') => $marker])
                ->and($guest->execBatches)
                ->toBe([['source-marker.gateway', 'source-marker.app-dev']])
                ->and($guest->pushes)
                ->toBe([])
                ->and($guest->directExecs)
                ->toBe([]);

            file_put_contents($worktree.'/overlay.txt', "dirty\n");
            $dirty = $synchronizer->syncWorkingTree($target, $worktree);
            $dirtyTree = new GitRepository($worktree)->effectiveTreeHash();

            expect($dirty->toArray())
                ->toMatchArray([
                    'host_sha' => $sha,
                    'dirty' => true,
                    'tree_hash' => $dirtyTree,
                    'overlay_paths' => ['overlay.txt'],
                    'mounted' => true,
                    'git_pointer_sha256' => $pointer,
                ])
                ->and($guest->sourceMarkers[$target->instance('app-dev')])
                ->toBe(['sha' => $sha, 'tree' => $dirtyTree, 'mounted' => true, 'git_pointer_sha256' => $pointer])
                ->and($guest->pushes)
                ->toBe([]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('fails closed when a mounted source marker cannot be written', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-143');
        try {
            $guest = new WorktreeSynchronizerGuestFake(
                '',
                failure: 'source-marker:orbit-e2e-nck-143-aaaaaaaa-app-dev',
            );

            expect(fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncWorkingTree(featureTarget('NCK-143'), $worktree))
                ->toThrow(
                    RuntimeException::class,
                    'source marker write failed. Failed operations: source-marker.app-dev',
                );
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('validates the mounted worktree exactly as a transferred one', function () {
        [$root, $worktree] = createSynchronizerRepositoryFixture('NCK-144');
        try {
            $guest = new WorktreeSynchronizerGuestFake('');
            $synchronizer = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)));

            expect(fn () => $synchronizer->syncWorkingTree(featureTarget('NCK-144'), $root))
                ->toThrow(InvalidArgumentException::class, 'registered linked worktree')
                ->and(fn () => $synchronizer->syncWorkingTree(featureTarget('NCK-999'), $worktree))
                ->toThrow(InvalidArgumentException::class, 'branch does not match')
                ->and($guest->execs)
                ->toBe([]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });
});

/**
 * A worktree whose HEAD is the clean candidate commit, then a dirty overlay and
 * a later commit on top; the candidate stays reachable from the branch.
 *
 * @return array{root:string, worktree:string, candidate:string, candidateTree:string, later:string}
 */
function createSynchronizerCandidateFixture(string $issue): array
{
    [$root, $worktree] = createSynchronizerRepositoryFixture($issue);
    $candidate = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
    $candidateTree = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD^{tree}'])[0]);
    file_put_contents($worktree.'/later.txt', "later\n");
    synchronizerGit($worktree, ['add', 'later.txt']);
    synchronizerGit($worktree, [
        '-c',
        'user.name=Test',
        '-c',
        'user.email=test@example.test',
        'commit',
        '-q',
        '-m',
        'later',
    ]);
    $later = trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]);
    file_put_contents($worktree.'/README.md', "dirty overlay\n");
    file_put_contents($worktree.'/untracked.txt', "untracked\n");

    return compact('root', 'worktree', 'candidate', 'candidateTree', 'later');
}

/** The marker hash the host publishes for the guest scripts of one checkout. */
function synchronizerGuestScriptMarker(string $worktree): string
{
    $scripts = array_map(
        static fn (string $name): string => $worktree.'/apps/e2e/resources/guest/'.$name,
        synchronizerRequiredGuestScriptNames(),
    );

    return hash('sha256', implode('', array_map(
        static fn (string $path): string => (
            basename($path)."\0".sprintf('%o', fileperms($path) & 07777)."\0".file_get_contents($path)."\0"
        ),
        $scripts,
    )));
}

/**
 * The `runuser` git or script invocations that carry one exact argument.
 *
 * @return list<list<string>>
 */
function synchronizerOrbitCommands(WorktreeSynchronizerGuestFake $guest, string $argument): array
{
    return array_values(array_map(
        static fn (array $exec): array => $exec['command']->command,
        array_filter(
            $guest->execs,
            static fn (array $exec): bool => (
                ($exec['command']->command[0] ?? null) === 'runuser'
                && in_array($argument, $exec['command']->command, true)
            ),
        ),
    ));
}

/** The candidate's guest scripts as `git show` sees them, keyed by script name. @return array<string, string> */
function synchronizerCandidateScripts(string $worktree, string $candidate): array
{
    $scripts = [];
    foreach (synchronizerRequiredGuestScriptNames() as $name) {
        $scripts[$name] = synchronizerGit($worktree, ['show', "{$candidate}:apps/e2e/resources/guest/{$name}"])[0];
    }

    return $scripts;
}

/** @param array<string, string> $scripts */
function synchronizerCandidateScriptMarker(array $scripts): string
{
    $context = hash_init('sha256');
    foreach ($scripts as $name => $content) {
        hash_update($context, $name."\0".'755'."\0".$content."\0");
    }

    return hash_final($context);
}

/** @param array<string, string> $scripts */
function synchronizerCandidateScriptContentHashes(array $scripts): string
{
    $lines = [];
    foreach ($scripts as $name => $content) {
        $lines[] = hash('sha256', $content).'  /usr/local/bin/'.$name;
    }

    return implode("\n", $lines);
}

/**
 * A fake whose checkout roles already hold the candidate scripts and will prove the candidate tree.
 *
 * @mago-expect lint:excessive-parameter-list Each guest outcome stays independently configurable per test.
 */
function candidateGuestFake(
    TopologyTarget $target,
    string $worktree,
    string $candidate,
    string $candidateTree,
    string|array $sha = '',
    string|array|null $failure = null,
    array $guestStatuses = [],
): WorktreeSynchronizerGuestFake {
    $scripts = synchronizerCandidateScripts($worktree, $candidate);

    return new WorktreeSynchronizerGuestFake(
        $sha,
        synchronizerCandidateScriptMarker($scripts),
        failure: $failure,
        installedScriptsHash: synchronizerCandidateScriptContentHashes($scripts),
        guestStatuses: $guestStatuses,
        guestTrees: [
            $target->instance('gateway') => $candidateTree,
            $target->instance('app-dev') => $candidateTree,
        ],
    );
}

describe('WorktreeSynchronizer::syncCommit', function () {
    it('uses physical recipe Nodes for checkout transfer and guest script verification', function () {
        $fixture = createSynchronizerCandidateFixture('SCN-1');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = TopologyTarget::disposableCold(
                'SCN-1',
                new \App\E2E\Value\AttemptId(str_repeat('a', 32)),
                TopologyRecipe::coldAcceptance(),
            );
            $guest = candidateGuestFake($target, $worktree, $candidate, $fixture['candidateTree']);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);

            expect(array_column($guest->bundlePushes, 'instance'))
                ->toBe([$target->instance('gateway'), $target->instance('operator')])
                ->and($guest->execBatches[0])
                ->toContain(
                    'guest-sha.operator',
                    'script-marker.operator',
                    'script-marker.extra',
                    'script-content.extra',
                )
                ->not->toContain('guest-sha.app-dev');
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('transfers only the exact candidate commit and ignores later host worktree state', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-150');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = featureTarget('NCK-150');
            $treeHash = hash('sha256', $fixture['candidateTree']);
            $guest = candidateGuestFake($target, $worktree, $candidate, $fixture['candidateTree']);

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);

            $emptyFile = hash('sha256', '');
            $receives = synchronizerOrbitCommands($guest, '/usr/local/bin/receive-source.sh');
            expect($state)
                ->toBeInstanceOf(CandidateSync::class)
                ->and($state->toArray())
                ->toBe([
                    'candidate_sha' => $candidate,
                    'candidate_tree' => $fixture['candidateTree'],
                    'guest_script_hash' => synchronizerCandidateScriptMarker(
                        synchronizerCandidateScripts($worktree, $candidate),
                    ),
                    'operation_id' => str_repeat('a', 32),
                ])
                ->and($fixture['later'])
                ->not->toBe($candidate)->and(trim(synchronizerGit($worktree, ['rev-parse', 'HEAD'])[0]))->toBe(
                    $fixture['later'],
                )->and(trim(synchronizerGit($worktree, ['status', '--porcelain'])[0]))
                ->not->toBe('')->and($guest->bundlePushes)->toHaveCount(2)->and(array_column(
                    $guest->bundlePushes,
                    'header',
                ))
                ->each->toMatch(
                    '/\\A# v2 git bundle\\n'.$candidate.' refs\\/orbit\\/e2e-source\\/[0-9a-f]{32}\\z/',
                )->and(array_column($guest->bundlePushes, 'header'))
                ->each->not->toContain($fixture['later'])->and(array_map(static fn (array $push): string => basename(
                    $push['destination'],
                ), $guest->pushes))->toContain("{$emptyFile}.tar", "{$emptyFile}.paths", "{$emptyFile}.deletions")->and(
                    $receives,
                )->toHaveCount(2)->and(array_column($receives, 8))->toBe([$candidate, $candidate])->and(array_map(
                    static fn (array $argv): string => (string) end($argv),
                    $receives,
                ))->toBe([
                    $treeHash,
                    $treeHash,
                ])->and($guest->sourceMarkers)->toBe([])->and($guest->directExecs)->toBe([]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('detaches every checkout at the candidate and proves it before hydration', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-156');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = featureTarget('NCK-156');
            $treeHash = hash('sha256', $fixture['candidateTree']);
            $guest = candidateGuestFake($target, $worktree, $candidate, $fixture['candidateTree']);

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);

            $detaches = synchronizerOrbitCommands($guest, '--detach');
            expect($detaches)
                ->toHaveCount(2)
                ->and($detaches[0])
                ->toBe([
                    'runuser',
                    '-u',
                    'orbit',
                    '--',
                    'env',
                    'HOME=/home/orbit',
                    'git',
                    '-C',
                    '/home/orbit/orbit',
                    'checkout',
                    '--detach',
                    '--quiet',
                    $candidate,
                ])
                ->and($guest->sourceStates)
                ->toBe([
                    $target->instance('gateway') => ['sha' => $candidate, 'tree' => $treeHash],
                    $target->instance('app-dev') => ['sha' => $candidate, 'tree' => $treeHash],
                ])
                ->and($guest->hydratedShas)
                ->toBe([$target->instance('gateway') => $candidate, $target->instance('app-dev') => $candidate])
                ->and($guest->preservedEnvironments)
                ->toBe([$target->instance('gateway')])
                ->and($guest->execBatches)
                ->toBe([
                    [
                        'guest-sha.gateway',
                        'guest-marker.gateway',
                        'guest-status.gateway',
                        'guest-hydration.gateway',
                        'guest-sha.app-dev',
                        'guest-marker.app-dev',
                        'guest-status.app-dev',
                        'guest-hydration.app-dev',
                        'script-marker.gateway',
                        'script-content.gateway',
                        'script-marker.app-dev',
                        'script-content.app-dev',
                        'script-marker.app-prod',
                        'script-content.app-prod',
                    ],
                    ['source-prepare.gateway', 'source-prepare.app-dev'],
                    ['source-ownership.gateway', 'source-ownership.app-dev'],
                    ['source-receive.gateway', 'source-receive.app-dev'],
                    ['source-detach.gateway', 'source-detach.app-dev'],
                    [
                        'source-head.gateway',
                        'source-tree.gateway',
                        'source-status.gateway',
                        'source-head.app-dev',
                        'source-tree.app-dev',
                        'source-status.app-dev',
                    ],
                    ['source-hydrate.gateway', 'source-hydrate.app-dev'],
                    ['source-orbit-cli.gateway', 'source-orbit-cli.app-dev'],
                    ['source-preserve-env.gateway'],
                    ['source-cleanup.gateway', 'source-cleanup.app-dev'],
                ]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('installs the guest scripts of the candidate commit, not the working tree', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-157');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = featureTarget('NCK-157');
            $script = $worktree.'/apps/e2e/resources/guest/receive-source.sh';
            file_put_contents($script, "# dirty host edit\n", FILE_APPEND);
            $candidateScripts = synchronizerCandidateScripts($worktree, $candidate);
            expect(synchronizerScriptContentHashes($worktree))
                ->not
                ->toBe(synchronizerCandidateScriptContentHashes($candidateScripts));

            // Guests hold the working-tree scripts: the candidate's differ, so they must be reinstalled.
            $guest = new WorktreeSynchronizerGuestFake(
                '',
                synchronizerGuestScriptMarker($worktree),
                installedScriptsHash: synchronizerScriptContentHashes($worktree),
                guestTrees: [
                    $target->instance('gateway') => $fixture['candidateTree'],
                    $target->instance('app-dev') => $fixture['candidateTree'],
                ],
            );
            $pushedScripts = [];
            $guest->onPush = function (string $source, string $destination) use (&$pushedScripts): void {
                if (str_ends_with($destination, '/receive-source.sh')) {
                    $pushedScripts[] = (string) file_get_contents($source);
                }
            };

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);

            expect($state->guestScriptHash)
                ->toBe(synchronizerCandidateScriptMarker($candidateScripts))
                ->and($guest->execBatches[1])
                ->toBe(['script-prepare.gateway', 'script-prepare.app-dev', 'script-prepare.app-prod'])
                ->and($pushedScripts)
                ->toHaveCount(3)
                ->and(array_unique($pushedScripts))
                ->toBe([$candidateScripts['receive-source.sh']])
                ->and($pushedScripts[0])
                ->not->toContain('dirty host edit');

            // A guest already holding the candidate scripts needs no install.
            $matching = candidateGuestFake($target, $worktree, $candidate, $fixture['candidateTree']);
            new WorktreeSynchronizer($matching, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);
            expect($matching->execBatches[1])->toBe(['source-prepare.gateway', 'source-prepare.app-dev']);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('refuses to sync a candidate into the topology snapshot before any guest interaction', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-158');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $guest = new WorktreeSynchronizerGuestFake('');

            expect(
                fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                    ->syncCommit(TopologyTarget::topologySnapshot(), $worktree, $candidate),
            )
                ->toThrow(
                    InvalidArgumentException::class,
                    'A proof candidate cannot be synced into the topology snapshot.',
                )
                ->and($guest->execs)
                ->toBe([])
                ->and($guest->pushes)
                ->toBe([]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('uses a known guest commit as the bundle prerequisite and resets a guest already at the candidate', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-151');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = featureTarget('NCK-151');
            $ancestor = trim(synchronizerGit($worktree, ['rev-parse', $candidate.'^'])[0]);
            $guest = candidateGuestFake(
                $target,
                $worktree,
                $candidate,
                $fixture['candidateTree'],
                sha: [$target->instance('gateway') => $ancestor, $target->instance('app-dev') => $candidate],
                guestStatuses: [$target->instance('app-dev') => "?? stray.txt\n"],
            );

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);

            $receives = synchronizerOrbitCommands($guest, '/usr/local/bin/receive-source.sh');
            expect($state->candidateSha)
                ->toBe($candidate)
                ->and($guest->bundlePushes)
                ->toHaveCount(1)
                ->and($guest->bundlePushes[0]['instance'])
                ->toBe($target->instance('gateway'))
                ->and($guest->bundlePushes[0]['header'])
                ->toContain("-{$ancestor} ")
                ->and(array_column($receives, 9))
                ->toBe([$guest->bundlePushes[0]['destination'], '-'])
                ->and($guest->receivedShas)
                ->toBe([$target->instance('gateway') => $candidate, $target->instance('app-dev') => $candidate]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('sends a full bundle when the guest commit is not an ancestor of the candidate', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-159');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = featureTarget('NCK-159');
            $guest = candidateGuestFake(
                $target,
                $worktree,
                $candidate,
                $fixture['candidateTree'],
                sha: [
                    $target->instance('gateway') => $fixture['later'],
                    $target->instance('app-dev') => $fixture['later'],
                ],
            );

            new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $worktree, $candidate);

            expect($guest->bundlePushes)
                ->toHaveCount(2)
                ->and(array_column($guest->bundlePushes, 'header'))
                ->each
                ->toMatch('/\\A# v2 git bundle\\n'.$candidate.' refs\\/orbit\\/e2e-source\\/[0-9a-f]{32}\\z/')
                ->and($guest->receivedShas)
                ->toBe([$target->instance('gateway') => $candidate, $target->instance('app-dev') => $candidate]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    });

    it('rejects an unreachable, inexact, or foreign candidate before any guest interaction', function () {
        $fixture = createSynchronizerCandidateFixture('NCK-152');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        $foreign = createSynchronizerPrimaryFixture('NCK-153');
        try {
            $orphanOutput = implode("\n", synchronizerGit($worktree, [
                '-c',
                'user.name=Test',
                '-c',
                'user.email=test@example.test',
                'commit-tree',
                $candidate.'^{tree}',
                '-m',
                'orphan',
            ]));
            preg_match('/[0-9a-f]{40}/', $orphanOutput, $orphan);
            $unreachable = $orphan[0] ?? '';
            expect($unreachable)->toMatch('/\A[0-9a-f]{40}\z/');
            $guest = new WorktreeSynchronizerGuestFake('');
            $synchronizer = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)));
            $target = featureTarget('NCK-152');

            expect(fn () => $synchronizer->syncCommit($target, $worktree, $unreachable))
                ->toThrow(InvalidArgumentException::class, 'not reachable')
                ->and(fn () => $synchronizer->syncCommit($target, $worktree, substr($candidate, 0, 12)))
                ->toThrow(InvalidArgumentException::class, 'exact full SHA')
                ->and(fn () => $synchronizer->syncCommit($target, $worktree, strtoupper($candidate)))
                ->toThrow(InvalidArgumentException::class, 'exact full SHA')
                ->and(fn () => $synchronizer->syncCommit(
                    $target,
                    $foreign,
                    trim(
                        synchronizerGit($foreign, ['rev-parse', 'HEAD'])[0],
                    ),
                ))
                ->toThrow(InvalidArgumentException::class, 'repository identity does not match')
                ->and($guest->execs)
                ->toBe([])
                ->and($guest->pushes)
                ->toBe([]);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
            removeSynchronizerFixture($foreign);
        }
    });

    it('accepts the candidate from the primary checkout without a worktree or branch rule', function () {
        $root = createSynchronizerPrimaryFixture('NCK-154');
        try {
            $candidate = trim(synchronizerGit($root, ['rev-parse', 'HEAD'])[0]);
            $candidateTree = trim(synchronizerGit($root, ['rev-parse', 'HEAD^{tree}'])[0]);
            file_put_contents($root.'/README.md', "dirty primary\n");
            $target = featureTarget('NCK-999');
            $guest = new WorktreeSynchronizerGuestFake(
                $candidate,
                installedScriptsHash: synchronizerScriptContentHashes($root),
                guestTrees: [
                    $target->instance('gateway') => $candidateTree,
                    $target->instance('app-dev') => $candidateTree,
                ],
            );

            $state = new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                ->syncCommit($target, $root, $candidate);

            expect($state->candidateSha)
                ->toBe($candidate)
                ->and($guest->bundlePushes)
                ->toBe([])
                ->and($guest->receivedShas)
                ->toHaveCount(2);
        } finally {
            removeSynchronizerFixture($root);
        }
    });

    it('fails closed and cleans staging when a guest checkout does not prove the candidate', function (
        string $failure,
        string $message,
    ) {
        $fixture = createSynchronizerCandidateFixture('NCK-155');
        ['root' => $root, 'worktree' => $worktree, 'candidate' => $candidate] = $fixture;
        try {
            $target = featureTarget('NCK-155');
            $trees = [
                $target->instance('gateway') => $fixture['candidateTree'],
                $target->instance('app-dev') => $failure === 'source-tree'
                    ? str_repeat('e', 40)
                    : $fixture['candidateTree'],
            ];
            $scripts = synchronizerCandidateScripts($worktree, $candidate);
            $guest = new WorktreeSynchronizerGuestFake(
                '',
                synchronizerCandidateScriptMarker($scripts),
                failure: $failure === 'source-tree' ? null : $failure.':'.$target->instance('app-dev'),
                installedScriptsHash: synchronizerCandidateScriptContentHashes($scripts),
                guestTrees: $trees,
            );

            expect(
                fn () => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('a', 32)))
                    ->syncCommit($target, $worktree, $candidate),
            )
                ->toThrow(RuntimeException::class, $message)
                ->and($guest->hydratedShas)
                ->toBe([])
                ->and(end($guest->execBatches))
                ->toBe(['source-cleanup.gateway', 'source-cleanup.app-dev']);
        } finally {
            destroySynchronizerRepositoryFixture($root, $worktree);
        }
    })->with([
        'detach failure' => [
            'source-detach',
            'Guest checkout detach failed. Failed operations: source-detach.app-dev.',
        ],
        'HEAD drift' => ['source-head', 'Guest checkout [app-dev] HEAD does not match the candidate commit.'],
        'tree drift' => ['source-tree', 'Guest checkout [app-dev] tree does not match the candidate tree.'],
        'dirty checkout' => ['source-status', 'Guest checkout [app-dev] is not clean after the candidate checkout.'],
    ]);
});
