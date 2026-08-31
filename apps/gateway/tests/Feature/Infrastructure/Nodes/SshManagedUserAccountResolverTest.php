<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Nodes\SshManagedUserAccountResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Symfony\Component\Process\Process;

it('extracts the home field from the remote passwd record', function (): void {
    $script = <<<'SHELL'
        getent() { printf '%s\n' 'nckrtl:x:1000:1000:Nck:/srv/users/nckrtl:/bin/bash'; }
        id() { printf '%s\n' 'nckrtl'; }
        SHELL;
    $process = new Process([
        'sh',
        '-c',
        $script."\n".SshManagedUserAccountResolver::PROGRAM,
        '--',
        'nckrtl',
    ]);

    $process->run();

    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toBe("nckrtl\n/srv/users/nckrtl\nnckrtl\n");
});

it('resolves the managed account over the node WireGuard address', function (): void {
    $executor = new class implements SshExecutor {
        public ?SshConnection $connection = null;
        public ?RemoteCommand $command = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->connection = $connection;
            $this->command = $command;

            return new CommandResult(0, "deploy\n/home/deploy\ndeploy\n", '', 1, false);
        }
    };
    $node = new Node(['user' => 'deploy', 'wireguard_ip' => '10.44.0.8']);
    $account = new SshManagedUserAccountResolver(
        $executor,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/key';
            }

            public function publicKey(): string
            {
                return 'key';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/hosts';
            }

            public function put(string $host, int $port, App\Infrastructure\Ssh\HostKey $key): void {}
        },
    )->resolve($node);
    expect($account->user)
        ->toBe('deploy')
        ->and($account->home)
        ->toBe('/home/deploy')
        ->and($account->group)
        ->toBe('deploy')
        ->and($executor->connection)
        ->toMatchObject([
            'host' => '10.44.0.8',
            'user' => 'deploy',
            'identityFile' => '/tmp/key',
            'knownHostsFile' => '/tmp/hosts',
        ])
        ->and($executor->command?->arguments)
        ->toBe(['sh', '-c', SshManagedUserAccountResolver::PROGRAM, '--', 'deploy']);
});

it('rejects malformed output with a safe provisioning error', function (): void {
    $executor = new class implements SshExecutor {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(0, "deploy\n../bad\ndeploy\n", 'secret stderr', 1, false);
        }
    };
    $node = new Node(['user' => 'deploy', 'wireguard_ip' => '10.44.0.8']);
    expect(
        fn () => new SshManagedUserAccountResolver(
            $executor,
            new class implements SshKeyProvider {
                public function privateKeyPath(): string
                {
                    return '/tmp/key';
                }

                public function publicKey(): string
                {
                    return 'key';
                }
            },
            new class implements KnownHostsStore {
                public function path(): string
                {
                    return '/tmp/hosts';
                }

                public function put(string $host, int $port, App\Infrastructure\Ssh\HostKey $key): void {}
            },
        )->resolve($node),
    )
        ->toThrow(
            fn (NodeProvisioningException $e): bool => (
                $e->step === 'managed-user'
                && $e->errorCode === 'node.managed_user_unavailable'
                && ! str_contains($e->getMessage(), 'secret')
            ),
        );
});

it('rejects every malformed or failed remote result safely', function (
    int $exitCode,
    string $stdout,
    bool $truncated,
): void {
    $executor = new class($exitCode, $stdout, $truncated) implements SshExecutor {
        public function __construct(
            private int $exitCode,
            private string $stdout,
            private bool $truncated,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult($this->exitCode, $this->stdout, 'stderr-sentinel', 1, $this->truncated);
        }
    };
    $node = new Node(['user' => 'deploy', 'wireguard_ip' => '10.44.0.8']);
    expect(
        fn () => new SshManagedUserAccountResolver(
            $executor,
            new class implements SshKeyProvider {
                public function privateKeyPath(): string
                {
                    return '/tmp/key';
                }

                public function publicKey(): string
                {
                    return 'key';
                }
            },
            new class implements KnownHostsStore {
                public function path(): string
                {
                    return '/tmp/hosts';
                }

                public function put(string $host, int $port, App\Infrastructure\Ssh\HostKey $key): void {}
            },
        )->resolve($node),
    )
        ->toThrow(
            fn (NodeProvisioningException $e): bool => (
                $e->step === 'managed-user'
                && $e->errorCode === 'node.managed_user_unavailable'
                && $e->getPrevious() === null
                && ! str_contains($e->getMessage(), 'sentinel')
            ),
        );
})->with([
    'nonzero' => [1, "deploy\n/home/deploy\ndeploy\n", false],
    'truncated' => [0, "deploy\n/home/deploy\ndeploy\n", true],
    'empty' => [0, '', false],
    'duplicate' => [0, "deploy\n/home/deploy\ndeploy\nextra\n", false],
    'no terminal newline' => [0, 'deploy\n/home/deploy\ndeploy', false],
    'mismatch' => [0, "other\n/home/deploy\ndeploy\n", false],
    'empty group' => [0, "deploy\n/home/deploy\n\n", false],
    'invalid group' => [0, "deploy\n/home/deploy\nBAD!\n", false],
    'relative home' => [0, "deploy\nhome/deploy\ndeploy\n", false],
    'double slash' => [0, "deploy\n/home//deploy\ndeploy\n", false],
    'dot segment' => [0, "deploy\n/home/./deploy\ndeploy\n", false],
    'dotdot segment' => [0, "deploy\n/home/../deploy\ndeploy\n", false],
    'carriage return' => [0, "deploy\n/home/deploy\r\ndeploy\n", false],
]);

it('bounds dependency exceptions and is registered as a singleton', function (): void {
    $executor = new class implements SshExecutor {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw new NodeProvisioningException('dependency', 'secret.code', 'secret-sentinel');
        }
    };
    $node = new Node(['user' => 'deploy', 'wireguard_ip' => '10.44.0.8']);
    $resolver = new SshManagedUserAccountResolver(
        $executor,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/key';
            }

            public function publicKey(): string
            {
                return 'key';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/hosts';
            }

            public function put(string $host, int $port, App\Infrastructure\Ssh\HostKey $key): void {}
        },
    );
    expect(fn () => $resolver->resolve($node))
        ->toThrow(
            fn (NodeProvisioningException $e): bool => (
                $e->step === 'managed-user'
                && $e->errorCode === 'node.managed_user_unavailable'
                && $e->getPrevious() === null
                && ! str_contains($e->getMessage(), 'secret')
            ),
        );
    expect(app(ManagedUserAccountResolver::class))->toBe(app(ManagedUserAccountResolver::class));
});
