<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
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
use Tests\Support\LinuxHost;

it('creates and atomically replaces a complete protected environment file', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

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

it('seeds a missing .env.testing with the complete supplied contents and leaves .env alone', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $directory = writer_environment_directory();
    file_put_contents("{$directory}/.env", "APP_ENV=\"local\"\n");
    chmod("{$directory}/.env", 0600);
    $seed = "APP_ENV=\"testing\"\nAPP_KEY=\"base64:seed\"\nDB_CONNECTION=\"mysql\"\nDB_DATABASE=\"app_test\"\n";
    $keys = ['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE'];

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner));
        $written = $access->mergeTesting(writer_environment_context($directory), $seed, $keys);
        $repeated = $access->mergeTesting(writer_environment_context($directory), $seed, $keys);

        expect($written->changed)->toBeTrue()
            ->and($written->tracked)->toBeFalse()
            ->and($repeated->changed)->toBeFalse()
            ->and(file_get_contents("{$directory}/.env.testing"))->toBe($seed)
            ->and(fileperms("{$directory}/.env.testing") & 0777)->toBe(0600)
            ->and(file_get_contents("{$directory}/.env"))->toBe("APP_ENV=\"local\"\n");
    } finally {
        writer_remove_directory($directory);
    }
});

it('replaces only the managed keys of an untracked .env.testing and keeps every other line', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $directory = writer_environment_directory();
    writer_git($directory, 'init', '--quiet');
    file_put_contents("{$directory}/.env.testing", "# Test settings\nAPP_KEY=base64:kept\nexport DB_HOST=127.0.0.1\nDB_PORT=3308\nDB_HOST=duplicate\nCACHE_STORE=array");

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner));
        $merged = $access->mergeTesting(
            writer_environment_context($directory),
            "APP_ENV=\"testing\"\nAPP_KEY=\"base64:seed\"\nDB_DATABASE=\"app_test\"\nDB_HOST=\"10.44.0.7\"\n",
            ['DB_HOST', 'DB_PORT', 'DB_DATABASE'],
        );

        expect($merged->changed)->toBeTrue()
            ->and(file_get_contents("{$directory}/.env.testing"))
            ->toBe("# Test settings\nAPP_KEY=base64:kept\nDB_HOST=\"10.44.0.7\"\nCACHE_STORE=array\nDB_DATABASE=\"app_test\"\n");
    } finally {
        writer_remove_directory($directory);
    }
});

it('never writes a .env.testing that Git tracks in the checkout', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $directory = writer_environment_directory();
    $tracked = "DB_DATABASE=committed\n";
    writer_git($directory, 'init', '--quiet');
    file_put_contents("{$directory}/.env.testing", $tracked);
    writer_git($directory, 'add', '.env.testing');

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner));
        $inode = fileinode("{$directory}/.env.testing");
        $result = $access->mergeTesting(writer_environment_context($directory), "DB_DATABASE=\"app_test\"\n", ['DB_DATABASE']);

        expect($result->confirmed)->toBeTrue()
            ->and($result->tracked)->toBeTrue()
            ->and($result->changed)->toBeFalse()
            ->and(file_get_contents("{$directory}/.env.testing"))->toBe($tracked)
            ->and(fileinode("{$directory}/.env.testing"))->toBe($inode)
            ->and(glob("{$directory}/.env.orbit-*"))->toBe([]);
    } finally {
        writer_remove_directory($directory);
    }
});

it('refuses .env.testing when Git cannot report whether the checkout tracks it', function (
    string $repository,
    ?string $existing,
): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $directory = writer_environment_directory();
    $replacements = [];

    if ($repository === 'dubious ownership') {
        writer_git($directory, 'init', '--quiet');
        $replacements = ['GIT_OPTIONAL_LOCKS="0"' => 'GIT_OPTIONAL_LOCKS="0", GIT_TEST_ASSUME_DIFFERENT_OWNER="1", GIT_CONFIG_GLOBAL="/dev/null", GIT_CONFIG_NOSYSTEM="1"'];
    } elseif ($repository === 'damaged config') {
        writer_git($directory, 'init', '--quiet');
        file_put_contents("{$directory}/.git/config", "[core\n");
    } else {
        file_put_contents("{$directory}/.git", "gitdir: {$directory}/missing\n");
    }

    if ($existing !== null) {
        file_put_contents("{$directory}/.env.testing", $existing);
    }

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner, $replacements));

        try {
            $access->mergeTesting(writer_environment_context($directory), "APP_KEY=\"base64:seed\"\nDB_DATABASE=\"app_test\"\n", ['DB_DATABASE']);
            $error = null;
        } catch (ResourceOperationException $exception) {
            $error = $exception->errorCode;
        }

        expect($error)->toBe('env.testing_tracking_unknown')
            ->and(is_file("{$directory}/.env.testing") ? file_get_contents("{$directory}/.env.testing") : null)->toBe($existing)
            ->and(glob("{$directory}/.env.orbit-*"))->toBe([]);
    } finally {
        writer_remove_directory($directory);
    }
})->with([
    'dubious ownership' => ['dubious ownership', "DB_DATABASE=committed\n"],
    'damaged repository config' => ['damaged config', "DB_DATABASE=committed\n"],
    'broken .git file without a .env.testing' => ['broken git file', null],
]);

it('retains file identity for an identical protected repeat and repairs mode drift', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

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
        'os.replace(candidate_name, target_name, src_dir_fd=current, dst_dir_fd=current)',
        '(_ for _ in ()).throw(OSError())',
    ],
]);

it('refuses a placement boundary change after preflight before writer effects', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

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
    if (LinuxHost::delegate($this)) {
        return;
    }

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

it('returns an unconfirmed result when directory sync fails after atomic replacement', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $directory = writer_environment_directory();
    file_put_contents("{$directory}/.env", "KEY=previous\n");
    chmod("{$directory}/.env", 0600);
    $contents = "KEY=current\n";

    try {
        $access = writer_environment_access(new WriterLocalSshExecutor(
            new NativeProcessRunner,
            [
                'os.fsync(current)' => '(_ for _ in ()).throw(OSError())',
            ],
        ));

        $unconfirmed = $access->write(writer_environment_context($directory), $contents);
        $installedIdentity = fileinode("{$directory}/.env");
        $retry = writer_environment_access(new WriterLocalSshExecutor(new NativeProcessRunner))
            ->write(writer_environment_context($directory), $contents);

        expect($unconfirmed->confirmed)
            ->toBeFalse()
            ->and($unconfirmed->changed)
            ->toBeNull();
        expect(file_get_contents("{$directory}/.env"))
            ->toBe($contents)
            ->and(glob("{$directory}/.env.orbit-*") ?: [])
            ->toBe([]);
        expect($retry->confirmed)
            ->toBeTrue()
            ->and($retry->changed)
            ->toBeFalse()
            ->and(fileinode("{$directory}/.env"))
            ->toBe($installedIdentity);
    } finally {
        writer_remove_directory($directory);
    }
});

it('treats mismatched failure exit and receipt pairs as unconfirmed', function (
    int $exitCode,
    string $receipt,
): void {
    $directory = writer_environment_directory();

    try {
        $result = writer_environment_access(new WriterObservationSshExecutor(
            new CommandResult($exitCode, $receipt, '', 1, false),
        ))->write(writer_environment_context($directory), "KEY=current\n");

        expect($result->confirmed)
            ->toBeFalse()
            ->and($result->changed)
            ->toBeNull();
    } finally {
        writer_remove_directory($directory);
    }
})->with([
    'refusal exit with failure receipt' => [42, "FAILED\n"],
    'failure exit with refusal receipt' => [43, "REFUSED\n"],
]);

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
                'os.replace(candidate_name, target_name, src_dir_fd=current, dst_dir_fd=current)' => '(_ for _ in ()).throw(OSError())',
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

function writer_git(string $directory, string ...$arguments): void
{
    $result = new NativeProcessRunner()->run(new ProcessInvocation(['git', '-C', $directory, ...$arguments], timeout: 30.0));

    if (! $result->succeeded()) {
        throw new RuntimeException('git failed in the writer test directory.');
    }
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

function writer_environment_context(string $path): InstanceEnvironmentContext
{
    $node = new Node;
    $node->forceFill([
        'wireguard_ip' => '127.0.0.1',
        'user' => 'orbit',
    ]);

    return new InstanceEnvironmentContext(
        instanceId: 1,
        projectId: 1,
        nodeId: 1,
        environment: 'development',
        path: $path,
        executionUser: (string) posix_getpwuid(posix_geteuid())['name'],
        laravel: false,
        routeId: 1,
        routeDomain: 'example.test',
        nodeStatus: 'active',
        node: $node,
    );
}

function writer_environment_access(SshExecutor $ssh): RemoteInstanceEnvironmentAccess
{
    return new RemoteInstanceEnvironmentAccess(
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
