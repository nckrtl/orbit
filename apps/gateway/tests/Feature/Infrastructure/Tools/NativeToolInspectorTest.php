<?php

declare(strict_types=1);

use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\NativeToolInspector;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeToolManager;
use Tests\Support\ToolManagerFakeSshExecutor;

it('returns bounded installed state and normalized version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = ['1.2.3'];
    $tool = Tool::make(['package' => 'example']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $record = \App\Models\ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeTrue()->and($data->normalizedVersion)->toBe('1.2.3');
});

it('returns absent state when the manager reports no installed version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = [null];
    $tool = Tool::make(['package' => 'example']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $record = \App\Models\ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeFalse()->and($data->normalizedVersion)->toBeNull();
});

it('reports an APT package with retained configuration as bounded absence', function (): void {
    $ssh = new ToolManagerFakeSshExecutor([
        new CommandResult(
            exitCode: 0,
            stdout: "deinstall ok config-files\n1:2.4.3-1ubuntu2\n",
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $manager = new AptToolManager(
        commands: new RemoteToolCommandRunner(
            ssh: $ssh,
            keys: new class implements SshKeyProvider {
                public function privateKeyPath(): string
                {
                    return '/tmp/orbit/id_ed25519';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 AAAATEST orbit@test';
                }
            },
            knownHosts: new class implements KnownHostsStore {
                public function path(): string
                {
                    return '/tmp/orbit/known_hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ),
        versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
    );
    $node = Node::make([
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.43',
    ]);
    $node->setAttribute('id', 1);
    $tool = Tool::make(['package' => 'redis-server']);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt,
    ]));

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeFalse()->and($data->normalizedVersion)->toBeNull();
    expect($ssh->arguments())->toBe([
        ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', 'redis-server'],
    ]);
});

it('fails closed when ownership is invalid', function (): void {
    $tool = Tool::make(['package' => 'example']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', \App\Models\ToolManagerRecord::make([
        'node_id' => 2,
        'name' => ToolManagerName::Apt,
    ]));

    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([new FakeToolManager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class, '');
});

it('fails closed for unsupported, unknown, throwing, and unnormalizable managers', function (): void {
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $record = \App\Models\ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt]);
    $tool = Tool::make(['package' => 'example']);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);
    $manager = new FakeToolManager;
    $manager->supports = false;
    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class);
    $manager->supports = true;
    $manager->installedVersions = [new RuntimeException('secret-output')];
    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class, '');
    $manager->installedVersions = ['not-a-version'];
    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class);
});

it('uses only the read-only installed version interaction and ignores stored version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = ['1.2.3'];
    $tool = Tool::make(['package' => 'example', 'installed_version' => '99.99.99']);
    $node = \App\Models\Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', \App\Models\ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt,
    ]));
    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);
    expect($data->normalizedVersion)->toBe('1.2.3')->and($manager->calls)->toBe(['installedVersion']);
});
