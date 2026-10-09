<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Nodes\MacOsNodeConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Infrastructure\Ssh\SshKeyProvider;

it('checks the approved key, account, platform, and address without host mutation', function (): void {
    $ssh = new MacOsConvergerSsh;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:pinned');

    $observation = $converger->enroll('mini', '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned');

    expect($observation->architecture)
        ->toBe('arm64')
        ->and($observation->hostKey->fingerprint)
        ->toBe('SHA256:pinned')
        ->and($ssh->connections[0]->user)
        ->toBe('mini')
        ->and($ssh->connections[0]->host)
        ->toBe('192.0.2.40')
        ->and($ssh->connections[0]->identityFile)
        ->toBe('/tmp/orbit-gateway-key')
        ->and($ssh->commands[0]->arguments)
        ->toBe(['true'])
        ->and($ssh->commands[1]->arguments)
        ->toBe(['bash', '-seu', '--', '10.44.0.40'])
        ->and($ssh->commands[1]->input)
        ->toBe(MacOsNodeConverger::SCRIPT);

    $remote = implode("\n", array_map(
        static fn (RemoteCommand $command): string => implode(' ', $command->arguments)."\n".($command->input ?? ''),
        $ssh->commands,
    ));

    expect($remote)->not->toContain('useradd')
        ->and($remote)->not->toContain('apt-get')
        ->and($remote)->not->toContain('ufw')
        ->and($remote)->not->toContain('wg-quick')
        ->and($remote)->not->toContain('brew');
});

it('stops when the existing account does not authorize the gateway key', function (): void {
    $ssh = new MacOsConvergerSsh;
    $ssh->accountOk = false;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:pinned');

    expect(fn () => $converger->enroll('mini', '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('account')
                ->and($exception->errorCode)
                ->toBe('node.account_unavailable');
        });

    expect($ssh->commands)->toHaveCount(1);
});

it('refuses a platform or address that disagrees with the machine before any later step', function (string $platform, string $tunnel, string $present, string $step, string $code): void {
    $ssh = new MacOsConvergerSsh;
    $ssh->platform = $platform;
    $ssh->tunnel = $tunnel;
    $ssh->addressPresent = $present === '1';
    $converger = macos_converger($ssh, fingerprint: 'SHA256:pinned');

    expect(fn () => $converger->enroll('mini', '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception) use ($step, $code): void {
            expect($exception->step)->toBe($step)->and($exception->errorCode)->toBe($code);
        });
})->with([
    'linux host' => ['Linux', 'ok', '1', 'machine-architecture', 'node.platform_mismatch'],
    'address absent' => ['Darwin', 'ok', '0', 'identity', 'node.wireguard_required'],
    'tunnel unreadable' => ['Darwin', 'unreadable', '0', 'identity', 'node.wireguard_required'],
]);

it('does not connect when the approved fingerprint disagrees', function (): void {
    $ssh = new MacOsConvergerSsh;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:live');

    expect(fn () => $converger->enroll('mini', '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:approved'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->errorCode)->toBe('node.ssh_host_key_mismatch');
        });

    expect($ssh->commands)->toBeEmpty();
});

it('does not connect when a pinned host key changed', function (): void {
    $ssh = new MacOsConvergerSsh;
    $converger = macos_converger($ssh, fingerprint: 'SHA256:live');

    expect(fn () => $converger->enroll('mini', '192.0.2.40', 22, 'mini', '10.44.0.40', 'SHA256:pinned', 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->errorCode)->toBe('node.ssh_host_key_changed');
        });

    expect($ssh->commands)->toBeEmpty();
});

it('reports a failed host key scan', function (): void {
    $ssh = new MacOsConvergerSsh;
    $scanner = new MacOsConvergerScanner('SHA256:pinned');
    $scanner->fail = true;

    expect(fn () => macos_converger($ssh, scanner: $scanner)->enroll('mini', '192.0.2.40', 22, 'mini', '10.44.0.40', null, 'SHA256:pinned'))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->step)
                ->toBe('ssh-host-key')
                ->and($exception->errorCode)
                ->toBe('node.ssh_host_key_scan_failed');
        });

    expect($ssh->commands)->toBeEmpty();
});

function macos_converger(MacOsConvergerSsh $ssh, ?MacOsConvergerScanner $scanner = null, string $fingerprint = 'SHA256:pinned'): MacOsNodeConverger
{
    return new MacOsNodeConverger(
        $scanner ?? new MacOsConvergerScanner($fingerprint),
        $ssh,
        new MacOsConvergerKeys,
    );
}

final class MacOsConvergerKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/orbit-gateway-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 GATEWAY';
    }
}

final class MacOsConvergerScanner implements HostKeyScanner
{
    public bool $fail = false;

    public function __construct(public string $fingerprint) {}

    public function scan(string $host, int $port, ?SshConnection $via = null): HostKey
    {
        if ($this->fail) {
            throw new SshHostKeyScanException('scan failed');
        }

        return new HostKey('ssh-ed25519', 'PUBLICKEY', $this->fingerprint);
    }
}

final class MacOsConvergerSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public string $platform = 'Darwin';

    public string $architecture = 'arm64';

    public string $tunnel = 'ok';

    public bool $addressPresent = true;

    public bool $accountOk = true;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        if ($command->arguments[0] === 'true') {
            return new CommandResult($this->accountOk ? 0 : 255, '', $this->accountOk ? '' : 'Permission denied', 1, false);
        }

        return new CommandResult(
            0,
            $this->platform."\n".$this->architecture."\n".$this->tunnel."\n".($this->addressPresent ? '1' : '0')."\n",
            '',
            1,
            false,
        );
    }
}
