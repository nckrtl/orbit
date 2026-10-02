<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(VpInventoryInspector::class, function (): void {
    it('enumerates real Vite+ global roots for the enrolled macOS scope without writing', function (): void {
        $node = vp_inventory_node();
        $other = vp_inventory_node('vp-inventory-other', '10.8.0.48');
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $composer = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer->value,
            'status' => LifecycleStatus::Active,
        ]);
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $otherVp = $other->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $codex = vp_inventory_tool($node, $vp, '@openai/codex');
        vp_inventory_tool($node, $composer, '@openai/codex');
        vp_inventory_tool($node, $brew, 'pnpm');
        vp_inventory_tool($other, $otherVp, '@openai/codex');
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tools/vp-list-g.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray();
        foreach ($decoded as $index => $entry) {
            if (($entry['name'] ?? null) === 'typescript') {
                $decoded[$index]['version'] = 'latest';
            }
        }
        $list = json_encode($decoded, JSON_THROW_ON_ERROR);
        $binary = '/Users/mini/.vite-plus/bin/vp';
        [$inspector, $ssh] = vp_inventory_inspector([
            vp_inventory_result($binary."\n"),
            vp_inventory_result($list),
        ]);
        $toolsBefore = Tool::query()->orderBy('id')->get(['id', 'package', 'installed_version', 'status'])->toArray();
        $managersBefore = ToolManagerRecord::query()->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $scan = $inspector->inspect($node);
        $queries = DB::getQueryLog();

        expect($scan->manager)->toBe(ToolManagerName::Vp)
            ->and($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and(array_map(static fn (ToolInventoryPackage $package): string => $package->package, $scan->packages))
            ->toBe(['@anthropic-ai/claude-code', '@openai/codex', 'pnpm', 'typescript'])
            ->and($scan->packages[0]->installedVersion)->toBe('1.0.24')
            ->and($scan->packages[0]->packageKind)->toBe(ToolInventoryPackageKind::Global)
            ->and($scan->packages[0]->dependency)->toBeFalse()
            ->and($scan->packages[0]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scan->packages[0]->adoptionBlock)->toBeNull()
            ->and($scan->packages[0]->registered)->toBeFalse()
            ->and($scan->packages[0]->toolId)->toBeNull()
            ->and($scan->packages[1]->registered)->toBeTrue()
            ->and($scan->packages[1]->toolId)->toBe($codex->id)
            ->and($scan->packages[1]->installedVersion)->toBe('0.150.0')
            ->and($scan->packages[2]->package)->toBe('pnpm')
            ->and($scan->packages[2]->adoption)->toBe(ToolInventoryPackage::UNSUPPORTED)
            ->and($scan->packages[2]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_PROTECTED)
            ->and($scan->packages[2]->registered)->toBeFalse()
            ->and($scan->packages[3]->installedVersion)->toBeNull()
            ->and($scan->packages[3]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_VERSION)
            ->and($ssh->arguments())->toBe([
                ['/bin/bash', '-su', '--', 'mini'],
                ['env', 'VP_HOME=/Users/mini/.vite-plus', $binary, 'list', '-g', '--json'],
            ])
            ->and($ssh->connections[0]->user)->toBe('mini')
            ->and($ssh->connections[0]->host)->toBe('10.8.0.47')
            ->and($ssh->commands[0]->maxOutputBytes)->toBe(4_096)
            ->and($ssh->commands[1]->maxOutputBytes)->toBe(VpInventoryInspector::MAX_INVENTORY_BYTES)
            ->and(ToolManagerRecord::query()->count())->toBe($managersBefore)
            ->and(Tool::query()->orderBy('id')->get(['id', 'package', 'installed_version', 'status'])->toArray())
            ->toBe($toolsBefore);

        vp_inventory_assert_read_only($ssh);
        foreach ($queries as $query) {
            expect(strtolower((string) $query['query']))->not->toMatch('/\b(insert|update|delete|replace)\b/');
        }
    });

    it('reads the enrolled Linux Vite+ scope without bootstrapping a manager', function (string $binary): void {
        $list = (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tools/vp-list-g.json');
        [$inspector, $ssh] = vp_inventory_inspector([
            vp_inventory_result($binary."\n"),
            vp_inventory_result($list),
        ]);

        $scan = $inspector->inspect(vp_inventory_node(platform: 'linux', user: 'orbit'));

        expect($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scan->packages[1]->package)->toBe('@openai/codex')
            ->and($scan->packages[1]->installedVersion)->toBe('0.150.0')
            ->and($ssh->arguments())->toBe([
                ['/bin/bash', '-seu', '--', 'orbit'],
                ['env', 'VP_HOME='.dirname($binary, 2), $binary, 'list', '-g', '--json'],
            ])
            ->and($ssh->commands[0]->input)->toContain('/usr/bin/getent')
            ->and($ssh->commands[0]->input)->toContain('/opt/orbit/vite-plus')
            ->and($ssh->commands[0]->input)->toContain('/.vite-plus')
            ->and($ssh->commands[0]->input)->toContain('/.local/share/vite-plus')
            ->and($ssh->commands[0]->input)->not->toContain('dscacheutil')
            ->and($ssh->commands[0]->input)->not->toContain('curl')
            ->and($ssh->commands[0]->input)->not->toContain('sudo');
        vp_inventory_assert_read_only($ssh);
        vp_inventory_assert_bash($ssh->commands[0]->input);
    })->with([
        'orbit store' => ['/opt/orbit/vite-plus/bin/vp'],
        'user store' => ['/home/orbit/.local/share/vite-plus/bin/vp'],
    ]);

    it('distinguishes a missing scope from a conflicting or failed one', function (
        CommandResult $probe,
        ToolInventoryScanState $state,
    ): void {
        [$inspector, $ssh] = vp_inventory_inspector([$probe]);

        $scan = $inspector->inspect(vp_inventory_node());

        expect($scan->scanState)->toBe($state)
            ->and($scan->packages)->toBe([])
            ->and($ssh->arguments())->toHaveCount(1);
        vp_inventory_assert_read_only($ssh);
    })->with([
        'absent' => [vp_inventory_result('secret path', exitCode: 42, stderr: 'secret stat'), ToolInventoryScanState::Absent],
        'conflicting' => [vp_inventory_result('secret path', exitCode: 43, stderr: 'secret owner'), ToolInventoryScanState::Conflicting],
        'probe failed' => [vp_inventory_result('secret path', exitCode: 1, stderr: 'secret stat'), ToolInventoryScanState::Incomplete],
        'malformed path' => [vp_inventory_result("/tmp/vp\n"), ToolInventoryScanState::Incomplete],
    ]);

    it('lists a legacy npm name as unsupported without hiding the other globals', function (): void {
        $node = vp_inventory_node();
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $codex = vp_inventory_tool($node, $vp, '@openai/codex', '0.150.0');
        $legacy = vp_inventory_tool($node, $vp, 'JSONStream', '1.3.5');
        [$inspector] = vp_inventory_inspector([
            vp_inventory_result("/Users/mini/.vite-plus/bin/vp\n"),
            vp_inventory_result('[{"name":"JSONStream","version":"1.3.5","bins":["JSONStream"]},{"name":"@openai/codex","version":"0.150.0"}]'),
        ]);

        $scan = $inspector->inspect($node);

        expect($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and(array_map(static fn (ToolInventoryPackage $package): string => $package->package, $scan->packages))
            ->toBe(['@openai/codex', 'JSONStream'])
            ->and($scan->packages[0]->registered)->toBeTrue()
            ->and($scan->packages[0]->toolId)->toBe($codex->id)
            ->and($scan->packages[0]->adoption)->toBe(ToolInventoryPackage::SUPPORTED)
            ->and($scan->packages[1]->adoption)->toBe(ToolInventoryPackage::UNSUPPORTED)
            ->and($scan->packages[1]->adoptionBlock)->toBe(ToolInventoryPackage::BLOCK_SOURCE)
            ->and($scan->packages[1]->registered)->toBeFalse()
            ->and($scan->packages[1]->toolId)->toBeNull()
            ->and($scan->packages[1]->installedVersion)->toBe('1.3.5')
            ->and($scan->packages[1]->dependency)->toBeFalse()
            ->and($scan->packages[1]->packageKind)->toBe(ToolInventoryPackageKind::Global)
            ->and($legacy->refresh()->package)->toBe('JSONStream');
    });

    it('reads the Orbit Linux Vite+ store when a user store is also present', function (): void {
        $ssh = new ToolManagerFakeSshExecutor([
            vp_inventory_result("/opt/orbit/vite-plus/bin/vp\n"),
        ]);
        $runner = new RemoteToolCommandRunner(
            ssh: $ssh,
            keys: vp_inventory_keys(),
            knownHosts: vp_inventory_known_hosts(),
        );
        $manager = new VpToolManager($runner, new SemverVersionNormalizer);

        $binary = $manager->existingBinary(vp_inventory_node(platform: 'linux', user: 'orbit', saved: false));

        expect($binary)->toBe('/opt/orbit/vite-plus/bin/vp');
        [$status, $stdout, $stderr, $expected] = vp_inventory_run_linux_scope($ssh->commands[0]->input ?? '');
        expect($status)->toBe(0, $stderr)
            ->and($stdout)->toBe($expected."\n")
            ->and($expected)->toEndWith('/vite-plus/bin/vp')
            ->and($expected)->not->toContain('/.vite-plus');
    });

    it('keeps an unsupported platform offline without a command', function (): void {
        [$inspector, $ssh] = vp_inventory_inspector([]);

        $scan = $inspector->inspect(vp_inventory_node(platform: 'freebsd', saved: false));

        expect($scan->scanState)->toBe(ToolInventoryScanState::Unsupported)
            ->and($scan->packages)->toBe([])
            ->and($ssh->commands)->toBe([]);
    });

    it('treats an empty global list as a complete inventory and keeps unregistered rows out', function (): void {
        $node = vp_inventory_node();
        $brew = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        vp_inventory_tool($node, $brew, '@openai/codex');
        [$inspector] = vp_inventory_inspector([
            vp_inventory_result("/Users/mini/.local/share/vite-plus/bin/vp\n"),
            vp_inventory_result('[]'),
        ]);

        $scan = $inspector->inspect($node);

        expect($scan->scanState)->toBe(ToolInventoryScanState::Complete)
            ->and($scan->packages)->toBe([])
            ->and(Tool::query()->count())->toBe(1);
    });

    it('marks malformed, truncated, and oversized lists incomplete without creating tools', function (CommandResult $list): void {
        $node = vp_inventory_node();
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp->value,
            'status' => LifecycleStatus::Active,
        ]);
        $tool = vp_inventory_tool($node, $vp, '@openai/codex', '0.150.0');
        [$inspector] = vp_inventory_inspector([
            vp_inventory_result("/Users/mini/.vite-plus/bin/vp\n"),
            $list,
        ]);

        $scan = $inspector->inspect($node);

        expect($scan->scanState)->toBe(ToolInventoryScanState::Incomplete)
            ->and($scan->packages)->toBe([])
            ->and($tool->refresh()->installed_version)->toBe('0.150.0')
            ->and($tool->status)->toBe(ToolStatus::Installed);
    })->with([
        'object document' => [vp_inventory_result('{"name":"@openai/codex","version":"0.150.0"}')],
        'trailing value' => [vp_inventory_result('[][]')],
        'truncated output' => [vp_inventory_result('[]', truncated: true)],
        'failed command' => [vp_inventory_result('secret failure', exitCode: 1, stderr: 'secret stderr')],
        'duplicate name' => [vp_inventory_result('[{"name":"typescript","version":"5.8.3"},{"name":"typescript","version":"5.8.2"}]')],
        'duplicate legacy name' => [vp_inventory_result('[{"name":"JSONStream","version":"1.3.5"},{"name":"JSONStream","version":"1.3.4"}]')],
        'empty name' => [vp_inventory_result('[{"name":"","version":"1.0.0"}]')],
        'non-string name' => [vp_inventory_result('[{"name":1,"version":"1.0.0"}]')],
        'control character in name' => [vp_inventory_result('[{"name":"type\\nscript","version":"1.0.0"}]')],
        'overlong name' => [vp_inventory_result('[{"name":"'.str_repeat('a', 256).'","version":"1.0.0"}]')],
        'numeric version' => [vp_inventory_result('[{"name":"typescript","version":5}]')],
        'control character' => [vp_inventory_result('[{"name":"typescript","version":"5.8.3\\nsecret"}]')],
        'oversized document' => [vp_inventory_result(str_repeat('[', VpInventoryInspector::MAX_INVENTORY_BYTES).']')],
        'deep document' => [vp_inventory_result(str_repeat('[', 70).'1'.str_repeat(']', 70))],
    ]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{VpInventoryInspector, ToolManagerFakeSshExecutor}
 */
function vp_inventory_inspector(array $results): array
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    $runner = new RemoteToolCommandRunner(
        ssh: $ssh,
        keys: vp_inventory_keys(),
        knownHosts: vp_inventory_known_hosts(),
    );

    return [
        new VpInventoryInspector(
            commands: $runner,
            vp: new VpToolManager($runner, new SemverVersionNormalizer),
            versions: new SemverVersionNormalizer,
        ),
        $ssh,
    ];
}

function vp_inventory_node(
    string $name = 'vp-inventory-node',
    string $address = '10.8.0.47',
    string $platform = 'macos',
    string $user = 'mini',
    bool $saved = true,
): Node {
    $attributes = [
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => 'arm64',
        'public_ssh_host' => '127.0.0.1',
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ];

    if (! $saved) {
        return new Node($attributes);
    }

    return Node::query()->create($attributes);
}

function vp_inventory_tool(
    Node $node,
    ToolManagerRecord $manager,
    string $package,
    string $version = '1.0.0',
): Tool {
    return $node->tools()->create([
        'tool_manager_id' => $manager->id,
        'package' => $package,
        'status' => ToolStatus::Installed,
        'installed_version' => $version,
    ]);
}

function vp_inventory_result(
    string $stdout = '',
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 10, $truncated);
}

function vp_inventory_assert_read_only(ToolManagerFakeSshExecutor $ssh): void
{
    expect(json_encode($ssh->arguments()))
        ->not->toContain('curl')
        ->not->toContain('sudo')
        ->not->toContain('apt-get')
        ->not->toContain('brew');

    foreach ($ssh->arguments() as $arguments) {
        expect($arguments)->not->toContain('install')
            ->not->toContain('update')
            ->not->toContain('remove')
            ->not->toContain('upgrade');
    }

    foreach ($ssh->commands as $command) {
        expect($command->input ?? '')
            ->not->toContain('curl')
            ->not->toContain('sudo')
            ->not->toContain('apt-get')
            ->not->toContain('install')
            ->not->toContain('git clone');
    }
}

function vp_inventory_assert_bash(?string $program): void
{
    expect($program)->toBeString();
    $script = tempnam(sys_get_temp_dir(), 'orbit-vp-inventory-');
    file_put_contents($script, $program);

    try {
        exec('bash -n '.escapeshellarg((string) $script).' 2>&1', $output, $status);
        expect($status)->toBe(0, implode("\n", $output));
    } finally {
        unlink((string) $script);
    }
}

/**
 * Runs the Linux scope script against an Orbit store and a second user store.
 *
 * @return array{int, string, string, string}
 */
function vp_inventory_run_linux_scope(string $program): array
{
    $root = sys_get_temp_dir().'/orbit-vp-scope-'.bin2hex(random_bytes(4));
    $orbit = $root.'/orbit';
    $home = $root.'/home';
    $account = trim((string) shell_exec('id -un'));
    mkdir($orbit.'/vite-plus/bin', 0755, true);
    mkdir($home.'/.vite-plus/bin', 0755, true);
    file_put_contents($orbit.'/vite-plus/bin/vp', "#!/bin/sh\n");
    file_put_contents($home.'/.vite-plus/bin/vp', "#!/bin/sh\n");
    chmod($orbit.'/vite-plus/bin/vp', 0755);
    chmod($home.'/.vite-plus/bin/vp', 0755);
    file_put_contents($root.'/getent', "#!/bin/sh\nprintf '%s\\n' 'orbit:x:1:1::{$home}:/bin/sh'\n");
    file_put_contents($root.'/stat', <<<SH
        #!/bin/sh
        path=
        for path do
            :
        done
        if [ "\$path" = "{$orbit}" ]; then
            printf 'root:root\n'
            exit 0
        fi
        exec /usr/bin/stat "\$@"
        SH);
    chmod($root.'/getent', 0755);
    chmod($root.'/stat', 0755);
    $program = str_replace(
        ['/usr/bin/getent', '/usr/bin/stat', '/opt/orbit'],
        [$root.'/getent', $root.'/stat', $orbit],
        $program,
    );
    $pipes = [];
    $process = proc_open(
        ['bash', '-seu', '--', $account],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    try {
        fwrite($pipes[0], $program);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : '', $orbit.'/vite-plus/bin/vp'];
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
}

function vp_inventory_keys(): SshKeyProvider
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

function vp_inventory_known_hosts(): KnownHostsStore
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
