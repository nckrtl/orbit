<?php

declare(strict_types=1);

use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\ToolManagerFakeSshExecutor;

beforeEach(function (): void {
    $caller = doctor_fixture_node('doctor-caller', '10.44.0.2');
    $this->markAsGateway($caller);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
});

describe('doctor discovery fixtures', function (): void {
    it('records an informational-only healthy discovery', function (): void {
        $node = doctor_fixture_node('mini', '10.44.0.3', 'macos', 'mini');
        $sha = str_repeat('ab', 32);
        $ssh = doctor_fixture_bind(new NodeInspectionData(true, 'macos', 'arm64', true), [
            doctor_fixture_result("/opt/homebrew\n"),
            doctor_fixture_result("27.0.1\n"),
            doctor_fixture_result(doctor_fixture_json([
                doctor_fixture_formula('jq', '1.7.1', $sha),
                doctor_fixture_formula('ripgrep', '14.1.1', $sha),
            ])),
            doctor_fixture_result("jq\nripgrep\n"),
            doctor_fixture_result(doctor_fixture_json([], [
                doctor_fixture_cask('font-hack', '2.0.0'),
                doctor_fixture_cask('docker', '4.39.0', [[
                    'app' => ['Docker.app'],
                    'target' => '/Applications/Docker.app',
                ]]),
            ])),
            doctor_fixture_result("docker\nfont-hack\n"),
            doctor_fixture_result("/Users/mini/.vite-plus/bin/vp\n"),
            doctor_fixture_result('[{"name":"left-pad","version":"1.0.0"}]'),
        ]);
        $toolsBefore = Tool::query()->count();

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeTrue()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(0)
            ->and($response->json('data.nodes.0.families.0.status'))->toBe('healthy')
            ->and($response->json('data.summary.drift'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(0)
            ->and($response->json('data.summary.informational'))->toBe(5)
            ->and($response->json('data.nodes.0.families.0.issues.2.resource_name'))->toBe('docker')
            ->and($response->json('data.nodes.0.families.0.issues.2.observed'))->toContain('authorization_required')
            ->and(Tool::query()->count())->toBe($toolsBefore)
            ->and(ToolManagerRecord::query()->count())->toBe(0);
        doctor_fixture_assert_read_only($ssh);
        record_fixture($response, 'doctor/doctor/informational', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records mixed drift and informational findings', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Brew->value,
            'status' => LifecycleStatus::Active,
        ]);
        $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'jq',
            'status' => ToolStatus::Installed,
            'installed_version' => '1.6.0',
        ]);
        $sha = str_repeat('cd', 32);
        $toolsBefore = Tool::query()->count();
        doctor_fixture_bind(new NodeInspectionData(true, 'linux', 'amd64', true), [
            doctor_fixture_result('', 1),
            doctor_fixture_result("/home/linuxbrew/.linuxbrew\n"),
            doctor_fixture_result("x86_64\n"),
            doctor_fixture_result(doctor_fixture_json([
                doctor_fixture_formula('ripgrep', '14.1.1', $sha, 'x86_64_linux'),
            ])),
            doctor_fixture_result("ripgrep\n"),
            doctor_fixture_result('', 42),
        ]);

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeFalse()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(1)
            ->and($response->json('data.nodes.0.families.0.status'))->toBe('drift')
            ->and($response->json('data.summary.drift'))->toBe(1)
            ->and($response->json('data.summary.unverifiable'))->toBe(0)
            ->and($response->json('data.summary.informational'))->toBe(2)
            ->and($response->json('data.nodes.0.families.0.issues.0.code'))->toBe('tool.not_installed')
            ->and($response->json('data.nodes.0.families.0.issues.1.code'))->toBe('tool.inventory_scan')
            ->and($response->json('data.nodes.0.families.0.issues.2.resource_name'))->toBe('ripgrep')
            ->and(Tool::query()->count())->toBe($toolsBefore);
        record_fixture($response, 'doctor/doctor/mixed', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records an unreachable node without an inventory scan', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Apt->value,
            'status' => LifecycleStatus::Active,
        ]);
        $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'jq',
            'status' => ToolStatus::Installed,
        ]);
        $ssh = doctor_fixture_bind(new NodeInspectionData(false, null, null, null), []);

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeFalse()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(1)
            ->and($response->json('data.summary.drift'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(1)
            ->and($response->json('data.summary.informational'))->toBe(0)
            ->and($response->json('data.nodes.0.families.0.issues'))->toHaveCount(1)
            ->and($response->json('data.nodes.0.families.0.issues.0.code'))->toBe('tool.node_unreachable')
            ->and($ssh->commands)->toBeEmpty();
        record_fixture($response, 'doctor/doctor/unreachable', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records a truncated inventory as unverifiable with no tool rows', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        doctor_fixture_bind(new NodeInspectionData(true, 'linux', 'amd64', true), [
            doctor_fixture_result("/home/linuxbrew/.linuxbrew\n"),
            doctor_fixture_result("x86_64\n"),
            doctor_fixture_result('{', truncated: true),
            doctor_fixture_result('[{"name":"left-pad","version":'),
        ]);

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeFalse()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(0)
            ->and($response->json('data.nodes.0.families.0.status'))->toBe('unverifiable')
            ->and($response->json('data.summary.informational'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(2)
            ->and(Tool::query()->count())->toBe(0);
        record_fixture($response, 'doctor/doctor/truncated', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });

    it('records absent managers as informational and ignores unsupported casks', function (): void {
        $node = doctor_fixture_node('linux-node', '10.44.0.4');
        $ssh = doctor_fixture_bind(new NodeInspectionData(true, 'linux', 'amd64', true), [
            doctor_fixture_result('', 42),
            doctor_fixture_result('', 42),
        ]);

        $response = $this->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['tool'],
        ])->assertOk();

        expect($response->json('data.healthy'))->toBeTrue()
            ->and($response->json('data.nodes.0.families.0.checked'))->toBe(0)
            ->and($response->json('data.summary.drift'))->toBe(0)
            ->and($response->json('data.summary.unverifiable'))->toBe(0)
            ->and($response->json('data.summary.informational'))->toBe(2)
            ->and($response->json('data.nodes.0.families.0.issues.0.resource_name'))->toBe('brew')
            ->and($response->json('data.nodes.0.families.0.issues.0.observed'))->toBe('absent')
            ->and($response->json('data.nodes.0.families.0.issues.1.resource_name'))->toBe('vp')
            ->and(json_encode($response->json('data.nodes.0.families.0.issues')))->not->toContain('brew-cask')
            ->and(Tool::query()->count())->toBe(0);
        doctor_fixture_assert_read_only($ssh);
        record_fixture($response, 'doctor/doctor/empty-managers', 'Orbit\\Sdk\\Requests\\Doctor\\RunDoctorRequest', 'POST /api/v1/doctor');
    });
});

function doctor_fixture_node(
    string $name,
    string $address,
    string $platform = 'linux',
    string $user = 'orbit',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'architecture' => $platform === 'macos' ? 'arm64' : 'amd64',
        'public_ssh_host' => '192.0.2.40',
        'user' => $user,
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
}

/** @param list<CommandResult> $results */
function doctor_fixture_bind(NodeInspectionData $inspection, array $results): ToolManagerFakeSshExecutor
{
    app()->instance(NodeStateInspector::class, new class($inspection) implements NodeStateInspector
    {
        public function __construct(private NodeInspectionData $inspection) {}

        public function inspect(Node $node): NodeInspectionData
        {
            return $this->inspection;
        }
    });
    $ssh = new ToolManagerFakeSshExecutor($results);
    app()->instance(SshExecutor::class, $ssh);

    return $ssh;
}

function doctor_fixture_result(string $stdout = '', int $exitCode = 0, bool $truncated = false): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 10, $truncated);
}

/**
 * @param  list<array<string, mixed>>  $formulae
 * @param  list<array<string, mixed>>  $casks
 */
function doctor_fixture_json(array $formulae = [], array $casks = []): string
{
    return json_encode(['formulae' => $formulae, 'casks' => $casks], JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function doctor_fixture_formula(string $name, string $version, string $sha, string $tag = 'arm64_golden_gate'): array
{
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
            'installed_on_request' => true,
            'installed_as_dependency' => false,
        ]],
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $artifacts
 * @return array<string, mixed>
 */
function doctor_fixture_cask(string $token, string $version, ?array $artifacts = null): array
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

function doctor_fixture_assert_read_only(ToolManagerFakeSshExecutor $ssh): void
{
    foreach ($ssh->arguments() as $arguments) {
        expect($arguments)->not->toContain('install')
            ->not->toContain('uninstall')
            ->not->toContain('upgrade')
            ->not->toContain('autoremove');
    }
}
