<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteAppInstanceLogReader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process as SymfonyProcess;

it('refuses a storage ancestor link without reading an outside disposable sentinel', function (): void {
    $fixture = orb072_log_fixture();

    try {
        rmdir($fixture['logs']);
        rmdir($fixture['checkout'].'/storage');
        symlink($fixture['outside'].'/storage', $fixture['checkout'].'/storage');
        $output = '';
        $failure = null;

        try {
            $output = $fixture['reader']->tail($fixture['instance'], 100);
        } catch (ResourceOperationException $exception) {
            $failure = $exception;
        }

        expect($output)->not->toContain('outside-disposable-sentinel');
        expect($failure)->toBeInstanceOf(ResourceOperationException::class);
        expect($failure?->errorCode)->toBe('instance.logs_failed');
        expect($failure?->status)->toBe(502);
        expect($failure?->getMessage())->toBe('The application log of AppInstance [log-fixture] could not be read.');
        expect(file_get_contents($fixture['outside'].'/storage/logs/laravel.log'))->toBe("outside-disposable-sentinel\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('reads the last requested lines from the single log before a newer daily log', function (string $content, int $lines, string $expected): void {
    $fixture = orb072_log_fixture();

    try {
        file_put_contents($fixture['logs'].'/laravel.log', $content);
        file_put_contents($fixture['logs'].'/laravel-2026-09-22.log', "daily should not win\n");
        touch($fixture['logs'].'/laravel.log', 1_700_000_000);
        touch($fixture['logs'].'/laravel-2026-09-22.log', 1_800_000_000);

        expect($fixture['reader']->tail($fixture['instance'], $lines))->toBe($expected);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'trailing newline' => ["one\ntwo\nthree\n", 2, "two\nthree\n"],
    'no trailing newline' => ["one\ntwo\nthree", 1, 'three'],
    'blank final line' => ["one\n\n", 1, "\n"],
    'carriage return is not a line separator' => ["one\ntwo\rthree\n", 1, "two\rthree\n"],
    'more lines than content' => ["one\ntwo\n", 1000, "one\ntwo\n"],
    'empty single log still wins' => ['', 100, ''],
]);

it('selects the newest direct daily log including spaces and newlines in its filename', function (string $name): void {
    $fixture = orb072_log_fixture();

    try {
        file_put_contents($fixture['logs'].'/laravel-old.log', "old daily\n");
        file_put_contents($fixture['logs'].'/'.$name, "first\nnew daily\n");
        file_put_contents($fixture['logs'].'/unrelated.log', "unrelated\n");
        mkdir($fixture['logs'].'/nested');
        file_put_contents($fixture['logs'].'/nested/laravel-nested.log', "nested\n");
        touch($fixture['logs'].'/laravel-old.log', 1_700_000_000);
        touch($fixture['logs'].'/'.$name, 1_800_000_000);

        expect($fixture['reader']->tail($fixture['instance'], 1))->toBe("new daily\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['laravel-new.log', 'laravel-with spaces.log', "laravel-with\nnewline.log"]);

it('returns an empty log when a required directory or log is missing', function (string $missing): void {
    $fixture = orb072_log_fixture();

    try {
        if ($missing !== 'file') {
            new Filesystem()->deleteDirectory($fixture[$missing]);
        }

        expect($fixture['reader']->tail($fixture['instance'], 100))->toBe('');
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['file', 'logs', 'checkout']);

it('refuses a linked ancestor instead of reading another checkout', function (string $component): void {
    $fixture = orb072_log_fixture();

    try {
        $path = match ($component) {
            'parent' => dirname($fixture['checkout']),
            'checkout' => $fixture['checkout'],
            'logs' => $fixture['logs'],
        };
        rename($path, $path.'-retained');
        $target = match ($component) {
            'parent' => $fixture['root'].'/outside-parent',
            'checkout' => $fixture['outside'],
            'logs' => $fixture['outside'].'/storage/logs',
        };
        if ($component === 'parent') {
            mkdir($target);
            symlink($fixture['outside'], $target.'/checkout with spaces');
        }
        symlink($target, $path);

        expect(fn () => $fixture['reader']->tail($fixture['instance'], 100))
            ->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture['outside'].'/storage/logs/laravel.log'))->toBe("outside-disposable-sentinel\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['parent', 'checkout', 'logs']);

it('skips linked and special candidates without blocking or reading their target', function (string $kind): void {
    $fixture = orb072_log_fixture();

    try {
        foreach (['laravel.log', 'laravel-new.log'] as $name) {
            $path = $fixture['logs'].'/'.$name;
            if ($kind === 'link') {
                symlink($fixture['outside'].'/storage/logs/laravel.log', $path);
            } elseif ($kind === 'fifo') {
                expect(posix_mkfifo($path, 0o600))->toBeTrue();
            } else {
                mkdir($path);
            }
        }
        file_put_contents($fixture['logs'].'/laravel-old.log', "safe daily\n");
        touch($fixture['logs'].'/laravel-old.log', 1_700_000_000);

        expect($fixture['reader']->tail($fixture['instance'], 100))->toBe("safe daily\n");
        expect(file_get_contents($fixture['outside'].'/storage/logs/laravel.log'))->toBe("outside-disposable-sentinel\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['link', 'fifo', 'directory']);

it('retains the bounded suffix of a large last line', function (): void {
    $fixture = orb072_log_fixture();

    try {
        file_put_contents($fixture['logs'].'/laravel.log', "discard\n".str_repeat('x', 150_000)."ending\n");

        $output = $fixture['reader']->tail($fixture['instance'], 1);

        expect($output)->toBe(str_repeat('x', 65_529)."ending\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('reports an unreadable regular log without including its content in the error', function (): void {
    $fixture = orb072_log_fixture();

    try {
        file_put_contents($fixture['logs'].'/laravel.log', "unreadable-disposable-sentinel\n");
        chmod($fixture['logs'].'/laravel.log', 0o000);

        expect(fn () => $fixture['reader']->tail($fixture['instance'], 100))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.logs_failed');
                expect($exception->status)->toBe(502);
                expect($exception->getMessage())->not->toContain('unreadable-disposable-sentinel');
            });
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('refuses a non-directory storage ancestor', function (): void {
    $fixture = orb072_log_fixture();

    try {
        rmdir($fixture['logs']);
        rmdir($fixture['checkout'].'/storage');
        file_put_contents($fixture['checkout'].'/storage', "not a directory\n");

        expect(fn () => $fixture['reader']->tail($fixture['instance'], 100))
            ->toThrow(ResourceOperationException::class);
        expect(file_get_contents($fixture['checkout'].'/storage'))->toBe("not a directory\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('reads a production concrete release without following the serving current link', function (): void {
    $fixture = orb072_log_fixture();

    try {
        $release = $fixture['root'].'/production/releases/selected';
        new Filesystem()->ensureDirectoryExists(dirname($release));
        rename($fixture['checkout'], $release);
        symlink($fixture['outside'], $fixture['root'].'/production/current');
        file_put_contents($release.'/storage/logs/laravel.log', "selected release\n");
        $fixture['instance']->checkout_path = $release;

        expect($fixture['reader']->tail($fixture['instance'], 100))->toBe("selected release\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('keeps the pinned SSH identity and the existing read timeout', function (): void {
    $fixture = orb072_log_fixture();

    try {
        $fixture['reader']->tail($fixture['instance'], 25);
        $connection = $fixture['ssh']->connections[0];

        expect($connection->host)->toBe('10.44.0.72');
        expect($connection->user)->toBe('orbit');
        expect($connection->port)->toBe(22);
        expect($connection->identityFile)->toBe('/tmp/orbit-log-test-key');
        expect($connection->knownHostsFile)->toBe('/tmp/orbit-log-test-known-hosts');
        expect($connection->commandTimeout)->toBe(30.0);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('ignores Python modules supplied by a user-writable cwd or environment', function (string $location): void {
    $fixture = orb072_log_fixture();

    try {
        $directory = $location === 'cwd' ? $fixture['root'] : $fixture['root'].'/pythonpath';
        file_put_contents($directory.'/fnmatch.py', "raise RuntimeError('untrusted-disposable-module')\n");
        file_put_contents($fixture['logs'].'/laravel.log', "trusted log read\n");

        expect($fixture['reader']->tail($fixture['instance'], 100))->toBe("trusted log read\n");
        expect(file_get_contents($directory.'/fnmatch.py'))->toBe("raise RuntimeError('untrusted-disposable-module')\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['cwd', 'PYTHONPATH']);

it('refuses a selected log replaced before open without returning outside bytes', function (string $replacement): void {
    $hook = 'replacement='.json_encode($replacement, JSON_THROW_ON_ERROR)."\n".<<<'PYTHON'
        import os, sys
        native_open=os.open
        fixture_root=os.path.dirname(os.path.dirname(sys.argv[1]))
        outside=fixture_root+'/outside/storage/logs/laravel.log'
        replaced=False
        def replace_before_open(path, flags, *arguments, **options):
            global replaced
            parent=options.get('dir_fd')
            if path == 'laravel.log' and parent is not None and not replaced:
                replaced=True
                os.rename(path, 'retained.log', src_dir_fd=parent, dst_dir_fd=parent)
                if replacement == 'symlink': os.symlink(outside, path, dir_fd=parent)
                elif replacement == 'regular': os.link(outside, path, dst_dir_fd=parent)
                else: os.mkfifo(path, 0o600, dir_fd=parent)
                marker=native_open(fixture_root+'/hook-fired', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                os.close(marker)
            return native_open(path, flags, *arguments, **options)
        os.open=replace_before_open
        PYTHON;
    $fixture = orb072_log_fixture($hook);

    try {
        file_put_contents($fixture['logs'].'/laravel.log', "original selected log\n");

        expect(fn () => $fixture['reader']->tail($fixture['instance'], 100))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('instance.logs_failed');
                expect($exception->getMessage())->not->toContain('outside-disposable-sentinel');
            });

        expect(file_exists($fixture['root'].'/hook-fired'))->toBeTrue();
        expect(file_get_contents($fixture['logs'].'/retained.log'))->toBe("original selected log\n");
        expect(file_get_contents($fixture['outside'].'/storage/logs/laravel.log'))->toBe("outside-disposable-sentinel\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['symlink', 'regular', 'fifo']);

it('reads the already opened log or directory after its pathname becomes a link', function (string $component): void {
    $hook = 'component='.json_encode($component, JSON_THROW_ON_ERROR)."\n".<<<'PYTHON'
        import os, sys
        native_open=os.open
        fixture_root=os.path.dirname(os.path.dirname(sys.argv[1]))
        replaced=False
        def replace_after_open(path, flags, *arguments, **options):
            global replaced
            descriptor=native_open(path, flags, *arguments, **options)
            parent=options.get('dir_fd')
            expected='storage' if component == 'directory' else 'laravel.log'
            if path == expected and parent is not None and not replaced:
                replaced=True
                os.rename(path, 'retained-'+path, src_dir_fd=parent, dst_dir_fd=parent)
                outside=fixture_root+'/outside/storage'+('' if component == 'directory' else '/logs/laravel.log')
                os.symlink(outside, path, dir_fd=parent)
                marker=native_open(fixture_root+'/hook-fired', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                os.close(marker)
            return descriptor
        os.open=replace_after_open
        PYTHON;
    $fixture = orb072_log_fixture($hook);

    try {
        file_put_contents($fixture['logs'].'/laravel.log', "original selected log\n");

        expect($fixture['reader']->tail($fixture['instance'], 100))->toBe("original selected log\n");

        expect(file_exists($fixture['root'].'/hook-fired'))->toBeTrue();
        expect(file_get_contents($fixture['outside'].'/storage/logs/laravel.log'))->toBe("outside-disposable-sentinel\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['file', 'directory']);

/** @return array{root: string, checkout: string, logs: string, outside: string, instance: AppInstance, ssh: Orb072LocalSshExecutor, reader: RemoteAppInstanceLogReader} */
function orb072_log_fixture(string $hook = ''): array
{
    $created = new NativeProcessRunner()->run(new ProcessInvocation(['mktemp', '-d', '/tmp/orbit-f072-XXXXXX']));
    expect($created->succeeded())->toBeTrue();
    $root = trim($created->stdout);
    $checkout = $root.'/managed/checkout with spaces';
    $logs = $checkout.'/storage/logs';
    $outside = $root.'/outside';
    $files = new Filesystem;
    $files->ensureDirectoryExists($logs);
    $files->ensureDirectoryExists($outside.'/storage/logs');
    $files->ensureDirectoryExists($root.'/pythonpath');
    file_put_contents($outside.'/storage/logs/laravel.log', "outside-disposable-sentinel\n");
    $instance = new AppInstance(['name' => 'log-fixture', 'checkout_path' => $checkout]);
    $instance->setRelation('node', new Node(['name' => 'log-node', 'wireguard_ip' => '10.44.0.72', 'user' => 'orbit']));
    $ssh = new Orb072LocalSshExecutor($root, $hook);
    $reader = new RemoteAppInstanceLogReader(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-log-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-log-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );

    return compact('root', 'checkout', 'logs', 'outside', 'instance', 'ssh', 'reader');
}

final class Orb072LocalSshExecutor implements SshExecutor
{
    /** @var list<SshConnection> */
    public array $connections = [];

    public function __construct(private readonly string $directory, private readonly string $hook = '') {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        expect($command->arguments[0])->toBe('sudo');

        return new NativeProcessRunner(
            processFactory: fn (array $arguments): SymfonyProcess => new SymfonyProcess($arguments, $this->directory),
        )->run(new ProcessInvocation(
            arguments: array_slice($command->arguments, 1),
            input: (basename($command->arguments[1]) === 'python3' ? $this->hook."\n" : '').$command->input,
            environment: ['PYTHONPATH' => $this->directory.'/pythonpath'],
            timeout: $connection->commandTimeout,
            maxOutputBytes: $command->maxOutputBytes,
        ));
    }
}
