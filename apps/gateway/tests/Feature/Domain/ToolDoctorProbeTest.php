<?php

declare(strict_types=1);

use App\Actions\Doctor\ToolDoctorProbe;
use App\Domain\Doctor\DoctorFamilyStatus;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\Doctor\SharedInstalledPackageInventory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\InspectsToolsIndividually;
use Tests\Support\ToolManagerFakeSshExecutor;
use Tests\Support\UnsupportedPackageInventory;

function tool_doctor_probe(ToolInspector $inspector, ?InstalledPackageInventory $inventory = null): ToolDoctorProbe
{
    return new ToolDoctorProbe(
        $inspector,
        new VersionConstraint,
        $inventory ?? new UnsupportedPackageInventory,
    );
}

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
    $report = tool_doctor_probe($inspector)
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
    $report = tool_doctor_probe($inspector)
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

    $report = tool_doctor_probe($inspector)
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
    $report = tool_doctor_probe($inspector)
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
    $report = tool_doctor_probe($inspector)->inspect(
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

    $report = tool_doctor_probe($inspector)
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

    $report = tool_doctor_probe($inspector)
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

    $report = tool_doctor_probe($inspector)
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
    $report = tool_doctor_probe($inspector)->inspect(
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
    $report = tool_doctor_probe($inspector)
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
    tool_doctor_probe($inspector)
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

    $report = tool_doctor_probe($inspector)
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

    $report = tool_doctor_probe($inspector)
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
    $probe = tool_doctor_probe($inspector);
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

    $report = tool_doctor_probe($inspector)
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

it('does not compare an unconstrained package with its last recorded version', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'installed_version' => '9.9.9',
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

    $report = tool_doctor_probe($inspector)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)),
    );

    expect($report->checked)->toBe(1)
        ->and($report->status)->toBe(DoctorFamilyStatus::Healthy)
        ->and($report->issues)->toBeEmpty();
});

it('names unregistered packages without a resource id and ignores informational findings in health', function (): void {
    $node = tool_probe_node();
    $report = tool_doctor_probe(
        tool_doctor_unused_inspector(),
        tool_doctor_scans([
            tool_doctor_scan(ToolManagerName::Brew, ToolInventoryScanState::Complete, [
                tool_doctor_package(ToolManagerName::Brew, 'zebra', '2.0.0'),
                tool_doctor_package(
                    ToolManagerName::Brew,
                    'openssl@3',
                    '3.4.0',
                    dependency: true,
                    adoption: ToolInventoryPackage::UNSUPPORTED,
                    block: ToolInventoryPackage::BLOCK_DEPENDENCY,
                ),
                tool_doctor_package(ToolManagerName::Brew, 'jq', '1.7.1', registered: true, toolId: 9),
            ]),
            tool_doctor_scan(ToolManagerName::BrewCask, ToolInventoryScanState::Complete, [
                tool_doctor_package(ToolManagerName::BrewCask, 'docker', '4.39.0'),
                tool_doctor_package(
                    ToolManagerName::BrewCask,
                    'local-font',
                    null,
                    adoption: ToolInventoryPackage::UNSUPPORTED,
                    block: ToolInventoryPackage::BLOCK_ARTIFACT,
                ),
            ]),
            tool_doctor_scan(ToolManagerName::Vp, ToolInventoryScanState::Absent),
        ]),
    )->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'macos', 'arm64', true)));

    expect($report->checked)->toBe(0)
        ->and($report->status)->toBe(DoctorFamilyStatus::Healthy)
        ->and(array_map(static fn ($issue): array => [$issue->code, $issue->kind->value, $issue->resourceId, $issue->resourceName, $issue->expected, $issue->observed], $report->issues))
        ->toBe([
            ['tool.inventory_scan', 'informational', null, 'vp', 'complete', 'absent'],
            ['tool.package_unregistered', 'informational', null, 'openssl@3', 'brew', 'version=3.4.0;dependency=yes;adoption=unsupported;block=dependency'],
            ['tool.package_unregistered', 'informational', null, 'zebra', 'brew', 'version=2.0.0;dependency=no;adoption=supported;block=none'],
            ['tool.package_unregistered', 'informational', null, 'docker', 'brew-cask', 'version=4.39.0;dependency=no;adoption=supported;block=none'],
            ['tool.package_unregistered', 'informational', null, 'local-font', 'brew-cask', 'version=unknown;dependency=no;adoption=unsupported;block=unsupported_artifact'],
        ]);
});

it('sorts registered issues before scan issues and unregistered packages', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $missing = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'missing',
        'status' => 'installed',
    ]);
    $mismatch = Tool::create([
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
            if ($tool->package === 'missing') {
                return new ToolInspectionData(false, null);
            }

            return new ToolInspectionData(true, '1.0.0');
        }
    };
    $longVersion = str_repeat('1', 65);

    $report = tool_doctor_probe($inspector, tool_doctor_scans([
        tool_doctor_scan(ToolManagerName::Brew, ToolInventoryScanState::Conflicting),
        tool_doctor_scan(ToolManagerName::BrewCask, ToolInventoryScanState::Incomplete),
        tool_doctor_scan(ToolManagerName::Vp, ToolInventoryScanState::Complete, [
            tool_doctor_package(ToolManagerName::Vp, 'zeta', $longVersion),
            tool_doctor_package(ToolManagerName::Vp, 'alpha', '1.0.0'),
        ]),
    ]))->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(2)
        ->and($report->status)->toBe(DoctorFamilyStatus::Unverifiable)
        ->and(array_map(static fn ($issue): array => [$issue->code, $issue->kind->value, $issue->resourceId, $issue->resourceName], $report->issues))
        ->toBe([
            ['tool.not_installed', 'drift', $missing->id, null],
            ['tool.version_mismatch', 'drift', $mismatch->id, null],
            ['tool.inventory_scan', 'informational', null, 'brew'],
            ['tool.inventory_scan', 'unverifiable', null, 'brew-cask'],
            ['tool.package_unregistered', 'informational', null, 'alpha'],
            ['tool.package_unregistered', 'informational', null, 'zeta'],
        ])
        ->and($report->issues[5]->observed)->toBe('version=unknown;dependency=no;adoption=supported;block=none');
});

it('skips inventory when the node is unreachable', function (bool $withTool): void {
    $node = tool_probe_node();
    if ($withTool) {
        $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
        Tool::create([
            'node_id' => $node->id,
            'tool_manager_id' => $manager->id,
            'package' => 'example',
            'status' => 'installed',
        ]);
    }
    $inventoryCalls = 0;
    $inventory = new class($inventoryCalls) implements InstalledPackageInventory
    {
        public function __construct(public int &$calls) {}

        public function inspect(Node $node): array
        {
            $this->calls++;

            return [];
        }
    };
    $inspector = new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            throw new RuntimeException('unreachable nodes are not inspected');
        }
    };

    $report = tool_doctor_probe($inspector, $inventory)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, false)),
    );

    expect($inventoryCalls)->toBe(0)
        ->and($report->checked)->toBe($withTool ? 1 : 0)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe($withTool ? ['tool.node_unreachable'] : [])
        ->and($report->status)->toBe($withTool ? DoctorFamilyStatus::Unverifiable : DoctorFamilyStatus::Healthy);
})->with([
    'tool rows' => [true],
    'no tool rows' => [false],
]);

it('keeps a conflicting unowned linux prefix informational when another manager has tools', function (): void {
    $node = tool_probe_linux_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => ToolManagerName::Apt->value, 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'installed_version' => '1.0.0',
        'status' => 'installed',
    ]);
    $toolsBefore = Tool::query()->count();
    $managersBefore = ToolManagerRecord::query()->count();
    $ssh = tool_doctor_bind_inventory([
        tool_doctor_result('', 43),
        tool_doctor_result('', 42),
    ]);

    $report = tool_doctor_probe(tool_doctor_installed_inspector(), app(SharedInstalledPackageInventory::class))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(1)
        ->and($report->status)->toBe(DoctorFamilyStatus::Healthy)
        ->and(array_map(static fn ($issue): array => [$issue->code, $issue->kind->value, $issue->resourceName, $issue->observed], $report->issues))
        ->toBe([
            ['tool.inventory_scan', 'informational', 'brew', 'conflicting'],
            ['tool.inventory_scan', 'informational', 'vp', 'absent'],
        ])
        ->and(json_encode($report))->not->toContain('brew-cask')
        ->and(Tool::query()->count())->toBe($toolsBefore)
        ->and(ToolManagerRecord::query()->count())->toBe($managersBefore);
    tool_doctor_assert_read_only($ssh);
});

it('marks a conflicting scope unverifiable only for a tool that uses that manager', function (): void {
    $node = tool_probe_linux_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => ToolManagerName::Brew->value, 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'status' => 'installed',
    ]);
    tool_doctor_bind_inventory([
        tool_doctor_result('', 43),
        tool_doctor_result('', 42),
    ]);

    $report = tool_doctor_probe(tool_doctor_installed_inspector(), app(SharedInstalledPackageInventory::class))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(1)
        ->and($report->status)->toBe(DoctorFamilyStatus::Unverifiable)
        ->and($report->issues[0]->code)->toBe('tool.inventory_scan')
        ->and($report->issues[0]->kind)->toBe(DoctorIssueKind::Unverifiable)
        ->and($report->issues[0]->resourceName)->toBe('brew')
        ->and($report->issues[0]->observed)->toBe('conflicting');
});

it('reports an incomplete scan as unverifiable even when the node has no tools', function (): void {
    $node = tool_probe_linux_node();
    $ssh = tool_doctor_bind_inventory([
        tool_doctor_result('/home/linuxbrew/.linuxbrew'."\n"),
        tool_doctor_result("x86_64\n"),
        tool_doctor_result('{', truncated: true),
        tool_doctor_result('[{"name":"left-pad","version":'),
    ]);

    $report = tool_doctor_probe(tool_doctor_unused_inspector(), app(SharedInstalledPackageInventory::class))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(0)
        ->and($report->status)->toBe(DoctorFamilyStatus::Unverifiable)
        ->and(array_map(static fn ($issue): array => [$issue->kind->value, $issue->resourceName, $issue->observed], $report->issues))
        ->toBe([
            ['unverifiable', 'brew', 'incomplete'],
            ['unverifiable', 'vp', 'incomplete'],
        ])
        ->and(Tool::query()->count())->toBe(0);
    tool_doctor_assert_read_only($ssh);
});

it('discovers an unsupported cask through the shared inventory without writing tools', function (): void {
    $node = tool_probe_node();
    $node->forceFill([
        'platform' => 'macos',
        'architecture' => 'arm64',
        'user' => 'mini',
        'wireguard_ip' => '10.8.0.45',
    ])->save();
    $brew = ToolManagerRecord::create(['node_id' => $node->id, 'name' => ToolManagerName::Brew->value, 'status' => 'active']);
    $registered = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $brew->id,
        'package' => 'ripgrep',
        'installed_version' => '0.0.1',
        'status' => 'installed',
    ]);
    $sha = str_repeat('ab', 32);
    $formulae = [tool_doctor_formula('ripgrep', '14.1.1', $sha), tool_doctor_formula('jq', '1.7.1', $sha)];
    $casks = [
        tool_doctor_cask('font-hack', '2.0.0'),
        tool_doctor_cask('docker', '4.39.0', [[
            'app' => ['Docker.app'],
            'target' => '/Applications/Docker.app',
        ]]),
    ];
    $toolsBefore = Tool::query()->count();
    $ssh = tool_doctor_bind_inventory([
        tool_doctor_result("/opt/homebrew\n"),
        tool_doctor_result("27.0.1\n"),
        tool_doctor_result(tool_doctor_inventory_json($formulae)),
        tool_doctor_result("jq\nripgrep\n"),
        tool_doctor_result(tool_doctor_inventory_json([], $casks)),
        tool_doctor_result("docker\nfont-hack\n"),
        tool_doctor_result("/Users/mini/.vite-plus/bin/vp\n"),
        tool_doctor_result('[{"name":"left-pad","version":"1.0.0"}]'),
    ]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $report = tool_doctor_probe(tool_doctor_installed_inspector(), app(SharedInstalledPackageInventory::class))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'macos', 'arm64', true)));
    $queries = DB::getQueryLog();

    expect($report->checked)->toBe(1)
        ->and($report->status)->toBe(DoctorFamilyStatus::Healthy)
        ->and(array_map(static fn ($issue): array => [$issue->code, $issue->resourceId, $issue->resourceName, $issue->expected, $issue->observed], $report->issues))
        ->toBe([
            ['tool.package_unregistered', null, 'jq', 'brew', 'version=1.7.1;dependency=no;adoption=supported;block=none'],
            ['tool.package_unregistered', null, 'docker', 'brew-cask', 'version=4.39.0;dependency=no;adoption=unsupported;block=authorization_required'],
            ['tool.package_unregistered', null, 'font-hack', 'brew-cask', 'version=2.0.0;dependency=no;adoption=supported;block=none'],
            ['tool.package_unregistered', null, 'left-pad', 'vp', 'version=1.0.0;dependency=no;adoption=supported;block=none'],
        ])
        ->and(Tool::query()->count())->toBe($toolsBefore)
        ->and($registered->fresh()->installed_version)->toBe('0.0.1');
    foreach ($queries as $query) {
        expect(strtolower((string) $query['query']))->not->toMatch('/\b(insert|update|delete|replace)\b/');
    }
    tool_doctor_assert_read_only($ssh);
    expect(json_encode($report))->not->toContain($sha)->not->toContain('/opt/homebrew')->not->toContain('/Users/mini');
});

it('reports a timed out inventory command as unverifiable without writing tools', function (): void {
    $node = tool_probe_linux_node();
    $toolsBefore = Tool::query()->count();
    $managersBefore = ToolManagerRecord::query()->count();
    $ssh = new class implements SshExecutor
    {
        public int $calls = 0;

        public ?float $commandTimeout = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;
            $this->commandTimeout = $connection->commandTimeout;
            $process = new Process(['true']);
            $process->setTimeout(30);

            throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
        }
    };
    app()->instance(SshExecutor::class, $ssh);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $report = tool_doctor_probe(tool_doctor_unused_inspector(), app(SharedInstalledPackageInventory::class))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)->toBe(0)
        ->and($report->status)->toBe(DoctorFamilyStatus::Unverifiable)
        ->and($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('tool.inspection_failed')
        ->and($report->issues[0]->kind)->toBe(DoctorIssueKind::Unverifiable)
        ->and($report->issues[0]->resourceId)->toBeNull()
        ->and($report->issues[0]->resourceName)->toBeNull()
        ->and($ssh->calls)->toBe(1)
        ->and($ssh->commandTimeout)->toBe(30.0)
        ->and(Tool::query()->count())->toBe($toolsBefore)
        ->and(ToolManagerRecord::query()->count())->toBe($managersBefore);
    foreach (DB::getQueryLog() as $query) {
        expect(strtolower((string) $query['query']))->not->toMatch('/\b(insert|update|delete|replace)\b/');
    }
});

function tool_doctor_unused_inspector(): ToolInspector
{
    return new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            throw new RuntimeException('No registered tool is inspected.');
        }
    };
}

function tool_doctor_installed_inspector(): ToolInspector
{
    return new class implements ToolInspector
    {
        use InspectsToolsIndividually;

        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, '1.2.3');
        }
    };
}

/** @param list<ToolInventoryScan> $scans */
function tool_doctor_scans(array $scans): InstalledPackageInventory
{
    return new class($scans) implements InstalledPackageInventory
    {
        /** @param list<ToolInventoryScan> $scans */
        public function __construct(private array $scans) {}

        public function inspect(Node $node): array
        {
            return $this->scans;
        }
    };
}

function tool_doctor_scan(ToolManagerName $manager, ToolInventoryScanState $state, array $packages = []): ToolInventoryScan
{
    return new ToolInventoryScan($manager, $state, $packages);
}

function tool_doctor_package(
    ToolManagerName $manager,
    string $package,
    ?string $version,
    bool $dependency = false,
    string $adoption = ToolInventoryPackage::SUPPORTED,
    ?string $block = null,
    bool $registered = false,
    ?int $toolId = null,
): ToolInventoryPackage {
    $kind = match ($manager) {
        ToolManagerName::Brew => ToolInventoryPackageKind::Formula,
        ToolManagerName::BrewCask => ToolInventoryPackageKind::Cask,
        ToolManagerName::Vp => ToolInventoryPackageKind::Global,
        default => throw new InvalidArgumentException($manager->value),
    };

    return new ToolInventoryPackage(
        manager: $manager,
        package: $package,
        packageKind: $kind,
        installedVersion: $version,
        dependency: $dependency,
        registered: $registered,
        toolId: $registered ? $toolId : null,
        adoption: $adoption,
        adoptionBlock: $block,
    );
}

/** @param list<CommandResult> $results */
function tool_doctor_bind_inventory(array $results): ToolManagerFakeSshExecutor
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    app()->instance(SshExecutor::class, $ssh);

    return $ssh;
}

function tool_doctor_result(string $stdout = '', int $exitCode = 0, bool $truncated = false): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 10, $truncated);
}

function tool_doctor_assert_read_only(ToolManagerFakeSshExecutor $ssh): void
{
    expect(json_encode($ssh->arguments()))
        ->not->toContain('apt-get')
        ->not->toContain('git clone')
        ->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE');

    foreach ($ssh->arguments() as $arguments) {
        expect($arguments)->not->toContain('install')
            ->not->toContain('uninstall')
            ->not->toContain('upgrade')
            ->not->toContain('autoremove')
            ->not->toContain('sudo');
    }
}

/**
 * @param  list<array<string, mixed>>  $formulae
 * @param  list<array<string, mixed>>  $casks
 */
function tool_doctor_inventory_json(array $formulae = [], array $casks = []): string
{
    return json_encode(['formulae' => $formulae, 'casks' => $casks], JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function tool_doctor_formula(string $name, string $version, string $sha): array
{
    return [
        'name' => $name,
        'full_name' => $name,
        'tap' => 'homebrew/core',
        'versions' => ['stable' => '9.9.9', 'bottle' => true],
        'bottle' => ['stable' => ['files' => [
            'arm64_golden_gate' => [
                'url' => "https://ghcr.io/v2/homebrew/core/{$name}/blobs/sha256:{$sha}",
                'sha256' => $sha,
            ],
        ]]],
        'disabled' => false,
        'installed' => [[
            'version' => $version,
            'installed_on_request' => true,
            'installed_as_dependency' => false,
        ]],
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $artifacts
 * @return array<string, mixed>
 */
function tool_doctor_cask(string $token, string $version, ?array $artifacts = null): array
{
    return [
        'token' => $token,
        'full_token' => $token,
        'tap' => 'homebrew/cask',
        'url' => "https://example.com/{$token}.zip",
        'version' => $version,
        'installed' => $version,
        'sha256' => str_repeat('a', 64),
        'disabled' => false,
        'variations' => new stdClass,
        'artifacts' => $artifacts ?? [[
            'font' => ["{$token}.ttf"],
            'target' => '/$HOME/Library/Fonts/'.$token.'.ttf',
        ]],
    ];
}
