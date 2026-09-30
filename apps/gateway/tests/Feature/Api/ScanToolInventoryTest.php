<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ToolManagerFakeSshExecutor;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-26 12:00:00', 'UTC'));
    $this->gateway = scan_node('scan-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    app()->instance(SshExecutor::class, new ToolManagerFakeSshExecutor([]));
    app()->instance(ToolManagerMaterializer::class, new class implements ToolManagerMaterializer
    {
        public function converge(Node $node, ToolManagerName ...$managerNames): void
        {
            throw new RuntimeException('The scan materialized a manager.');
        }

        public function convergeWithFailureHandler(Node $node, Closure $onFailure, ToolManagerName ...$managerNames): void
        {
            throw new RuntimeException('The scan materialized a manager.');
        }
    });
    app()->instance(ToolManagerScopeLock::class, new class implements ToolManagerScopeLock
    {
        public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed
        {
            throw new RuntimeException('The scan locked a manager scope.');
        }
    });
    app()->instance(ToolOperationLock::class, new class implements ToolOperationLock
    {
        public function run(
            int $nodeId,
            ToolManagerName $manager,
            string $package,
            ToolOperation $operation,
            ?string $versionConstraint,
            Closure $callback,
        ): mixed {
            throw new RuntimeException('The scan locked a tool operation.');
        }
    });
});

describe('tool inventory authorization and input', function (): void {
    it('denies a peer without access and does not scan', function (): void {
        $node = scan_node('scan-target', '10.44.0.3', 'macos', 'mini');
        $consumer = scan_node('scan-consumer', '10.44.0.9');
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
            ->getJson('/api/v1/tool-inventory?node_id='.$node->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');

        expect($ssh->commands)->toBe([])->and(Tool::query()->count())->toBe(0);
    });

    it('rejects an empty body field other than the strict node id', function (string $uri, ?string $body = null): void {
        $node = scan_node('scan-target', '10.44.0.3', 'macos', 'mini');
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);
        $response = $this->call(
            'GET',
            str_replace('{id}', (string) $node->id, $uri),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body,
        );

        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($response->getContent())->not->toContain('raw-caller-sentinel')
            ->and($ssh->commands)->toBe([]);
    })->with([
        'absent' => ['/api/v1/tool-inventory'],
        'zero' => ['/api/v1/tool-inventory?node_id=0'],
        'negative' => ['/api/v1/tool-inventory?node_id=-1'],
        'leading zero' => ['/api/v1/tool-inventory?node_id=01'],
        'fraction' => ['/api/v1/tool-inventory?node_id=1.5'],
        'word' => ['/api/v1/tool-inventory?node_id=invalid'],
        'duplicate' => ['/api/v1/tool-inventory?node_id={id}&node_id={id}'],
        'extra query' => ['/api/v1/tool-inventory?node_id={id}&token=raw-caller-sentinel'],
        'array query' => ['/api/v1/tool-inventory?node_id[]=1'],
        'body' => ['/api/v1/tool-inventory?node_id={id}', '{"token":"raw-caller-sentinel"}'],
    ]);

    it('returns 404 for a missing node without scanning', function (): void {
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $this->getJson('/api/v1/tool-inventory?node_id=999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'http.404');

        expect($ssh->commands)->toBe([]);
    });

    it('rejects an inactive or unmanaged node before any command', function (string $field, mixed $value): void {
        $node = scan_node('scan-target', '10.44.0.3', 'macos', 'mini');
        $node->update([$field => $value]);
        $tool = scan_tool($node, ToolManagerName::Vp, '@openai/codex');
        $ssh = new ToolManagerFakeSshExecutor([]);
        app()->instance(SshExecutor::class, $ssh);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json']);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', $field === 'status' ? 'tool.node_inactive' : 'tool.node_unmanaged')
            ->assertJsonPath('error.details.step', 'scan')
            ->assertJsonPath('error.details.outcome', 'manager_failed');
        expect(array_keys($response->json('error.details')))->toBe(['step', 'outcome'])
            ->and($ssh->commands)->toBe([])
            ->and($tool->refresh()->installed_version)->toBe('0.150.0');
    })->with([
        'inactive' => ['status', LifecycleStatus::Failed],
        'no fingerprint' => ['ssh_host_fingerprint', null],
        'no wireguard' => ['wireguard_ip', null],
    ]);
});

describe('tool inventory scans', function (): void {
    it('returns a complete empty inventory when no tools are registered', function (): void {
        $node = scan_node('scan-mini', '10.44.0.3', 'macos', 'mini');
        $ssh = scan_bind(scan_mac_results(
            scan_inventory_json(),
            '',
            scan_inventory_json(),
            '',
            "/Users/mini/.vite-plus/bin/vp\n",
            '[]',
        ));

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        expect($response->json('data.managers'))->toHaveCount(3)
            ->and($response->json('data.observed_at'))->toBe('2026-04-26T12:00:00+00:00')
            ->and($response->json('data.managers.0.scan_state'))->toBe('complete')
            ->and($response->json('data.managers.0.packages'))->toBe([])
            ->and($response->json('data.managers.1.scan_state'))->toBe('complete')
            ->and($response->json('data.managers.2.manager'))->toBe('vp')
            ->and($response->json('data.managers.2.packages'))->toBe([])
            ->and(Tool::query()->count())->toBe(0)
            ->and(ToolManagerRecord::query()->count())->toBe(0);
        scan_assert_read_only($ssh);
        record_fixture($response, 'tools/tool-scan/empty', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('reports scoped Vite+ roots, ownership, and Homebrew facts without host paths', function (): void {
        $node = scan_node('scan-mini', '10.44.0.3', 'macos', 'mini');
        $codex = scan_tool($node, ToolManagerName::Vp, '@openai/codex');
        $ripgrep = scan_tool($node, ToolManagerName::Brew, 'ripgrep');
        $formulae = [
            scan_formula('openssl@3', requested: false, asDependency: true),
            scan_formula('ripgrep', '14.1.1'),
            scan_formula('wireguard-tools'),
        ];
        $casks = [
            scan_cask('docker', '4.39.0', [[
                'app' => ['Docker.app'],
                'target' => '/Applications/Docker.app',
            ]]),
            scan_cask('font-hack', '2.0.0'),
        ];
        $vp = json_encode([
            [
                'name' => '@openai/codex',
                'version' => '0.150.0',
                'bins' => ['codex'],
                'installedAt' => '2026-03-18T09:41:12Z',
                'platform' => ['node' => '22.21.0', 'npm' => '10.9.2'],
            ],
            [
                'name' => 'pnpm',
                'version' => '10.15.1',
                'bins' => ['pnpm'],
                'manager' => 'npm',
            ],
            [
                'name' => 'typescript',
                'version' => 'latest',
                'bins' => ['tsc'],
            ],
        ], JSON_THROW_ON_ERROR);
        $ssh = scan_bind(scan_mac_results(
            scan_inventory_json($formulae),
            "openssl@3\nripgrep\nwireguard-tools\n",
            scan_inventory_json([], $casks),
            "docker\nfont-hack\n",
            "/Users/mini/.vite-plus/bin/vp\n",
            $vp,
        ));
        $before = Tool::query()->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        $body = $response->getContent();
        expect($response->json('data.node_id'))->toBe($node->id)
            ->and($response->json('data.observed_at'))->toBe('2026-04-26T12:00:00+00:00')
            ->and(array_column($response->json('data.managers'), 'manager'))->toBe(['brew', 'brew-cask', 'vp'])
            ->and($response->json('data.managers.0.packages.1.package'))->toBe('ripgrep')
            ->and($response->json('data.managers.0.packages.1.registered'))->toBeTrue()
            ->and($response->json('data.managers.0.packages.1.tool_id'))->toBe($ripgrep->id)
            ->and($response->json('data.managers.0.packages.0.adoption_block'))->toBe('dependency')
            ->and($response->json('data.managers.0.packages.2.adoption_block'))->toBe('protected')
            ->and($response->json('data.managers.1.packages.0.package'))->toBe('docker')
            ->and($response->json('data.managers.1.packages.0.package_kind'))->toBe('cask')
            ->and($response->json('data.managers.1.packages.1.adoption'))->toBe('supported')
            ->and($response->json('data.managers.2.packages.0.package'))->toBe('@openai/codex')
            ->and($response->json('data.managers.2.packages.0.package_kind'))->toBe('global')
            ->and($response->json('data.managers.2.packages.0.tool_id'))->toBe($codex->id)
            ->and($response->json('data.managers.2.packages.0.dependency'))->toBeFalse()
            ->and($response->json('data.managers.2.packages.1.adoption_block'))->toBe('protected')
            ->and($response->json('data.managers.2.packages.2.adoption_block'))->toBe('version_unreadable')
            ->and($response->json('data.managers.2.packages.2.installed_version'))->toBeNull()
            ->and($body)->not->toContain('/Users')
            ->and($body)->not->toContain('VP_HOME')
            ->and($body)->not->toContain('installedAt')
            ->and($body)->not->toContain('22.21.0')
            ->and($body)->not->toContain('ghcr.io')
            ->and($body)->not->toContain('example.com')
            ->and(Tool::query()->count())->toBe($before)
            ->and($codex->refresh()->installed_version)->toBe('0.150.0');
        scan_assert_read_only($ssh);
        expect($ssh->arguments()[7])->toBe([
            'env',
            'VP_HOME=/Users/mini/.vite-plus',
            '/Users/mini/.vite-plus/bin/vp',
            'list',
            '-g',
            '--json',
        ])->and(json_encode($ssh->arguments()))->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE');
        $writes = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => preg_match('/\b(insert|update|delete|replace)\b/i', $query['query']) === 1)
            ->pluck('query')
            ->implode("\n");
        expect($writes)->not->toMatch('/tool_managers|\btools\b/i');
        record_fixture($response, 'tools/tool-scan/discovered', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('keeps a successful manager when another read fails or the scope conflicts', function (): void {
        $node = scan_node('scan-mini', '10.44.0.3', 'macos', 'mini');
        $tool = scan_tool($node, ToolManagerName::Vp, '@openai/codex');
        $formulae = [scan_formula('ripgrep', '14.1.1')];
        $ssh = scan_bind([
            scan_result("/opt/homebrew\n"),
            scan_result("27.0.1\n"),
            scan_result(scan_inventory_json($formulae)),
            scan_result("ripgrep\n"),
            scan_result('secret cask output', exitCode: 1, stderr: 'secret cask'),
            scan_result('secret scope', exitCode: 43, stderr: 'secret owner'),
        ]);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        expect($response->json('data.managers.0.scan_state'))->toBe('complete')
            ->and($response->json('data.managers.0.packages.0.package'))->toBe('ripgrep')
            ->and($response->json('data.managers.1.scan_state'))->toBe('incomplete')
            ->and($response->json('data.managers.1.packages'))->toBe([])
            ->and($response->json('data.managers.2.scan_state'))->toBe('conflicting')
            ->and($response->json('data.managers.2.packages'))->toBe([])
            ->and($response->json('data.observed_at'))->toBe('2026-04-26T12:00:00+00:00')
            ->and($response->getContent())->not->toContain('secret')
            ->and($tool->refresh()->status)->toBe(ToolStatus::Installed);
        scan_assert_read_only($ssh);
        record_fixture($response, 'tools/tool-scan/partial', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('distinguishes an unsupported manager from an absent scope on Linux', function (): void {
        $node = scan_node('scan-linux', '10.44.0.4', 'linux', 'orbit', 'x86_64');
        $formulae = [scan_formula('ripgrep', '14.1.1', tag: 'x86_64_linux')];
        $ssh = scan_bind([
            scan_result("/home/linuxbrew/.linuxbrew\n"),
            scan_result("x86_64\n"),
            scan_result(scan_inventory_json($formulae)),
            scan_result("ripgrep\n"),
            scan_result('secret path', exitCode: 42, stderr: 'secret absent'),
        ]);

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        expect(array_column($response->json('data.managers'), 'scan_state'))
            ->toBe(['complete', 'unsupported', 'absent'])
            ->and($response->json('data.managers.1.packages'))->toBe([])
            ->and($response->json('data.managers.2.packages'))->toBe([])
            ->and($response->getContent())->not->toContain('/home/linuxbrew')
            ->and($response->getContent())->not->toContain('secret')
            ->and(Tool::query()->count())->toBe(0);
        scan_assert_read_only($ssh);
        expect($ssh->commands)->toHaveCount(5)
            ->and($ssh->arguments()[0])->toBe(['/bin/bash', '-seu', '--', 'orbit'])
            ->and($ssh->commands[4]->input)->toContain('/usr/bin/getent')
            ->and($ssh->commands[4]->input)->not->toContain('curl');
        record_fixture($response, 'tools/tool-scan/linux', 'Orbit\\Sdk\\Requests\\Tools\\ScanToolInventoryRequest', 'GET /api/v1/tool-inventory');
    });

    it('marks a malformed or oversized Vite+ list incomplete and keeps the other managers', function (string $vpList): void {
        $node = scan_node('scan-mini', '10.44.0.3', 'macos', 'mini');
        $tool = scan_tool($node, ToolManagerName::Vp, '@openai/codex');
        $formulae = [scan_formula('ripgrep', '14.1.1')];
        $casks = [scan_cask('font-hack', '2.0.0')];
        scan_bind(scan_mac_results(
            scan_inventory_json($formulae),
            "ripgrep\n",
            scan_inventory_json([], $casks),
            "font-hack\n",
            "/Users/mini/.vite-plus/bin/vp\n",
            $vpList,
        ));

        $response = $this->get('/api/v1/tool-inventory?node_id='.$node->id, ['Accept' => 'application/json'])->assertOk();

        expect($response->json('data.managers.0.scan_state'))->toBe('complete')
            ->and($response->json('data.managers.1.scan_state'))->toBe('complete')
            ->and($response->json('data.managers.2.scan_state'))->toBe('incomplete')
            ->and($response->json('data.managers.2.packages'))->toBe([])
            ->and($tool->refresh()->installed_version)->toBe('0.150.0')
            ->and($response->getContent())->not->toContain('secret');
    })->with([
        'malformed' => ['[{"name":"@openai/codex","version":0.150}]'],
        'oversized' => [str_repeat('[', 1_048_576).']'],
    ]);
});

function scan_node(
    string $name,
    string $address,
    string $platform = 'linux',
    string $user = 'orbit',
    string $architecture = 'arm64',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $architecture,
        'public_ssh_host' => '192.0.2.40',
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
}

function scan_tool(Node $node, ToolManagerName $manager, string $package): Tool
{
    $record = $node->toolManagers()->create([
        'name' => $manager->value,
        'status' => LifecycleStatus::Active,
    ]);

    return $node->tools()->create([
        'tool_manager_id' => $record->id,
        'package' => $package,
        'status' => ToolStatus::Installed,
        'installed_version' => '0.150.0',
    ]);
}

/**
 * @param  list<CommandResult>  $results
 */
function scan_bind(array $results): ToolManagerFakeSshExecutor
{
    $ssh = new ToolManagerFakeSshExecutor($results);
    app()->instance(SshExecutor::class, $ssh);

    return $ssh;
}

/**
 * @return list<CommandResult>
 */
function scan_mac_results(
    string $formulaJson,
    string $formulaNames,
    string $caskJson,
    string $caskNames,
    string $binary,
    string $vpList,
): array {
    return [
        scan_result("/opt/homebrew\n"),
        scan_result("27.0.1\n"),
        scan_result($formulaJson),
        scan_result($formulaNames),
        scan_result($caskJson),
        scan_result($caskNames),
        scan_result($binary),
        scan_result($vpList),
    ];
}

function scan_result(
    string $stdout = '',
    int $exitCode = 0,
    string $stderr = '',
    bool $truncated = false,
): CommandResult {
    return new CommandResult($exitCode, $stdout, $stderr, 10, $truncated);
}

/**
 * @param  list<array<string, mixed>>  $formulae
 * @param  list<array<string, mixed>>  $casks
 */
function scan_inventory_json(array $formulae = [], array $casks = []): string
{
    return json_encode([
        'formulae' => $formulae,
        'casks' => $casks,
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function scan_formula(
    string $name,
    string $version = '1.0.0',
    bool $requested = true,
    bool $asDependency = false,
    string $tag = 'arm64_golden_gate',
): array {
    $sha = str_repeat('ab', 32);

    return [
        'name' => $name,
        'full_name' => $name,
        'tap' => 'homebrew/core',
        'versions' => ['stable' => '9.9.9', 'bottle' => true],
        'bottle' => ['stable' => ['files' => [
            $tag => [
                'url' => "https://ghcr.io/v2/homebrew/core/{$name}/blobs/sha256:{$sha}",
                'sha256' => $sha,
            ],
        ]]],
        'disabled' => false,
        'installed' => [[
            'version' => $version,
            'installed_on_request' => $requested,
            'installed_as_dependency' => $asDependency,
        ]],
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $artifacts
 * @return array<string, mixed>
 */
function scan_cask(string $token, string $version, ?array $artifacts = null): array
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
            'target' => "/\$HOME/Library/Fonts/{$token}.ttf",
        ]],
    ];
}

function scan_assert_read_only(ToolManagerFakeSshExecutor $ssh): void
{
    expect(json_encode($ssh->arguments()))
        ->not->toContain('curl')
        ->not->toContain('sudo')
        ->not->toContain('apt-get')
        ->not->toContain('HOMEBREW_FORCE_API_AUTO_UPDATE');

    foreach ($ssh->arguments() as $arguments) {
        expect($arguments)->not->toContain('install')
            ->not->toContain('update')
            ->not->toContain('upgrade')
            ->not->toContain('uninstall')
            ->not->toContain('autoremove');
    }

    foreach ($ssh->commands as $command) {
        expect($command->input ?? '')
            ->not->toContain('curl')
            ->not->toContain('sudo')
            ->not->toContain('apt-get')
            ->not->toContain('install')
            ->not->toContain('git clone')
            ->not->toContain('checkout');
    }
}
