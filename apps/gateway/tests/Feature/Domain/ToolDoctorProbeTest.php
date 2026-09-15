<?php

declare(strict_types=1);

use App\Actions\Doctor\ToolDoctorProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\VersionConstraint;
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
use Illuminate\Support\Facades\DB;
use Tests\Support\InspectsToolsIndividually;
use Tests\Support\ToolManagerFakeSshExecutor;

function tool_probe_node(): Node
{
    return Node::create(['name' => 'probe-node', 'public_ssh_host' => '127.0.0.1']);
}

it('reports no rows as healthy', function (): void {
    $node = tool_probe_node();
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            throw new RuntimeException('unexpected');
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty();
});

it('reports an absent managed tool as drift', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(false, null);
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($report->issues[0]->code)->toBe('tool.not_installed');
});

it('keeps an installed tool healthy when its normalized version satisfies valid intent', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'version_constraint' => '^1.2',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, '1.2.3');
        }
    };

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty();
});

it('does not inspect rows when unreachable', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'status' => 'installed',
    ]);
    $calls = 0;
    $inspector = new class($calls) implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function __construct(
            public int &$calls,
        ) {}

        public function inspect(Tool $tool): ToolInspectionData
        {
            $this->calls++;
            throw new RuntimeException;
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(false, null, null, false)));
    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('tool.node_unreachable')
        ->and($calls)
        ->toBe(0);
});

it('keeps unconstrained installed tools healthy and reports bounded mismatch and invalid intent', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'free',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'bad',
        'version_constraint' => 'not valid',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'mismatch',
        'version_constraint' => '^2.0',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, $tool->package === 'mismatch' ? '1.0.0' : '1.2.3');
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)),
    );
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.inspection_failed', 'tool.version_mismatch'])
        ->and($report->issues[0]->expected)
        ->toBe('verifiable')
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable');
});

it('keeps installed APT meta-packages healthy when live versions are not SemVer', function (
    string $package,
    string $rawVersion,
): void {
    $node = tool_probe_node();
    $node->forceFill([
        'platform' => 'linux',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.43',
    ])->save();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => ToolManagerName::Apt->value, 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => $package,
        'installed_version' => $rawVersion,
        'status' => 'installed',
    ]);
    $ssh = new ToolManagerFakeSshExecutor([
        new CommandResult(
            exitCode: 0,
            stdout: "install ok installed\n{$rawVersion}\n",
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $inspector = new NativeToolInspector(new ToolManagerRegistry([
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
    ]));

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty();
    expect($ssh->arguments())->toBe([
        ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', $package],
    ]);
})->with([
    'postgresql-client' => ['postgresql-client', '16+257build1.1'],
    'golang-go' => ['golang-go', '2:1.24~2build1'],
]);

it('keeps an unconstrained installed tool healthy when its version cannot be normalized', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'postgresql-client',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, null);
        }
    };

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty();
});

it('reports a constrained installed tool as unverifiable when its version cannot be normalized', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'golang-go',
        'version_constraint' => '^1.24',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, null);
        }
    };

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->issues[0]->code)
        ->toBe('tool.inspection_failed')
        ->and($report->issues[0]->expected)
        ->toBe('verifiable')
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable');
});

it('converts inspector failures to bounded unverifiable findings', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            throw new ToolInspectionException;
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)),
    );
    expect($report->issues[0]->code)
        ->toBe('tool.inspection_failed')
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable');
});

it('queries only the selected node and preserves tool id order', function (): void {
    $node = tool_probe_node();
    $other = Node::create(['name' => 'other-node', 'public_ssh_host' => '127.0.0.1']);
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $otherManager = ToolManagerRecord::create(['node_id' => $other->id, 'name' => 'apt', 'status' => 'active']);
    $first = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'first',
        'status' => 'installed',
    ]);
    $second = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'second',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $other->id,
        'tool_manager_id' => $otherManager->id,
        'package' => 'other',
        'status' => 'installed',
    ]);
    $seen = [];
    $inspector = new class($seen) implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function __construct(
            public array &$seen,
        ) {}

        public function inspect(Tool $tool): ToolInspectionData
        {
            $this->seen[] = $tool->id;

            return new ToolInspectionData(false, null);
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($seen)
        ->toBe([$first->id, $second->id])
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.not_installed', 'tool.not_installed'])
        ->and(array_map(static fn ($issue): int => (int) $issue->resourceId, $report->issues))
        ->toBe([$first->id, $second->id]);
});

it('eager loads inspector relationships in the bounded tool query', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'first',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'second',
        'status' => 'installed',
    ]);
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            $tool->node;
            $tool->manager;

            return new ToolInspectionData(false, null);
        }
    };
    new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($queries)->toBe(3);
});

it('inspects multiple Composer tools with one installed-package command and keeps tool order', function (): void {
    $node = tool_probe_linux_node();
    $manager = ToolManagerRecord::create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Composer->value,
        'status' => 'active',
    ]);
    $second = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'phpunit/phpunit',
        'version_constraint' => '^11.0',
        'status' => 'installed',
    ]);
    $first = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'laravel/installer',
        'status' => 'installed',
    ]);
    $missing = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'missing/pkg',
        'status' => 'installed',
    ]);
    [$inspector, $ssh] = tool_probe_composer_inspector([
        tool_probe_composer_show([
            ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
            ['name' => 'phpunit/phpunit', 'version' => 'v11.0.0'],
            ['name' => 'other/dup', 'version' => 'v1.0.0'],
            ['name' => 'other/dup', 'version' => 'v1.1.0'],
        ]),
    ]);

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(3);
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.not_installed']);
    expect($report->issues[0]->resourceId)->toBe($missing->id);
    expect($ssh->arguments())->toBe([tool_probe_composer_show_arguments()]);
    expect($first->id)->toBeLessThan($missing->id);
    expect($second->id)->toBeLessThan($first->id);
});

it('marks a failed Composer snapshot unverifiable and continues other managers without leaking diagnostics', function (): void {
    $node = tool_probe_linux_node();
    $composer = ToolManagerRecord::create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Composer->value,
        'status' => 'active',
    ]);
    $apt = ToolManagerRecord::create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt->value,
        'status' => 'active',
    ]);
    $first = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $composer->id,
        'package' => 'laravel/installer',
        'status' => 'installed',
    ]);
    $second = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $composer->id,
        'package' => 'phpunit/phpunit',
        'status' => 'installed',
    ]);
    $jq = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $apt->id,
        'package' => 'jq',
        'status' => 'installed',
    ]);
    $ssh = new ToolManagerFakeSshExecutor([
        new CommandResult(
            exitCode: 3,
            stdout: 'secret-stdout',
            stderr: 'secret-stderr',
            durationMs: 10,
            truncated: false,
        ),
        new CommandResult(
            exitCode: 0,
            stdout: "install ok installed\n1:2.4.3-1ubuntu2\n",
            stderr: '',
            durationMs: 10,
            truncated: false,
        ),
    ]);
    $inspector = new NativeToolInspector(new ToolManagerRegistry([
        tool_probe_composer_manager($ssh),
        tool_probe_apt_manager($ssh),
    ]));

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(3);
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.inspection_failed', 'tool.inspection_failed']);
    expect(array_map(static fn ($issue): int => (int) $issue->resourceId, $report->issues))
        ->toBe([$first->id, $second->id]);
    expect($report->issues[0]->observed)->toBe('unverifiable');
    expect(json_encode($report))->not->toContain('secret');
    expect($ssh->arguments())->toBe([
        tool_probe_composer_show_arguments(),
        ['dpkg-query', '--show', '--showformat=${Status}\n${Version}\n', '--', 'jq'],
    ]);
    expect($jq->package)->toBe('jq');
});

it('reads a fresh Composer inventory on repeated doctor inspections', function (): void {
    $node = tool_probe_linux_node();
    $manager = ToolManagerRecord::create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Composer->value,
        'status' => 'active',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'laravel/installer',
        'version_constraint' => '^5.16',
        'status' => 'installed',
    ]);
    [$inspector, $ssh] = tool_probe_composer_inspector([
        tool_probe_composer_show([
            ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
        ]),
        tool_probe_composer_show([
            ['name' => 'laravel/installer', 'version' => 'v5.15.0'],
        ]),
    ]);
    $probe = new ToolDoctorProbe($inspector, new VersionConstraint);
    $context = new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true));

    $before = $probe->inspect($context);
    $after = $probe->inspect($context);

    expect($before->issues)->toBeEmpty();
    expect($after->issues[0]->code)->toBe('tool.version_mismatch');
    expect($ssh->arguments())->toBe([
        tool_probe_composer_show_arguments(),
        tool_probe_composer_show_arguments(),
    ]);
});

it('fails a requested Composer package that appears twice and keeps unrelated inventory duplicates', function (): void {
    $node = tool_probe_linux_node();
    $manager = ToolManagerRecord::create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Composer->value,
        'status' => 'active',
    ]);
    $installer = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'laravel/installer',
        'status' => 'installed',
    ]);
    $phpunit = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'phpunit/phpunit',
        'status' => 'installed',
    ]);
    [$inspector, $ssh] = tool_probe_composer_inspector([
        tool_probe_composer_show([
            ['name' => 'laravel/installer', 'version' => 'v5.16.0'],
            ['name' => 'laravel/installer', 'version' => 'v5.17.0'],
            ['name' => 'phpunit/phpunit', 'version' => 'v11.0.0'],
            ['name' => 'other/dup', 'version' => 'v1.0.0'],
            ['name' => 'other/dup', 'version' => 'v1.1.0'],
        ]),
    ]);

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(2);
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.inspection_failed']);
    expect($report->issues[0]->resourceId)->toBe($installer->id);
    expect($phpunit->package)->toBe('phpunit/phpunit');
    expect($ssh->arguments())->toHaveCount(1);
});

function tool_probe_linux_node(): Node
{
    $node = tool_probe_node();
    $node->forceFill([
        'platform' => 'linux',
        'user' => 'orbit',
        'wireguard_ip' => '10.8.0.43',
    ])->save();

    return $node;
}

/**
 * @param  list<CommandResult>  $results
 * @return array{NativeToolInspector, ToolManagerFakeSshExecutor}
 */
function tool_probe_composer_inspector(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);

    return [
        new NativeToolInspector(new ToolManagerRegistry([
            tool_probe_composer_manager($ssh),
        ])),
        $ssh,
    ];
}

function tool_probe_composer_manager(ToolManagerFakeSshExecutor $ssh): ComposerToolManager
{
    return new ComposerToolManager(
        commands: new RemoteToolCommandRunner(
            ssh: $ssh,
            keys: tool_probe_keys(),
            knownHosts: tool_probe_known_hosts(),
        ),
        parser: new ComposerDryRunVersionParser,
        inventory: new ComposerInstalledInventoryParser,
        versions: new SemverVersionNormalizer,
    );
}

function tool_probe_apt_manager(ToolManagerFakeSshExecutor $ssh): AptToolManager
{
    return new AptToolManager(
        commands: new RemoteToolCommandRunner(
            ssh: $ssh,
            keys: tool_probe_keys(),
            knownHosts: tool_probe_known_hosts(),
        ),
        versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
    );
}

function tool_probe_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAATEST orbit@test';
        }
    };
}

function tool_probe_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

/** @param list<array<string, string>> $entries */
function tool_probe_composer_show(array $entries): CommandResult
{
    return new CommandResult(
        exitCode: 0,
        stdout: json_encode(['installed' => $entries], flags: JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 10,
        truncated: false,
    );
}

/** @return non-empty-list<string> */
function tool_probe_composer_show_arguments(): array
{
    return ['env', 'COMPOSER_HOME=/opt/orbit/composer', '/usr/bin/composer', 'global', 'show', '--format=json', '--no-ansi'];
}
