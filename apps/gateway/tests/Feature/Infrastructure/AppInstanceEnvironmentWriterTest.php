<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteAppInstanceEnvironmentAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('creates and atomically replaces a complete protected environment file', function (): void {
    $directory = writer_environment_directory();
    file_put_contents("{$directory}/source.php", 'source');
    file_put_contents("{$directory}/database.sqlite", 'database');
    $first = "APP_KEY=first\nARBITRARY=\0binary\n";
    $second = "APP_KEY=second\nARBITRARY=\0replacement\n";

    try {
        $ssh = new WriterLocalSshExecutor(new NativeProcessRunner);
        $access = writer_environment_access($ssh);

        $created = $access->write(writer_environment_context($directory), $first);
        $firstIdentity = fileinode("{$directory}/.env");
        $replaced = $access->write(writer_environment_context($directory), $second);

        expect($created->confirmed)
            ->toBeTrue()
            ->and($created->changed)
            ->toBeTrue()
            ->and($replaced->confirmed)
            ->toBeTrue()
            ->and($replaced->changed)
            ->toBeTrue()
            ->and(file_get_contents("{$directory}/.env"))
            ->toBe($second)
            ->and(fileperms("{$directory}/.env") & 0777)
            ->toBe(0600)
            ->and(fileinode("{$directory}/.env"))
            ->not
            ->toBe($firstIdentity)
            ->and(file_get_contents("{$directory}/source.php"))
            ->toBe('source')
            ->and(file_get_contents("{$directory}/database.sqlite"))
            ->toBe('database')
            ->and($ssh->protectedInputHashes)
            ->toBe([hash('sha256', $first), hash('sha256', $second)]);
    } finally {
        writer_remove_directory($directory);
    }
});

it('retains file identity for an identical protected repeat and repairs mode drift', function (): void {
    $directory = writer_environment_directory();
    $contents = "KEY=same\n";
    file_put_contents("{$directory}/.env", $contents);
    chmod("{$directory}/.env", 0600);

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner));
        $before = fileinode("{$directory}/.env");

        $unchanged = $access->write(writer_environment_context($directory), $contents);
        chmod("{$directory}/.env", 0644);
        $changed = $access->write(writer_environment_context($directory), $contents);

        expect($unchanged->confirmed)
            ->toBeTrue()
            ->and($unchanged->changed)
            ->toBeFalse()
            ->and($changed->confirmed)
            ->toBeTrue()
            ->and($changed->changed)
            ->toBeTrue()
            ->and(fileinode("{$directory}/.env"))
            ->not
            ->toBe($before)
            ->and(fileperms("{$directory}/.env") & 0777)
            ->toBe(0600)
            ->and(file_get_contents("{$directory}/.env"))
            ->toBe($contents);
    } finally {
        writer_remove_directory($directory);
    }
});

it('preserves the destination and unrelated candidates on confirmed writer failures', function (
    string $search,
    string $replacement,
): void {
    $directory = writer_environment_directory();
    file_put_contents("{$directory}/.env", "KEY=previous\n");
    file_put_contents("{$directory}/.env.orbit-foreign", 'foreign');
    chmod("{$directory}/.env", 0600);

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(
            new NativeProcessRunner,
            [$search => $replacement],
        ));

        expect(fn () => $access->write(writer_environment_context($directory), "KEY=current\n"))
            ->toThrow(ResourceOperationException::class, 'replacement failed safely');
        expect(file_get_contents("{$directory}/.env"))
            ->toBe("KEY=previous\n")
            ->and(file_get_contents("{$directory}/.env.orbit-foreign"))
            ->toBe('foreign')
            ->and(glob("{$directory}/.env.orbit-*") ?: [])
            ->toBe(["{$directory}/.env.orbit-foreign"]);
    } finally {
        writer_remove_directory($directory);
    }
})->with([
    'candidate write' => [
        'written = os.write(candidate, chunk[offset:])',
        'written = (_ for _ in ()).throw(OSError())',
    ],
    'candidate protection' => [
        'os.fchmod(candidate, 0o600)',
        '(_ for _ in ()).throw(OSError())',
    ],
    'atomic rename' => [
        'os.replace(candidate_name, ".env", src_dir_fd=current, dst_dir_fd=current)',
        '(_ for _ in ()).throw(OSError())',
    ],
]);

it('refuses a placement boundary change after preflight before writer effects', function (): void {
    $parent = writer_environment_directory();
    $recorded = "{$parent}/recorded";
    $original = "{$parent}/original";
    $actor = "{$parent}/actor";
    mkdir($recorded, 0700);
    mkdir($actor, 0700);
    file_put_contents("{$recorded}/.env", "KEY=previous\n");
    file_put_contents("{$actor}/.env", "KEY=actor\n");
    chmod("{$recorded}/.env", 0600);
    chmod("{$actor}/.env", 0600);

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner));
        $context = writer_environment_context($recorded);
        $access->assertEnvironmentWritable($context, 4096);
        rename($recorded, $original);
        symlink($actor, $recorded);

        expect(fn () => $access->write($context, "KEY=current\n"))
            ->toThrow(ResourceOperationException::class, 'replacement failed safely');
        expect(file_get_contents("{$original}/.env"))
            ->toBe("KEY=previous\n")
            ->and(file_get_contents("{$actor}/.env"))
            ->toBe("KEY=actor\n")
            ->and(glob("{$original}/.env.orbit-*") ?: [])
            ->toBe([])
            ->and(glob("{$actor}/.env.orbit-*") ?: [])
            ->toBe([]);
    } finally {
        if (is_link($recorded)) {
            unlink($recorded);
        }
        writer_remove_directory($parent);
    }
});

it('returns an unconfirmed result after a lost acknowledgement and accepts the protected retry', function (): void {
    $directory = writer_environment_directory();
    file_put_contents("{$directory}/.env", "KEY=previous\n");
    chmod("{$directory}/.env", 0600);
    $contents = "KEY=current\n";

    try {
        $lostAcknowledgement = writer_environment_access(new WriterLocalSshExecutor(
            new NativeProcessRunner,
            loseAcknowledgement: true,
        ));

        $unconfirmed = $lostAcknowledgement->write(writer_environment_context($directory), $contents);
        $installedIdentity = fileinode("{$directory}/.env");
        $retry = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner))
            ->write(writer_environment_context($directory), $contents);

        expect($unconfirmed->confirmed)
            ->toBeFalse()
            ->and($unconfirmed->changed)
            ->toBeNull()
            ->and(file_get_contents("{$directory}/.env"))
            ->toBe($contents)
            ->and($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeFalse()
            ->and(fileinode("{$directory}/.env"))
            ->toBe($installedIdentity);
    } finally {
        writer_remove_directory($directory);
    }
});

it('keeps supplied bytes and raw remote output out of diagnostics and trace arguments', function (): void {
    $directory = writer_environment_directory();
    file_put_contents("{$directory}/.env", "KEY=previous\n");
    chmod("{$directory}/.env", 0600);
    $sensitive = "APP_KEY=trace-sentinel-\0-secret\n";
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(
            new NativeProcessRunner,
            [
                'os.replace(candidate_name, ".env", src_dir_fd=current, dst_dir_fd=current)' => '(_ for _ in ()).throw(OSError())',
            ],
        ));

        try {
            $access->write(writer_environment_context($directory), $sensitive);
            $this->fail('The injected writer failure unexpectedly passed.');
        } catch (ResourceOperationException $exception) {
            $frames = collect($exception->getTrace())->where('function', 'write');

            expect($exception->getMessage())
                ->not->toContain($sensitive)->and($exception->getPrevious())->toBeNull()->and($frames)
                ->not->toBeEmpty();
            foreach ($frames as $frame) {
                expect(collect($frame['args'] ?? [])
                    ->contains(
                        static fn (mixed $argument): bool => $argument instanceof SensitiveParameterValue,
                    ))
                    ->toBeTrue()
                    ->and(json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR))
                    ->not->toContain($sensitive);
            }
        }

        $unconfirmed = writer_environment_access(new WriterObservationSshExecutor(
            new CommandResult(255, "raw {$sensitive}", "diagnostic {$sensitive}", 1, false),
        ))->write(writer_environment_context($directory), $sensitive);

        expect($unconfirmed->confirmed)
            ->toBeFalse()
            ->and($unconfirmed->changed)
            ->toBeNull()
            ->and(print_r($unconfirmed, return: true))
            ->not->toContain($sensitive);
    } finally {
        if (is_string($previous)) {
            ini_set('zend.exception_ignore_args', $previous);
        }
        writer_remove_directory($directory);
    }
});

function writer_environment_directory(): string
{
    $directory = sys_get_temp_dir().'/orbit-env-writer-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    return $directory;
}

function writer_remove_directory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());

            continue;
        }

        rmdir($entry->getPathname());
    }

    rmdir($directory);
}

function writer_environment_context(string $path): AppInstanceEnvironmentContext
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
        environment: 'development',
        path: $path,
        executionUser: (string) posix_getpwuid(posix_geteuid())['name'],
        laravel: false,
        routeId: 1,
        routeHostname: 'example.test',
        nodeStatus: 'active',
        node: $node,
    );
}

function writer_environment_access(SshExecutor $ssh): RemoteAppInstanceEnvironmentAccess
{
    return new RemoteAppInstanceEnvironmentAccess(
        $ssh,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 synthetic';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

/** @mago-expect lint:file-name Test-local adapter executes the fixed writer program. */
final class WriterLocalSshExecutor implements SshExecutor
{
    /** @var list<string> */
    public array $protectedInputHashes = [];

    /** @param array<string, string> $programReplacements */
    public function __construct(
        private readonly NativeProcessRunner $runner,
        private readonly array $programReplacements = [],
        private readonly bool $loseAcknowledgement = false,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $separator = array_search('--', $command->arguments, true);

        if (! is_int($separator)) {
            throw new RuntimeException('The environment command has no privilege boundary.');
        }

        if ($command->protectedInput instanceof ProtectedInput) {
            $contents = stream_get_contents($command->protectedInput->stream());

            if (! is_string($contents)) {
                throw new RuntimeException('Unable to inspect protected test input.');
            }

            $this->protectedInputHashes[] = hash('sha256', $contents);
        }

        $arguments = array_values(array_slice($command->arguments, $separator + 1));
        $arguments[2] = str_replace(
            array_keys($this->programReplacements),
            array_values($this->programReplacements),
            $arguments[2],
        );
        $result = $this->runner->run(new ProcessInvocation(
            arguments: $arguments,
            input: $command->input,
            protectedInput: $command->protectedInput,
            maxOutputBytes: $command->maxOutputBytes,
        ));

        if (! $this->loseAcknowledgement) {
            return $result;
        }

        return new CommandResult(255, '', 'Connection closed.', $result->durationMs, false);
    }
}

/** @mago-expect lint:file-name Test-local adapter returns a bounded remote observation. */
final readonly class WriterObservationSshExecutor implements SshExecutor
{
    public function __construct(
        private CommandResult $result,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return $this->result;
    }
}
