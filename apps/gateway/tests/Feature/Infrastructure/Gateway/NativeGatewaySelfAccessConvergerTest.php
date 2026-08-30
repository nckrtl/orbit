<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('appends the managed public key to authorized_keys when absent', function (): void {
    ['converger' => $converger, 'home' => $home] = gateway_self_access_converger();

    try {
        $converger->converge(gateway_self_access_node());

        expect(file_get_contents($home.'/.ssh/authorized_keys'))
            ->toBe("ssh-ed25519 MANAGEDKEY orbit-gateway\n")
            ->and(fileperms($home.'/.ssh') & 0o777)
            ->toBe(0o700)
            ->and(fileperms($home.'/.ssh/authorized_keys') & 0o777)
            ->toBe(0o600);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('does not duplicate the managed public key on a second convergence', function (): void {
    ['converger' => $converger, 'home' => $home] = gateway_self_access_converger();

    try {
        $converger->converge(gateway_self_access_node());
        $converger->converge(gateway_self_access_node());

        expect(file_get_contents($home.'/.ssh/authorized_keys'))
            ->toBe("ssh-ed25519 MANAGEDKEY orbit-gateway\n");
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('pins the gateway host key for its own WireGuard address', function (): void {
    [
        'converger' => $converger,
        'processes' => $processes,
        'knownHostsPath' => $knownHostsPath,
        'home' => $home,
    ] = gateway_self_access_converger();

    try {
        $converger->converge(gateway_self_access_node());

        expect(file_get_contents($knownHostsPath))
            ->toBe("10.44.0.1 ssh-ed25519 HOSTKEYVALUE\n")
            ->and($processes->invocations)
            ->not
            ->toBeEmpty()
            ->and($processes->invocations[0]->arguments)
            ->toBe(['ssh-keygen', '-lf', '-', '-E', 'sha256']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('pins the gateway host key idempotently by replacing the prior pinned line', function (): void {
    [
        'converger' => $converger,
        'knownHostsPath' => $knownHostsPath,
        'home' => $home,
    ] = gateway_self_access_converger();

    try {
        $converger->converge(gateway_self_access_node());
        $converger->converge(gateway_self_access_node());

        expect(file_get_contents($knownHostsPath))
            ->toBe("10.44.0.1 ssh-ed25519 HOSTKEYVALUE\n");
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('fails closed when authorized_keys is a symlink instead of a regular file', function (): void {
    ['converger' => $converger, 'home' => $home] = gateway_self_access_converger();
    mkdir(directory: $home.'/.ssh', permissions: 0o700, recursive: true);
    $target = $home.'/.ssh/actual-target';
    file_put_contents($target, data: 'not authorized_keys');
    symlink($target, $home.'/.ssh/authorized_keys');

    try {
        expect(fn () => $converger->converge(gateway_self_access_node()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-self-access')
                    ->and($exception->errorCode)
                    ->toBe('gateway.self_access_failed');
            })
            ->and(is_link($home.'/.ssh/authorized_keys'))
            ->toBeTrue()
            ->and(file_get_contents($target))
            ->toBe('not authorized_keys');
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('fails closed when the home directory cannot be resolved', function (): void {
    $home = sys_get_temp_dir().'/orbit-gateway-self-access-'.Str::uuid();
    $converger = new NativeGatewaySelfAccessConverger(
        processes: new class implements ProcessRunner {
            public function run(ProcessInvocation $invocation): CommandResult
            {
                return new CommandResult(1, '', 'unexpected process invocation', 1, false);
            }
        },
        knownHosts: new KnownHostsRepository($home.'/ssh/known_hosts'),
        sshKeys: gateway_self_access_fake_ssh_keys(),
        homeDirectory: static fn (string $user): string|false => false,
    );

    try {
        expect(fn () => $converger->converge(gateway_self_access_node()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('gateway-self-access')
                    ->and($exception->errorCode)
                    ->toBe('gateway.self_access_failed');
            });
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

function gateway_self_access_node(): Node
{
    return new Node([
        'name' => 'gateway',
        'user' => 'orbit',
        'wireguard_address' => '10.44.0.1',
    ]);
}

function gateway_self_access_fake_ssh_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/does/not/matter';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 MANAGEDKEY orbit-gateway';
        }
    };
}

/**
 * @return array{
 *     converger: NativeGatewaySelfAccessConverger,
 *     processes: object&ProcessRunner,
 *     knownHostsPath: string,
 *     home: string,
 * }
 */
function gateway_self_access_converger(): array
{
    $home = sys_get_temp_dir().'/orbit-gateway-self-access-'.Str::uuid();
    $knownHostsPath = $home.'/ssh/known_hosts';
    $hostPublicKeyPath = $home.'/etc-ssh/ssh_host_ed25519_key.pub';
    mkdir(directory: dirname($hostPublicKeyPath), permissions: 0o700, recursive: true);
    file_put_contents($hostPublicKeyPath, data: "ssh-ed25519 HOSTKEYVALUE root@gateway\n");
    $processes = new class implements ProcessRunner {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return new CommandResult(0, '256 SHA256:hostfingerprint root@gateway (ED25519)', '', 2, false);
        }
    };
    $converger = new NativeGatewaySelfAccessConverger(
        processes: $processes,
        knownHosts: new KnownHostsRepository($knownHostsPath),
        sshKeys: gateway_self_access_fake_ssh_keys(),
        homeDirectory: static fn (string $user): string => $home,
        hostPublicKeyPath: $hostPublicKeyPath,
    );

    return [
        'converger' => $converger,
        'processes' => $processes,
        'knownHostsPath' => $knownHostsPath,
        'home' => $home,
    ];
}
