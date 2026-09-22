<?php

declare(strict_types=1);

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\Hibernation\HibernationDirectoryEnsure;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

it('writes the awake marker after creating traversable tmpfs and log directories', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    $store->markAwake(hibernation_marker_node(), RuntimeHibernation::key(6));

    expect($ssh->commands[0]->arguments)
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            RuntimeHibernation::MarkerDirectory,
            RuntimeHibernation::AccessLogDirectory,
        ])
        ->and($ssh->commands[0]->input)
        ->toBe(HibernationDirectoryEnsure::script())
        ->and(array_map(static fn ($command): array => $command->arguments, array_slice($ssh->commands, 1)))
        ->toBe([
            ['sudo', 'touch', '--', RuntimeHibernation::awakePath('app-instance-6')],
            ['sudo', 'chmod', '0644', '--', RuntimeHibernation::awakePath('app-instance-6')],
        ]);
});

it('uses the later of the access log and awake marker as last activity', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "present:100\n", '', 1, false),
        new CommandResult(0, "present:200\n", '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    expect($store->lastActivityUnix(hibernation_marker_node(), RuntimeHibernation::key(6)))
        ->toBe(200)
        ->and(array_map(static fn ($command): array => $command->arguments, $ssh->commands))
        ->toBe([
            ['sudo', 'python3', '-', RuntimeHibernation::accessLogPath('app-instance-6')],
            ['sudo', 'python3', '-', RuntimeHibernation::awakePath('app-instance-6')],
        ]);
    expect($ssh->commands[0]->maxOutputBytes)->toBe(128);
    expect($ssh->commands[0]->timeout)->toBe(10.0);
});

it('writes the durable cold marker under the access-log directory', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    $store->markCold(hibernation_marker_node(), RuntimeHibernation::key(6));

    expect($ssh->commands[0]->arguments)
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            RuntimeHibernation::MarkerDirectory,
            RuntimeHibernation::AccessLogDirectory,
        ])
        ->and(array_map(static fn ($command): array => $command->arguments, array_slice($ssh->commands, 1)))
        ->toBe([
            ['sudo', 'touch', '--', RuntimeHibernation::coldPath('app-instance-6')],
            ['sudo', 'chmod', '0644', '--', RuntimeHibernation::coldPath('app-instance-6')],
        ]);
});

it('treats a present awake marker as awake and a missing marker as asleep', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "present:200\n", '', 1, false),
        new CommandResult(0, "absent\n", '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );
    $node = hibernation_marker_node();

    expect($store->isAwake($node, RuntimeHibernation::key(6)))
        ->toBeTrue()
        ->and($store->isAwake($node, RuntimeHibernation::key(6)))
        ->toBeFalse()
        ->and(array_map(static fn ($command): array => $command->arguments, $ssh->commands))
        ->toBe([
            ['sudo', 'python3', '-', RuntimeHibernation::awakePath('app-instance-6')],
            ['sudo', 'python3', '-', RuntimeHibernation::awakePath('app-instance-6')],
        ]);
});

it('treats a missing log and missing awake marker as no activity', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "absent\n", '', 1, false),
        new CommandResult(0, "absent\n", '', 1, false),
    ]);
    $store = new RemoteHibernationMarkerStore(
        ssh: $ssh,
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    expect($store->lastActivityUnix(hibernation_marker_node(), RuntimeHibernation::key(6)))
        ->toBeNull();
});

it('rejects a failed or ambiguous marker observation without exposing remote output', function (int $exitCode, string $stdout, string $stderr, bool $truncated): void {
    $store = new RemoteHibernationMarkerStore(
        ssh: new AppDevFakeSshExecutor([new CommandResult($exitCode, $stdout, $stderr, 1, $truncated)]),
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    expect(fn () => $store->isAwake(hibernation_marker_node(), 'app-instance-6'))
        ->toThrow(function (HibernationException $exception): void {
            expect($exception->errorCode)->toBe('hibernation.marker_failed');
            expect($exception->getMessage())->toBe('Hibernation marker read failed on Node [app-dev].');
        });
})->with([
    'SSH failure' => [255, '', 'private remote diagnostic', false],
    'unclassified read failure' => [1, '', 'permission denied', false],
    'failed absent response' => [1, "absent\n", '', false],
    'empty success' => [0, '', '', false],
    'legacy timestamp' => [0, "200\n", '', false],
    'malformed timestamp' => [0, "present:invalid\n", '', false],
    'negative timestamp' => [0, "present:-1\n", '', false],
    'overflow timestamp' => [0, 'present:'.PHP_INT_MAX."0\n", '', false],
    'extra response' => [0, "absent\npresent:200\n", '', false],
    'unexpected stderr' => [0, "absent\n", 'private remote diagnostic', false],
    'truncated absence' => [0, "absent\n", '', true],
    'truncated timestamp' => [0, "present:200\n", '', true],
]);

it('accepts a complete timestamp without conflating epoch zero with absence', function (int $timestamp): void {
    $store = new RemoteHibernationMarkerStore(
        ssh: new AppDevFakeSshExecutor([
            new CommandResult(0, "present:{$timestamp}\n", '', 1, false),
            new CommandResult(0, "absent\n", '', 1, false),
        ]),
        keys: new HibernationFakeSshKeyProvider,
        knownHosts: new HibernationFakeKnownHostsStore,
    );

    expect($store->lastActivityUnix(hibernation_marker_node(), 'app-instance-6'))->toBe($timestamp);
})->with([0, 200, PHP_INT_MAX]);

it('distinguishes native absent files from invalid parent paths', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'orbit-marker-read-');
    expect($file)->toBeString();
    touch($file, 200);
    $ssh = new HibernationNativeReadExecutor($file);
    $store = new RemoteHibernationMarkerStore($ssh, new HibernationFakeSshKeyProvider, new HibernationFakeKnownHostsStore);

    try {
        expect($store->lastActivityUnix(hibernation_marker_node(), 'app-instance-6'))->toBe(200);
        $ssh->path = $file.'-absent';
        expect($store->isAwake(hibernation_marker_node(), 'app-instance-6'))->toBeFalse();
        $ssh->path = $file.'/invalid-parent';
        expect(fn () => $store->isCold(hibernation_marker_node(), 'app-instance-6'))
            ->toThrow(HibernationException::class, 'Hibernation marker read failed');
    } finally {
        unlink($file);
    }
});

it('does not treat a native inaccessible parent directory as an absent marker', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('A root process can traverse a mode-000 directory.');
    }

    $directory = sys_get_temp_dir().'/orbit-marker-denied-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    chmod($directory, 0000);
    $store = new RemoteHibernationMarkerStore(
        new HibernationNativeReadExecutor($directory.'/marker'),
        new HibernationFakeSshKeyProvider,
        new HibernationFakeKnownHostsStore,
    );

    try {
        expect(fn () => $store->isAwake(hibernation_marker_node(), 'app-instance-6'))
            ->toThrow(HibernationException::class, 'Hibernation marker read failed');
    } finally {
        chmod($directory, 0700);
        rmdir($directory);
    }
});

function hibernation_marker_node(): Node
{
    return new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
}

final class HibernationFakeSshKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class HibernationFakeKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class HibernationNativeReadExecutor implements SshExecutor
{
    public function __construct(public string $path) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        expect(array_slice($command->arguments, 0, 3))->toBe(['sudo', 'python3', '-']);
        expect($command->protectedInput)->not->toBeNull();
        $process = new Process(['python3', '-', $this->path], input: $command->protectedInput->stream(), timeout: 5);
        $process->run();

        return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput(), 1, false);
    }
}
