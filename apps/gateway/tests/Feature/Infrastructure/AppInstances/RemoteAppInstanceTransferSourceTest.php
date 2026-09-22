<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Transfer\TransferArchiveAttempt;
use App\Domain\AppInstances\Transfer\TransferCheckout;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppInstances\RemoteAppInstanceTransferSource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('protects a source archive from creation under a permissive remote umask', function (): void {
    $fixture = new TransferArchiveNativeFixture;

    try {
        expect(filesize($fixture->attempt->archivePath('source')))->toBe(0)
            ->and(fileperms($fixture->attempt->archivePath('source')) & 0777)->toBe(0600)
            ->and(filesize($fixture->attempt->archivePath('destination')))->toBe(0)
            ->and(fileperms($fixture->attempt->archivePath('destination')) & 0777)->toBe(0600);
        $capture = $fixture->source->capture($fixture->instance, $fixture->attempt);

        expect(fileperms($capture->archiveIdentity) & 0777)->toBe(0600)
            ->and(fileperms(dirname($capture->archiveIdentity)) & 0777)->toBe(0700);
    } finally {
        $fixture->close();
    }
});

it('removes both remote archive payloads after native materialization without changing source content', function (): void {
    $fixture = new TransferArchiveNativeFixture;

    try {
        $sourceArchive = $fixture->attempt->archivePath('source');
        $checkout = $fixture->materialize();

        expect(file_get_contents($checkout->path.'/.env'))->toBe("KEY=archive-secret-sentinel\n")
            ->and(file_get_contents($fixture->instance->checkout_path.'/.env'))->toBe("KEY=archive-secret-sentinel\n")
            ->and(is_file($sourceArchive))->toBeFalse()
            ->and(is_file($fixture->transport->uploadedPath))->toBeFalse();
        expect($fixture->transport->invocations)->toHaveCount(2);
        expect($fixture->transport->targetModes)->toBe([0600, 0600]);

        foreach (['source', 'destination'] as $side) {
            $root = $fixture->attempt->location($side)['private_root'];
            expect(array_values(array_diff(scandir($root), ['.', '..'])))->toBe([
                $fixture->attempt->id.'.json', $fixture->attempt->id.'.lock',
            ]);
            expect(file_get_contents($root.'/'.$fixture->attempt->id.'.json'))->not->toContain('archive-secret-sentinel');
        }

        foreach ($fixture->transport->invocations as $invocation) {
            expect(array_slice($invocation->arguments, 0, -2))->toBe([
                'scp', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
                '-o', 'StrictHostKeyChecking=yes', '-i', '/transfer-test/key',
                '-o', 'UserKnownHostsFile=/transfer-test/known-hosts',
            ]);
        }
    } finally {
        $fixture->close();
    }
});

it('removes owned payloads and local stages across capture transport and extraction failures', function (string $failure): void {
    $fixture = new TransferArchiveNativeFixture;
    if ($failure === 'capture') {
        $fixture->ssh->programReplacements = ['os.fsync(archive)' => 'raise OSError("archive-secret-sentinel")'];
    } else {
        $fixture->transport->failure = $failure;
    }

    try {
        expect(fn () => $fixture->materialize())
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.transfer_failed')
                    ->and($exception->getMessage())->not->toContain('archive-secret-sentinel')
                    ->and($exception->getPrevious())->toBeNull();
            });
        expect(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse()
            ->and(is_dir(dirname($fixture->attempt->archivePath('destination'))))->toBeFalse();
        foreach ($fixture->transport->localStages as $path) {
            expect(is_file($path))->toBeFalse();
        }
        foreach ($fixture->transport->targetModes as $mode) {
            expect($mode)->toBe(0600);
        }
        expect(file_get_contents($fixture->instance->checkout_path.'/.env'))->toBe("KEY=archive-secret-sentinel\n");
    } finally {
        $fixture->close();
    }
})->with(['capture', 'download', 'download exception', 'download typed exception', 'upload', 'upload exception', 'upload typed exception', 'truncated upload', 'extraction']);

it('keeps a worktree bundle private and removes only its temporary archive payloads', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $worktree = $fixture->directory.'/linked';
    $fixture->git(['worktree', 'add', '--quiet', '-b', 'preview', $worktree], $fixture->instance->checkout_path);
    $fixture->instance->update(['checkout_path' => $worktree, 'source_layout' => 'worktree']);
    file_put_contents($worktree.'/.env', "KEY=archive-secret-sentinel\n");

    try {
        $capture = $fixture->source->capture($fixture->instance, $fixture->attempt);
        $bundle = dirname($capture->archiveIdentity).'/'.$fixture->attempt->id.'.bundle';

        expect(fileperms($bundle) & 0777)->toBe(0600)
            ->and(filesize($bundle))->toBeGreaterThan(0);
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_file($bundle))->toBeFalse()
            ->and(is_file($worktree.'/.git'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('keeps overlapping attempts unique without removing the other attempt', function (): void {
    $fixture = new TransferArchiveNativeFixture;

    try {
        $first = $fixture->source->capture($fixture->instance, $fixture->attempt);
        $accounts = app(ManagedUserAccountResolver::class);
        $secondAttempt = TransferArchiveAttempt::create(
            $fixture->transfer, $accounts->resolve($fixture->instance->node), $accounts->resolve($fixture->destination),
        );
        $secondAttempt = $fixture->source->prepareArchives($secondAttempt);
        $second = $fixture->source->capture($fixture->instance, $secondAttempt);

        expect($first->archiveIdentity)->not->toBe($second->archiveIdentity)
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_file($second->archiveIdentity))->toBeTrue()
            ->and($fixture->source->cleanupArchives($secondAttempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('preserves a foreign workspace collision while settling the never-created destination', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);
    $workspace = dirname($fixture->attempt->archivePath('source'));
    mkdir($workspace, 0700, recursive: true);
    file_put_contents($workspace.'/foreign', 'foreign-sentinel');

    try {
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($workspace.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_dir(dirname($fixture->attempt->archivePath('destination'))))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('settles a never-started attempt and refuses late preparation', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        expect(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('cleans empty prepared scopes after acknowledgement loss without starting payload capture', function (): void {
    $fixture = new TransferArchiveNativeFixture(prepare: false);
    $fixture->ssh->after = static function (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult {
        if ($command->arguments[3] === 'prepare' && $connection->host === '10.44.47.1') {
            throw new RuntimeException('archive-secret-sentinel');
        }

        return $result;
    };

    try {
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
        expect(filesize($fixture->attempt->archivePath('source')))->toBe(0)
            ->and($fixture->attempt->source['receipt'])->toBeNull()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
        expect(array_column(array_column($fixture->ssh->calls, 'command'), 'arguments'))
            ->each(fn ($arguments) => $arguments->not->toContain('capture'));
    } finally {
        $fixture->close();
    }
});

it('retains unconfirmed cleanup after a lost acknowledgement and confirms an identical retry', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->source->capture($fixture->instance, $fixture->attempt);
    $fixture->ssh->after = static function (SshConnection $connection, RemoteCommand $command, CommandResult $result): CommandResult {
        if ($command->arguments[3] === 'cleanup' && $connection->host === '10.44.47.1') {
            throw new RuntimeException('archive-secret-sentinel');
        }

        return $result;
    };

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
        $fixture->ssh->after = null;
        expect($fixture->source->cleanupArchives($fixture->attempt->withCleanupPending(['source'])))->toBe([]);
        expect(fn () => $fixture->source->capture($fixture->instance, $fixture->attempt))
            ->toThrow(ResourceOperationException::class);
        expect(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('unlinks a destination archive while an upload still holds its open descriptor', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $archive = $fixture->attempt->archivePath('destination');
    $upload = fopen($archive, 'wb');

    try {
        expect(is_resource($upload))->toBeTrue();
        fwrite($upload, 'first-archive-secret-sentinel');
        fflush($upload);

        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(fstat($upload)['nlink'])->toBe(0);
        fwrite($upload, 'late-archive-secret-sentinel');
        fflush($upload);

        expect(is_dir(dirname($archive)))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        $root = $fixture->attempt->destination['private_root'];
        expect(array_values(array_diff(scandir($root), ['.', '..'])))->toBe([
            $fixture->attempt->id.'.json', $fixture->attempt->id.'.lock',
        ]);
    } finally {
        if (is_resource($upload)) {
            fclose($upload);
        }
        $fixture->close();
    }
});

it('refuses a late upload open after native archive cleanup removed its workspace', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->source->capture($fixture->instance, $fixture->attempt);
    $stage = $fixture->directory.'/late-upload.tar';
    copy($capture->archiveIdentity, $stage);

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        $upload = new NativeProcessRunner()->run(new ProcessInvocation([
            'scp', '--', $stage, $fixture->attempt->archivePath('destination'),
        ]));

        expect($upload->succeeded())->toBeFalse()
            ->and(is_dir(dirname($fixture->attempt->archivePath('destination'))))->toBeFalse()
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
        expect(fn () => $fixture->prepare())->toThrow(ResourceOperationException::class);
    } finally {
        $fixture->close();
    }
});

it('preserves unknown content inside an owned archive workspace until it can be resolved', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->source->capture($fixture->instance, $fixture->attempt);
    $foreign = dirname($capture->archiveIdentity).'/foreign';
    file_put_contents($foreign, 'foreign-sentinel');

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($foreign))->toBe('foreign-sentinel')
            ->and(is_file($capture->archiveIdentity))->toBeTrue();
        unlink($foreign);
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([]);
    } finally {
        $fixture->close();
    }
});

it('refuses a replaced workspace without deleting its foreign contents', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $capture = $fixture->source->capture($fixture->instance, $fixture->attempt);
    $workspace = dirname($capture->archiveIdentity);
    rename($workspace, $workspace.'.retained');
    mkdir($workspace, 0700);
    file_put_contents($workspace.'/foreign', 'foreign-sentinel');

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($workspace.'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($workspace.'.retained/archive.tar'))->toBeTrue();
    } finally {
        $fixture->close();
    }
});

it('retains archive evidence when its private root identity changes', function (bool $symlink): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->source->capture($fixture->instance, $fixture->attempt);
    $root = $fixture->attempt->source['private_root'];
    rename($root, $root.'.retained');
    $foreign = $fixture->directory.'/foreign-root';
    mkdir($foreign, 0700);
    file_put_contents($foreign.'/sentinel', 'foreign-sentinel');
    if ($symlink) {
        symlink($foreign, $root);
    } else {
        mkdir($root, 0700);
        file_put_contents($root.'/sentinel', 'foreign-sentinel');
    }

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source'])
            ->and(file_get_contents($root.'/sentinel'))->toBe('foreign-sentinel')
            ->and(file_get_contents($foreign.'/sentinel'))->toBe('foreign-sentinel');
    } finally {
        $fixture->close();
    }
})->with(['symlink' => true, 'replacement' => false]);

it('preserves a foreign directory swapped between archive validation and its atomic claim', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->source->capture($fixture->instance, $fixture->attempt);
    $fixture->ssh->programReplacements = [
        'rename_without_replacement(root, workspace_name, claim_name)' => implode("\n", [
            'if side == "source":',
            '            os.rename(workspace_name, attempt + ".retained", src_dir_fd=root, dst_dir_fd=root)',
            '            os.mkdir(workspace_name, 0o700, dir_fd=root)',
            '            foreign = os.open(workspace_name + "/foreign", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600, dir_fd=root)',
            '            os.write(foreign, b"foreign-sentinel")',
            '            os.close(foreign)',
            '        rename_without_replacement(root, workspace_name, claim_name)',
        ]),
    ];

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source']);
        $root = $fixture->attempt->source['private_root'];
        expect(file_get_contents(dirname($fixture->attempt->archivePath('source')).'/foreign'))->toBe('foreign-sentinel')
            ->and(is_file($root.'/'.$fixture->attempt->id.'.retained/archive.tar'))->toBeTrue()
            ->and(is_dir($root.'/'.$fixture->attempt->id.'.claimed'))->toBeFalse();
    } finally {
        $fixture->close();
    }
});

it('refuses an archive file substituted after workspace validation before opening the payload', function (): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->ssh->programReplacements = [
        'archive = open_artifact(workspace, "archive", os.O_RDWR)' => implode("\n", [
            'os.rename("archive.tar", "retained.tar", src_dir_fd=workspace, dst_dir_fd=workspace)',
            '    foreign = os.open("archive.tar", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600, dir_fd=workspace)',
            '    os.write(foreign, b"foreign-sentinel")',
            '    os.close(foreign)',
            '    archive = open_artifact(workspace, "archive", os.O_RDWR)',
        ]),
    ];

    try {
        expect(fn () => $fixture->source->capture($fixture->instance, $fixture->attempt))->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture->attempt->archivePath('source')))->toBe('foreign-sentinel')
            ->and($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source']);
    } finally {
        $fixture->close();
    }
});

it('resumes exact archive cleanup after interruption at its claim or deletion boundary', function (string $fault): void {
    $fixture = new TransferArchiveNativeFixture;
    $fixture->source->capture($fixture->instance, $fixture->attempt);
    $fixture->ssh->programReplacements = match ($fault) {
        'after claim' => [
            'moved = metadata_at(root, claim_name)' => 'raise OSError("after claim")',
        ],
        'during deletion' => [
            'os.unlink(name, dir_fd=workspace)' => "os.unlink(name, dir_fd=workspace)\n                raise OSError(\"during deletion\")",
        ],
    };

    try {
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe(['source', 'destination']);
        $fixture->ssh->programReplacements = [];
        expect($fixture->source->cleanupArchives($fixture->attempt))->toBe([])
            ->and(is_dir(dirname($fixture->attempt->archivePath('source'))))->toBeFalse();
    } finally {
        $fixture->close();
    }
})->with(['after claim', 'during deletion']);

final class TransferArchiveNativeFixture
{
    public readonly string $directory;

    public readonly string $destinationPath;

    public readonly AppInstance $instance;

    public readonly Node $destination;

    public readonly TransferArchiveNativeSsh $ssh;

    public readonly TransferArchiveNativeTransport $transport;

    public readonly RemoteAppInstanceTransferSource $source;

    public readonly AppInstanceTransfer $transfer;

    public TransferArchiveAttempt $attempt;

    public function __construct(bool $prepare = true)
    {
        $this->directory = sys_get_temp_dir().'/orbit-transfer-native-'.Str::uuid();
        mkdir($this->directory, 0700);
        $sourceHome = $this->directory.'/source-home';
        $destinationHome = $this->directory.'/destination-home';
        mkdir($sourceHome, 0700);
        mkdir($destinationHome, 0700);
        $checkout = $sourceHome.'/source-'.Str::uuid();
        mkdir($checkout, 0700);
        $this->destinationPath = $destinationHome.'/apps/shop/web';
        $account = posix_getpwuid(posix_geteuid());
        if (! is_array($account)) {
            throw new RuntimeException('The native archive fixture requires the current account.');
        }
        $user = $account['name'];
        $sourceNode = Node::query()->create([
            'name' => 'archive-source', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.201', 'wireguard_ip' => '10.44.47.1', 'user' => $user,
        ]);
        $this->destination = Node::query()->create([
            'name' => 'archive-destination', 'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.202', 'wireguard_ip' => '10.44.47.2', 'user' => $user,
        ]);
        $app = OrbitApp::query()->create([
            'name' => 'Archive fixture', 'slug' => 'archive-fixture',
            'repository_url' => 'https://example.test/archive-fixture.git',
        ]);
        $this->instance = AppInstance::query()->create([
            'app_id' => $app->id, 'node_id' => $sourceNode->id, 'name' => 'web',
            'environment' => 'development', 'checkout_path' => $checkout,
            'source_layout' => 'checkout', 'status' => AppInstanceState::Active,
        ]);
        $this->transfer = AppInstanceTransfer::query()->create([
            'app_instance_id' => $this->instance->id,
            'source_node_id' => $sourceNode->id,
            'destination_node_id' => $this->destination->id,
            'destination_name' => 'web', 'destination_path' => $this->destinationPath,
            'destination_domain' => 'web.archive.test', 'source_layout' => 'checkout',
            'source_path' => $checkout, 'source_route_id' => 1,
            'status' => 'reserved', 'current_step' => 'reserved',
        ]);
        file_put_contents($checkout.'/app.php', 'tracked source');
        file_put_contents($checkout.'/.env', "KEY=archive-secret-sentinel\n");
        $this->git(['init', '--quiet', '--initial-branch=main'], $checkout);
        $this->git(['add', 'app.php'], $checkout);
        $this->git(['-c', 'user.name=Archive Fixture', '-c', 'user.email=archive@example.test', 'commit', '--quiet', '-m', 'fixture'], $checkout);
        $keys = new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/transfer-test/key';
            }

            public function publicKey(): string
            {
                return 'transfer-test-public-key';
            }
        };
        $knownHosts = new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/transfer-test/known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        };
        app()->instance(ManagedUserAccountResolver::class, new class($sourceNode->id, $user, $sourceHome, $destinationHome) implements ManagedUserAccountResolver
        {
            public function __construct(
                private readonly int $sourceNodeId,
                private readonly string $user,
                private readonly string $sourceHome,
                private readonly string $destinationHome,
            ) {}

            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount(
                    $this->user,
                    $this->user,
                    $node->id === $this->sourceNodeId ? $this->sourceHome : $this->destinationHome,
                );
            }
        });
        $this->ssh = new TransferArchiveNativeSsh;
        $this->transport = new TransferArchiveNativeTransport;
        $this->source = app()->make(RemoteAppInstanceTransferSource::class, [
            'ssh' => new AppDevSshExecutor($this->ssh, $keys, $knownHosts),
            'processes' => $this->transport,
            'keys' => $keys,
            'knownHosts' => $knownHosts,
        ]);
        $accounts = app(ManagedUserAccountResolver::class);
        $this->attempt = TransferArchiveAttempt::create(
            $this->transfer, $accounts->resolve($sourceNode), $accounts->resolve($this->destination),
        );
        $this->transfer->update(['archive_attempt' => $this->attempt->toArray()]);
        if ($prepare) {
            $this->prepare();
        }
    }

    public function prepare(): void
    {
        $this->attempt = $this->source->prepareArchives($this->attempt);
        $this->transfer->update(['archive_attempt' => $this->attempt->toArray()]);
    }

    public function materialize(): TransferCheckout
    {
        try {
            $capture = $this->source->capture($this->instance, $this->attempt);

            return $this->source->materialize(
                $capture, $this->destination, StoragePath::parse($this->destinationPath), $this->attempt,
            );
        } finally {
            expect($this->source->cleanupArchives($this->attempt))->toBe([]);
        }
    }

    /** @param non-empty-list<string> $arguments */
    public function git(array $arguments, string $path): string
    {
        $result = new NativeProcessRunner()->run(new ProcessInvocation(['git', '-C', $path, ...$arguments]));
        if (! $result->succeeded()) {
            throw new RuntimeException('The native archive Git fixture failed.');
        }

        return trim($result->stdout);
    }

    public function close(): void
    {
        File::deleteDirectory($this->directory);
    }
}

final class TransferArchiveNativeSsh implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $calls = [];

    public ?Closure $before = null;

    public ?Closure $after = null;

    /** @var array<string, string> */
    public array $programReplacements = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = ['connection' => $connection, 'command' => $command];
        if ($this->before instanceof Closure) {
            ($this->before)($connection, $command);
        }
        $arguments = $command->arguments;
        if ($arguments[0] === 'python3') {
            $arguments[2] = strtr($arguments[2], $this->programReplacements);
        }
        $previous = umask(0000);
        try {
            $result = new NativeProcessRunner()->run(new ProcessInvocation(
                $arguments, input: $command->input, protectedInput: $command->protectedInput,
            ));
        } finally {
            umask($previous);
        }
        if ($this->after instanceof Closure) {
            return ($this->after)($connection, $command, $result);
        }

        return $result;
    }
}

final class TransferArchiveNativeTransport implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public string $uploadedPath = '';

    public ?string $failure = null;

    /** @var list<string> */
    public array $localStages = [];

    /** @var list<int> */
    public array $targetModes = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;
        if ($invocation->arguments[0] !== 'scp') {
            throw new RuntimeException('The archive transport accepts only SCP.');
        }
        [$source, $target] = array_slice($invocation->arguments, -2);
        $remoteSource = $source;
        $source = $this->localPath($source);
        $localTarget = $this->localPath($target);
        $direction = $remoteSource !== $source ? 'download' : 'upload';
        if ($direction === 'download') {
            $this->localStages[] = $localTarget;
        }
        if ($localTarget !== $target) {
            $this->uploadedPath = $localTarget;
        }
        $this->targetModes[] = fileperms($localTarget) & 0777;
        if ($this->failure === $direction.' exception') {
            throw new RuntimeException('archive-secret-sentinel');
        }
        if ($this->failure === $direction.' typed exception') {
            throw new ResourceOperationException('transport.secret', 'archive-secret-sentinel', 500);
        }
        if ($this->failure === $direction || $direction === 'upload' && in_array($this->failure, ['truncated upload', 'extraction'], true)) {
            file_put_contents($localTarget, 'partial-archive-secret-sentinel');

            return new CommandResult($this->failure === 'extraction' ? 0 : 1, 'archive-secret-sentinel', '', 1, $this->failure === 'truncated upload');
        }
        if (! copy($source, $localTarget)) {
            throw new RuntimeException('The native archive transport fixture could not copy the payload.');
        }

        return new CommandResult(0, '', '', 1, false);
    }

    private function localPath(string $path): string
    {
        if (preg_match('/\A[^@]+@(?:\[[^]]+\]|[^:]+):(.+)\z/D', $path, $match) !== 1) {
            return $path;
        }

        return $match[1];
    }
}
