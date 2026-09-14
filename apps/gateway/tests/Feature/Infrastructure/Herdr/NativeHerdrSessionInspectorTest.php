<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Herdr\NativeHerdrSessionInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\HerdrSession;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('inspects the supported Herdr session snapshot command', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, json_encode([
            'id' => 'cli:api:snapshot',
            'result' => [
                'type' => 'session_snapshot',
                'snapshot' => [
                    'version' => '0.9.0',
                    'protocol' => 22,
                    'panes' => [[
                        'pane_id' => 'wH:p3',
                        'terminal_id' => 'term_65b6c6719fb573',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR), '', 1, false),
    ]);
    $inspection = (new NativeHerdrSessionInspector(herdr_inspector_ssh($transport)))->inspect(
        new HerdrSession(['session' => 'commander-tasks']),
        herdr_inspector_node(),
    );

    expect($transport->commands)
        ->toHaveCount(1)
        ->and($transport->commands[0]->arguments)
        ->toBe([
            '/home/linuxbrew/.linuxbrew/bin/herdr',
            '--session',
            'commander-tasks',
            'api',
            'snapshot',
        ])
        ->and($inspection->version)->toBe('0.9.0')
        ->and($inspection->protocol)->toBe(22)
        ->and($inspection->handoffSupported)->toBeFalse()
        ->and($inspection->panes)->toHaveCount(1)
        ->and($inspection->panes[0]->pane)->toBe('wH:p3')
        ->and($inspection->panes[0]->terminal)->toBe('term_65b6c6719fb573')
        ->and($inspection->panes[0]->live)->toBeTrue();
});

it('rejects malformed Herdr session snapshots', function (string $output): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(0, $output, '', 1, false)]);

    expect(fn () => (new NativeHerdrSessionInspector(herdr_inspector_ssh($transport)))->inspect(
        new HerdrSession(['session' => 'commander-tasks']),
        herdr_inspector_node(),
    ))->toThrow(ResourceOperationException::class, 'invalid snapshot');
})->with([
    'invalid JSON' => '{',
    'wrong response type' => '{"result":{"type":"other","snapshot":{}}}',
    'missing snapshot' => '{"result":{"type":"session_snapshot"}}',
]);

function herdr_inspector_node(): Node
{
    return new Node([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.8',
        'user' => 'nckrtl',
    ]);
}

function herdr_inspector_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
{
    return new AppDevSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}
