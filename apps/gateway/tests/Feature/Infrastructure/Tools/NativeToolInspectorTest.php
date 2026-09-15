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
use App\Infrastructure\Tools\ComposerDryRunVersionParser;
use App\Infrastructure\Tools\ComposerInstalledInventoryParser;
use App\Infrastructure\Tools\ComposerToolManager;
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
    $node = Node::make();
    $node->setAttribute('id', 1);
    $record = ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt->value]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeTrue()->and($data->normalizedVersion)->toBe('1.2.3');
});

it('returns absent state when the manager reports no installed version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = [null];
    $tool = Tool::make(['package' => 'example']);
    $node = Node::make();
    $node->setAttribute('id', 1);
    $record = ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt->value]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', $record);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeFalse()->and($data->normalizedVersion)->toBeNull();
});

it('reports an APT package with retained configuration as bounded absence', function (): void {
    [$manager, $ssh] = native_apt_inspector_manager([
        new CommandResult(
            exitCode: 0,
            stdout: "deinstall ok config-files\n1:2.4.3-1ubuntu2\n",
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $tool = native_apt_inspector_tool('redis-server');

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeFalse()->and($data->normalizedVersion)->toBeNull();
    expect($ssh->arguments())->toBe([
        ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', 'redis-server'],
    ]);
});

it('reports installed APT meta-packages as installed when Debian versions are not SemVer', function (
    string $package,
    string $rawVersion,
): void {
    [$manager, $ssh] = native_apt_inspector_manager([
        new CommandResult(
            exitCode: 0,
            stdout: "install ok installed\n{$rawVersion}\n",
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $tool = native_apt_inspector_tool($package);

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeTrue()->and($data->normalizedVersion)->toBeNull();
    expect($ssh->arguments())->toBe([
        ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', $package],
    ]);
})->with([
    'postgresql-client meta-package' => ['postgresql-client', '16+257build1.1'],
    'golang-go meta-package' => ['golang-go', '2:1.24~2build1'],
]);

it('fails closed when ownership is invalid', function (): void {
    $tool = Tool::make(['package' => 'example']);
    $node = Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => 2,
        'name' => ToolManagerName::Apt->value,
    ]));

    expect(fn (): mixed => new NativeToolInspector(new ToolManagerRegistry([new FakeToolManager]))->inspect($tool))
        ->toThrow(ToolInspectionException::class, '');
});

it('fails closed for unsupported and throwing managers', function (): void {
    $node = Node::make();
    $node->setAttribute('id', 1);
    $record = ToolManagerRecord::make(['node_id' => 1, 'name' => ToolManagerName::Apt->value]);
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
});

it('returns installed state without a normalized version when the manager version is unparseable', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = ['not-a-version'];
    $tool = Tool::make(['package' => 'example']);
    $node = Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt->value,
    ]));

    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);

    expect($data->installed)->toBeTrue()->and($data->normalizedVersion)->toBeNull();
});

it('uses only the read-only installed version interaction and ignores stored version', function (): void {
    $manager = new FakeToolManager;
    $manager->installedVersions = ['1.2.3'];
    $tool = Tool::make(['package' => 'example', 'installed_version' => '99.99.99']);
    $node = Node::make();
    $node->setAttribute('id', 1);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt->value,
    ]));
    $data = new NativeToolInspector(new ToolManagerRegistry([$manager]))->inspect($tool);
    expect($data->normalizedVersion)->toBe('1.2.3')->and($manager->calls)->toBe(['installedVersion']);
});

it('inspects multiple Composer tools from one installed inventory command', function (): void {
    [$manager, $ssh] = native_composer_inspector_manager([
        new CommandResult(
            exitCode: 0,
            stdout: json_encode([
                'installed' => [
                    ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
                    ['name' => 'phpunit/phpunit', 'version' => 'v11.0.0'],
                    ['name' => 'other/dup', 'version' => 'v1.0.0'],
                    ['name' => 'other/dup', 'version' => 'v1.1.0'],
                ],
            ], flags: JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $installer = native_composer_inspector_tool('laravel/installer', nodeId: 1);
    $phpunit = native_composer_inspector_tool('phpunit/phpunit', nodeId: 1);
    $missing = native_composer_inspector_tool('missing/pkg', nodeId: 1);

    $outcomes = new NativeToolInspector(new ToolManagerRegistry([$manager]))
        ->inspectMany([$phpunit, $installer, $missing]);

    expect($outcomes[0]->data()->installed)->toBeTrue()
        ->and($outcomes[0]->data()->normalizedVersion)->toBe('11.0.0')
        ->and($outcomes[1]->data()->installed)->toBeTrue()
        ->and($outcomes[1]->data()->normalizedVersion)->toBe('5.16.0')
        ->and($outcomes[2]->data()->installed)->toBeFalse()
        ->and($outcomes[2]->data()->normalizedVersion)->toBeNull();
    expect($ssh->arguments())->toBe([
        ['env', 'COMPOSER_HOME=/opt/orbit/composer', '/usr/bin/composer', 'global', 'show', '--format=json', '--no-ansi'],
    ]);
});

it('fails a requested Composer package that appears twice and keeps unrelated inventory duplicates', function (): void {
    [$manager, $ssh] = native_composer_inspector_manager([
        new CommandResult(
            exitCode: 0,
            stdout: json_encode([
                'installed' => [
                    ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
                    ['name' => 'laravel/installer', 'version' => 'v5.17.0'],
                    ['name' => 'phpunit/phpunit', 'version' => 'v11.0.0'],
                    ['name' => 'other/dup', 'version' => 'v1.0.0'],
                    ['name' => 'other/dup', 'version' => 'v1.1.0'],
                ],
            ], flags: JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $installer = native_composer_inspector_tool('laravel/installer', nodeId: 1);
    $phpunit = native_composer_inspector_tool('phpunit/phpunit', nodeId: 1);

    $outcomes = new NativeToolInspector(new ToolManagerRegistry([$manager]))
        ->inspectMany([$installer, $phpunit]);

    expect(fn (): mixed => $outcomes[0]->data())->toThrow(ToolInspectionException::class, '');
    expect($outcomes[1]->data()->installed)->toBeTrue()
        ->and($outcomes[1]->data()->normalizedVersion)->toBe('11.0.0');
    expect($ssh->arguments())->toHaveCount(1);
});

it('reads a fresh Composer inventory for each inspection and each node', function (): void {
    [$manager, $ssh] = native_composer_inspector_manager([
        new CommandResult(
            exitCode: 0,
            stdout: json_encode([
                'installed' => [
                    ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
                ],
            ], flags: JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
        new CommandResult(
            exitCode: 0,
            stdout: json_encode([
                'installed' => [
                    ['name' => 'laravel/installer', 'version' => 'v5.17.0'],
                ],
            ], flags: JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
        new CommandResult(
            exitCode: 0,
            stdout: json_encode([
                'installed' => [
                    ['name' => 'laravel/installer', 'version' => 'v5.18.0'],
                ],
            ], flags: JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $first = native_composer_inspector_tool('laravel/installer', nodeId: 1);
    $second = native_composer_inspector_tool('phpunit/phpunit', nodeId: 1);
    $otherNode = native_composer_inspector_tool('laravel/installer', nodeId: 2);
    $inspector = new NativeToolInspector(new ToolManagerRegistry([$manager]));

    $firstPass = $inspector->inspectMany([$first, $second]);
    $secondPass = $inspector->inspectMany([$first]);
    $other = $inspector->inspect($otherNode);

    expect($firstPass[0]->data()->normalizedVersion)->toBe('5.16.0')
        ->and($firstPass[1]->data()->installed)->toBeFalse()
        ->and($secondPass[0]->data()->normalizedVersion)->toBe('5.17.0')
        ->and($other->normalizedVersion)->toBe('5.18.0');
    expect($ssh->arguments())->toHaveCount(3);
});

it('marks Composer tools unverifiable when the inventory snapshot fails and keeps other managers', function (): void {
    [$composer, $ssh] = native_composer_inspector_manager([
        new CommandResult(
            exitCode: 3,
            stdout: 'secret-stdout',
            stderr: 'secret-stderr',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $apt = new FakeToolManager;
    $apt->installedVersions = ['1.2.3'];
    $composerTool = native_composer_inspector_tool('laravel/installer', nodeId: 1);
    $secondComposer = native_composer_inspector_tool('phpunit/phpunit', nodeId: 1);
    $aptTool = Tool::make(['package' => 'jq']);
    $aptTool->setRelation('node', $composerTool->getRelationValue('node'));
    $aptTool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt->value,
    ]));

    $outcomes = new NativeToolInspector(new ToolManagerRegistry([$composer, $apt]))
        ->inspectMany([$composerTool, $aptTool, $secondComposer]);

    expect(fn (): mixed => $outcomes[0]->data())->toThrow(ToolInspectionException::class, '');
    expect($outcomes[1]->data()->installed)->toBeTrue()
        ->and($outcomes[1]->data()->normalizedVersion)->toBe('1.2.3');
    expect(fn (): mixed => $outcomes[2]->data())->toThrow(ToolInspectionException::class, '');
    expect($ssh->arguments())->toHaveCount(1);
    expect($apt->calls)->toBe(['installedVersion']);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{AptToolManager, ToolManagerFakeSshExecutor}
 */
function native_apt_inspector_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new AptToolManager(
            commands: new RemoteToolCommandRunner(
                ssh: $ssh,
                keys: new class implements SshKeyProvider
                {
                    public function privateKeyPath(): string
                    {
                        return '/tmp/orbit/id_ed25519';
                    }

                    public function publicKey(): string
                    {
                        return 'ssh-ed25519 AAAATEST orbit@test';
                    }
                },
                knownHosts: new class implements KnownHostsStore
                {
                    public function path(): string
                    {
                        return '/tmp/orbit/known_hosts';
                    }

                    public function put(string $host, int $port, HostKey $key): void {}
                },
            ),
            versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
        ),
        $ssh,
    ];
}

function native_apt_inspector_tool(string $package): Tool
{
    $node = Node::make([
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.43',
    ]);
    $node->setAttribute('id', 1);
    $tool = Tool::make(['package' => $package]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => 1,
        'name' => ToolManagerName::Apt->value,
    ]));

    return $tool;
}

/**
 * @param  list<CommandResult>  $results
 * @return array{ComposerToolManager, ToolManagerFakeSshExecutor}
 */
function native_composer_inspector_manager(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new ComposerToolManager(
            commands: new RemoteToolCommandRunner(
                ssh: $ssh,
                keys: new class implements SshKeyProvider
                {
                    public function privateKeyPath(): string
                    {
                        return '/tmp/orbit/id_ed25519';
                    }

                    public function publicKey(): string
                    {
                        return 'ssh-ed25519 AAAATEST orbit@test';
                    }
                },
                knownHosts: new class implements KnownHostsStore
                {
                    public function path(): string
                    {
                        return '/tmp/orbit/known_hosts';
                    }

                    public function put(string $host, int $port, HostKey $key): void {}
                },
            ),
            parser: new ComposerDryRunVersionParser,
            inventory: new ComposerInstalledInventoryParser,
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

function native_composer_inspector_tool(string $package, int $nodeId): Tool
{
    $node = Node::make([
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.'.$nodeId,
    ]);
    $node->setAttribute('id', $nodeId);
    $tool = Tool::make(['package' => $package]);
    $tool->setRelation('node', $node);
    $tool->setRelation('manager', ToolManagerRecord::make([
        'node_id' => $nodeId,
        'name' => ToolManagerName::Composer->value,
    ]));

    return $tool;
}
