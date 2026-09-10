<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteAppInstanceEnvironmentAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('uses the same bounded remote check without reading environment contents', function (): void {
    $ssh = new EnvironmentObservationSshExecutor([
        new CommandResult(0, "OK\n", '', 1, false),
    ]);
    $access = environment_remote_access($ssh);

    $access->assertEnvironmentReadable(environment_access_context('/srv/apps/example'));

    expect($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)
        ->toContain('read-check')
        ->and($ssh->commands[0]->maxOutputBytes)
        ->toBe(64)
        ->and($ssh->commands[0]->input)
        ->toBeNull()
        ->and($ssh->commands[0]->protectedInput)
        ->toBeNull();
});

it('selects bounded write and required-capacity checks without environment input', function (): void {
    $ssh = new EnvironmentObservationSshExecutor([
        new CommandResult(0, "OK\n", '', 1, false),
    ]);
    $access = environment_remote_access($ssh);

    $access->assertEnvironmentWritable(environment_access_context('/srv/apps/example'), 8192);

    expect($ssh->commands)
        ->toHaveCount(1)
        ->and(array_slice($ssh->commands[0]->arguments, -6))
        ->toBe([
            'write-check',
            '/srv/apps/example',
            (string) posix_getpwuid(posix_geteuid())['name'],
            '1048576',
            '0',
            '8192',
        ])
        ->and($ssh->commands[0]->maxOutputBytes)
        ->toBe(64)
        ->and($ssh->commands[0]->input)
        ->toBeNull()
        ->and($ssh->commands[0]->protectedInput)
        ->toBeNull();
});

it('checks a writable destination without reading its contents', function (): void {
    $directory = environment_access_directory();
    file_put_contents("{$directory}/.env", "MUST_NOT_BE_OBSERVED=sentinel\n");
    chmod("{$directory}/.env", 0000);

    try {
        $access = environment_remote_access(new LocalEnvironmentProgramSshExecutor(new NativeProcessRunner));

        $access->assertEnvironmentWritable(environment_access_context($directory), 4096);

        chmod("{$directory}/.env", 0600);
        expect(file_get_contents("{$directory}/.env"))->toBe("MUST_NOT_BE_OBSERVED=sentinel\n");
    } finally {
        chmod("{$directory}/.env", 0600);
        unlink("{$directory}/.env");
        rmdir($directory);
    }
});

it('refuses missing unsafe unwritable and insufficient-capacity write destinations', function (string $kind): void {
    $parent = environment_access_directory();
    $real = "{$parent}/real";
    mkdir($real, 0700);
    $path = $real;
    $requiredCapacity = 0;

    if ($kind === 'missing parent') {
        $path = "{$parent}/missing";
    } elseif ($kind === 'ancestor symlink') {
        symlink($real, "{$parent}/linked");
        $path = "{$parent}/linked";
    } elseif ($kind === 'special destination') {
        posix_mkfifo("{$real}/.env", 0600);
    } elseif ($kind === 'unwritable directory') {
        chmod($real, 0500);
    } else {
        $requiredCapacity = PHP_INT_MAX;
    }

    try {
        $access = environment_remote_access(new LocalEnvironmentProgramSshExecutor(new NativeProcessRunner));

        expect(fn () => $access->assertEnvironmentWritable(
            environment_access_context($path),
            $requiredCapacity,
        ))
            ->toThrow(ResourceOperationException::class, 'cannot be replaced safely');
    } finally {
        chmod($real, 0700);
        if (file_exists("{$real}/.env")) {
            unlink("{$real}/.env");
        }
        if (is_link("{$parent}/linked")) {
            unlink("{$parent}/linked");
        }
        rmdir($real);
        rmdir($parent);
    }
})->with([
    'missing parent',
    'ancestor symlink',
    'special destination',
    'unwritable directory',
    'insufficient capacity',
]);

it('refuses failed truncated and malformed remote observations', function (CommandResult $observation): void {
    $access = environment_remote_access(new EnvironmentObservationSshExecutor([$observation]));

    try {
        $access->assertEnvironmentReadable(environment_access_context('/srv/apps/example'));
        $this->fail('The observation unexpectedly passed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)
            ->toBe('env.import_preflight_failed')
            ->and($exception->getMessage())
            ->not->toContain($observation->stdout, $observation->stderr);
    }
})->with([
    'failed command' => new CommandResult(42, 'arbitrary remote value', 'raw remote failure', 1, false),
    'truncated command' => new CommandResult(0, "OK\n", '', 1, true),
    'unexpected success output' => new CommandResult(0, "MAYBE\n", '', 1, false),
    'unexpected success diagnostics' => new CommandResult(0, "OK\n", 'arbitrary remote diagnostic', 1, false),
]);

it('refuses nonempty diagnostics while reading an otherwise valid remote value', function (): void {
    $diagnostic = 'arbitrary remote read diagnostic';
    $access = environment_remote_access(new EnvironmentObservationSshExecutor([
        new CommandResult(0, base64_encode('arbitrary remote value'), $diagnostic, 1, false),
    ]));

    try {
        $access->read(environment_access_context('/srv/apps/example'));
        $this->fail('The observation unexpectedly passed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)
            ->toBe('env.import_preflight_failed')
            ->and($exception->getMessage())
            ->not->toContain($diagnostic, 'arbitrary remote value');
    }
});

it('reads a maximum-size file through the explicit native output bound', function (): void {
    $directory = environment_access_directory();
    $contents = str_repeat('x', 1_048_576);
    file_put_contents("{$directory}/.env", $contents);
    chmod("{$directory}/.env", 0400);
    chmod($directory, 0500);

    try {
        $ssh = new LocalEnvironmentProgramSshExecutor(new NativeProcessRunner);
        $access = environment_remote_access($ssh);

        $access->assertEnvironmentReadable(environment_access_context($directory));
        $read = $access->read(environment_access_context($directory));

        expect($read)
            ->toBe($contents)
            ->and($ssh->commands)
            ->toHaveCount(2)
            ->and($ssh->commands[1]->maxOutputBytes)
            ->toBe(1_398_104);
    } finally {
        chmod($directory, 0700);
        chmod("{$directory}/.env", 0600);
        unlink("{$directory}/.env");
        rmdir($directory);
    }
});

it('refuses symlink path components symlink files and non-regular files', function (string $kind): void {
    $parent = environment_access_directory();
    $real = "{$parent}/real";
    mkdir($real, 0700);
    $path = $real;

    if ($kind === 'ancestor symlink') {
        symlink($real, "{$parent}/linked");
        $path = "{$parent}/linked";
        file_put_contents("{$real}/.env", "KEY=value\n");
    } elseif ($kind === 'file symlink') {
        file_put_contents("{$real}/target", "KEY=value\n");
        symlink("{$real}/target", "{$real}/.env");
    } else {
        posix_mkfifo("{$real}/.env", 0600);
    }

    try {
        $access = environment_remote_access(new LocalEnvironmentProgramSshExecutor(new NativeProcessRunner));

        expect(fn () => $access->assertEnvironmentReadable(environment_access_context($path)))
            ->toThrow(ResourceOperationException::class, 'cannot be read safely');
    } finally {
        if (is_link("{$real}/.env")) {
            unlink("{$real}/.env");
        } elseif (file_exists("{$real}/.env")) {
            unlink("{$real}/.env");
        }
        if (file_exists("{$real}/target")) {
            unlink("{$real}/target");
        }
        if (is_link("{$parent}/linked")) {
            unlink("{$parent}/linked");
        }
        rmdir($real);
        rmdir($parent);
    }
})->with(['ancestor symlink', 'file symlink', 'fifo']);

it('selects the recorded application user directly even when production login shells are disabled', function (): void {
    $ssh = new EnvironmentObservationSshExecutor([
        new CommandResult(0, "OK\n", '', 1, false),
    ]);
    $access = environment_remote_access($ssh);
    $context = environment_access_context('/home/example-app', production: true);

    $access->assertEnvironmentReadable($context);

    expect(array_slice($ssh->commands[0]->arguments, 0, 7))
        ->toBe([
            'sudo',
            '-n',
            '-u',
            'example-app',
            '--',
            'python3',
            '-c',
        ]);
    expect(array_slice($ssh->commands[0]->arguments, -6))
        ->toBe([
            'read-check',
            '/home/example-app',
            'example-app',
            '1048576',
            '1',
            '0',
        ]);
});

function environment_access_directory(): string
{
    $directory = sys_get_temp_dir().'/orbit-env-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    return $directory;
}

function environment_access_context(string $path, bool $production = false): AppInstanceEnvironmentContext
{
    $node = new Node;
    $node->forceFill([
        'wireguard_ip' => '127.0.0.1',
        'user' => 'orbit',
    ]);

    return new AppInstanceEnvironmentContext(
        appInstanceId: 1,
        appId: 1,
        nodeId: 1,
        environment: $production ? 'production' : 'development',
        path: $path,
        executionUser: $production ? 'example-app' : (string) posix_getpwuid(posix_geteuid())['name'],
        laravel: false,
        routeId: 1,
        routeHostname: 'example.test',
        nodeStatus: 'active',
        node: $node,
    );
}

function environment_remote_access(SshExecutor $ssh): RemoteAppInstanceEnvironmentAccess
{
    return new RemoteAppInstanceEnvironmentAccess(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 synthetic';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

final class EnvironmentObservationSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return array_shift($this->results) ?? throw new RuntimeException('Unexpected environment command.');
    }
}

final class LocalEnvironmentProgramSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(
        private readonly NativeProcessRunner $runner,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $separator = array_search('--', $command->arguments, true);

        if (! is_int($separator)) {
            throw new RuntimeException('The environment command has no privilege boundary.');
        }

        return $this->runner->run(new ProcessInvocation(
            arguments: array_values(array_slice($command->arguments, $separator + 1)),
            input: $command->input,
            protectedInput: $command->protectedInput,
            maxOutputBytes: $command->maxOutputBytes,
        ));
    }
}
