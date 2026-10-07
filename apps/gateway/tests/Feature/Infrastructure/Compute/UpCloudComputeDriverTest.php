<?php

declare(strict_types=1);

use App\Actions\Compute\ProvisionTaskSandboxAction;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Compute\UpCloudCloudInit;
use App\Infrastructure\Compute\UpCloudToken;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function compute_config(): string
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-upcloud-token-');
    file_put_contents($path, "token: ucat_test_only\n");
    chmod($path, 0600);
    test()->beforeApplicationDestroyed(static function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });
    config(['compute.upcloud' => [
        'enabled' => true, 'token_file' => $path, 'max_vms' => 2, 'zone' => 'nl-ams1',
        'image' => SandboxSpec::Image, 'gateway_address' => '1.1.1.1',
        'wireguard_address' => '8.8.8.8', 'wireguard_port' => 51820,
    ]]);
    Http::preventStrayRequests();

    return $path;
}

function compute_spec(): SandboxSpec
{
    return new SandboxSpec('nl-ams1', '1.1.1.1', '8.8.8.8', 51820, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T fixture');
}

function compute_sandbox(bool $created = false): TaskSandbox
{
    $id = (string) Str::uuid();

    return TaskSandbox::query()->create([
        'id' => $id, 'provider' => 'upcloud', 'name' => 'orbit-sandbox-'.$id,
        'spec' => compute_spec()->toArray(), 'state' => $created ? SandboxState::Running : SandboxState::Reserved,
        'credential_fingerprint' => $created ? hash('sha256', 'ucat_test_only') : null,
        'desired_power' => 'running', 'create_attempted_at' => $created ? now() : null,
        'server_id' => $created ? '00000000-0000-4000-8000-000000000001' : null,
        'disk_id' => $created ? '00000000-0000-4000-8000-000000000002' : null,
    ]);
}

/** Fixture captured from the retained DLF VM on 7 Oct 2026; identifiers are anonymized.
 * @return array<string, mixed>
 */
function compute_server(TaskSandbox $sandbox, string $state = 'started'): array
{
    $fixture = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/Compute/upcloud-server.json'), true, flags: JSON_THROW_ON_ERROR);
    $server = $fixture['server'];
    $server['hostname'] = $server['title'] = $sandbox->name;
    $server['labels'] = ['label' => [['key' => 'orbit-sandbox', 'value' => $sandbox->id]]];
    $server['storage_devices']['storage_device'][0]['storage_title'] = $sandbox->name.'-disk';
    $server['state'] = $state;

    return ['server' => $server];
}

/** @return array<string, mixed> */
function compute_firewall(bool $sealed = false): array
{
    return ['firewall_rules' => ['firewall_rule' => app(UpCloudCloudInit::class)->firewall(compute_spec(), $sealed)]];
}

function compute_group(): Task
{
    $slug = 'compute-'.Str::lower(Str::random(8));
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'repository_url' => 'https://github.com/acme/compute.git', 'default_branch' => 'main']);

    return Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Cloud group', 'brief' => 'One sandbox',
        'status' => TaskGroupStatus::Todo, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
}

function compute_keys(): void
{
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            throw new RuntimeException('The compute driver must never read the SSH private key.');
        }

        public function publicKey(): string
        {
            return compute_spec()->publicKey;
        }
    });
}

describe('UpCloud provisioning', function (): void {
    it('records creation before sending it and creates one smallest VM without secrets in cloud-init', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        Http::fake([
            'https://api.upcloud.com/1.3/server' => function (Request $request) use ($sandbox) {
                expect($sandbox->fresh()->create_attempted_at)->not->toBeNull();
                expect($request->data()['server'])
                    ->toMatchArray(['plan' => 'STARTER-1xCPU-1GB', 'zone' => 'nl-ams1', 'firewall' => 'on']);
                expect($request->data()['server']['storage_devices']['storage_device'][0])
                    ->toMatchArray(['storage' => SandboxSpec::Image, 'size' => 20]);
                expect($request->data()['server']['user_data'])->toContain('ssh-ed25519', '/swapfile')->not->toContain('ucat_test_only', 'apiKey', 'orbit-token', 'orbit-worker');

                return Http::response(compute_server($sandbox), 201);
            },
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox)),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::response(compute_firewall()),
        ]);

        $result = app(ComputeDriver::class)->provision($sandbox);
        app(ComputeDriver::class)->provision($sandbox);

        expect($result->state)->toBe(SandboxState::Running);
        expect($result->server_id)->toBe('00000000-0000-4000-8000-000000000001');
        expect($result->disk_id)->toBe('00000000-0000-4000-8000-000000000002');
        expect($result->public_address)->toBe('203.0.113.20');
        expect($result->firewall_configured_at)->not->toBeNull();
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer ucat_test_only'));
    });

    it('recovers a lost create response by exact provider ownership without another POST', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        Http::fake([
            'https://api.upcloud.com/1.3/server' => fn (Request $request) => $request->method() === 'POST'
                ? Http::failedConnection()($request)
                : Http::response(['servers' => ['server' => [compute_server($sandbox)['server']]]]),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox)),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::response(compute_firewall()),
        ]);
        expect(fn () => app(ComputeDriver::class)->provision($sandbox))->toThrow(ComputeException::class, 'did not return');
        expect($sandbox->fresh()->create_attempted_at)->not->toBeNull();
        Http::assertSentCount(1);

        expect(app(ComputeDriver::class)->provision($sandbox)->state)->toBe(SandboxState::Running);
        expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
        Http::assertSentCount(4);
    });

    it('holds capacity and refuses another create when an ambiguous attempt has no visible server', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        $sandbox->update(['create_attempted_at' => now(), 'state' => SandboxState::Creating, 'credential_fingerprint' => hash('sha256', 'ucat_test_only')]);
        Http::fake(['https://api.upcloud.com/1.3/server' => Http::response(['servers' => ['server' => []]])]);

        expect(fn () => app(ComputeDriver::class)->provision($sandbox))->toThrow(ComputeException::class, 'no second VM');
        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'no second VM');
        expect($sandbox->fresh()->state)->toBe(SandboxState::Uncertain);
        expect(app(ComputeDriver::class)->capacity())->toBe(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    });

    it('does not adopt duplicate matching provider servers', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        $sandbox->update(['create_attempted_at' => now(), 'credential_fingerprint' => hash('sha256', 'ucat_test_only')]);
        $server = compute_server($sandbox)['server'];
        Http::fake(['https://api.upcloud.com/1.3/server' => Http::response(['servers' => ['server' => [$server, $server]]])]);

        expect(fn () => app(ComputeDriver::class)->provision($sandbox))->toThrow(ComputeException::class, 'ownership');
        expect($sandbox->fresh()->server_id)->toBeNull();
        Http::assertSentCount(1);
    });

    it('rejects a server whose identity or recorded disk differs before any mutation', function (string $change): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        $fixture = compute_server($sandbox, 'stopped');
        match ($change) {
            'hostname' => $fixture['server']['hostname'] = 'someone-else',
            'label' => $fixture['server']['labels']['label'] = [],
            'disk' => $fixture['server']['storage_devices']['storage_device'][0]['storage'] = '00000000-0000-4000-8000-000000000003',
            'extra disk' => $fixture['server']['storage_devices']['storage_device'][] = $fixture['server']['storage_devices']['storage_device'][0],
        };
        Http::fake(['https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response($fixture)]);

        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'ownership');
        expect($sandbox->fresh()->error_code)->toBe('compute.ownership_mismatch');
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    })->with(['hostname', 'label', 'disk', 'extra disk']);

    it('reports provider boot without claiming the machine is running', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake(['https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox, 'starting'))]);

        expect(app(ComputeDriver::class)->observe($sandbox)->state)->toBe(SandboxState::Starting);
        expect($sandbox->fresh()->firewall_configured_at)->toBeNull();
        Http::assertSentCount(1);
    });

    it('refuses to report running when the provider firewall is disabled', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        $fixture = compute_server($sandbox);
        $fixture['server']['firewall'] = 'off';
        Http::fake(['https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response($fixture)]);

        expect(fn () => app(ComputeDriver::class)->observe($sandbox))->toThrow(ComputeException::class, 'not enabled');
        expect($sandbox->fresh()->error_code)->toBe('compute.firewall_failed');
        Http::assertSentCount(1);
    });

    it('replaces drifted firewall rules and verifies the replacement', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox)),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::sequence()
                ->push(['firewall_rules' => ['firewall_rule' => []]])->push([], 204)->push(compute_firewall()),
        ]);

        expect(app(ComputeDriver::class)->observe($sandbox)->state)->toBe(SandboxState::Running);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->data()['firewall_rules']['firewall_rule'] === compute_firewall()['firewall_rules']['firewall_rule']);
        Http::assertSentCount(4);
    });

    it('keeps a firewall failure inspectable instead of treating an accepted PUT as readiness', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox)),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::sequence()
                ->push(['firewall_rules' => ['firewall_rule' => []]])->push([], 204)->push(['firewall_rules' => ['firewall_rule' => []]]),
        ]);

        expect(fn () => app(ComputeDriver::class)->observe($sandbox))->toThrow(ComputeException::class, 'did not converge');
        expect($sandbox->fresh()->firewall_configured_at)->toBeNull();
        Http::assertSentCount(4);
    });

    it('seals metadata access after bootstrap while preserving the hub and public web access', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox)),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::sequence()
                ->push(compute_firewall())->push([], 204)->push(compute_firewall(true)),
        ]);

        expect(app(ComputeDriver::class)->sealNetwork($sandbox)->state)->toBe(SandboxState::Running);
        expect($sandbox->fresh()->network_policy)->toBe('sealed');
        expect($sandbox->fresh()->firewall_configured_at)->not->toBeNull();
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $rules = $request->data()['firewall_rules']['firewall_rule'];
            $acceptsMetadata = array_filter($rules, fn (array $rule): bool => $rule['action'] === 'accept'
                && ($rule['destination_address_start'] ?? null) === '169.254.169.254');
            $hub = array_filter($rules, fn (array $rule): bool => $rule['action'] === 'accept'
                && ($rule['destination_address_start'] ?? null) === '8.8.8.8' && ($rule['destination_port_start'] ?? null) === '51820');
            $web = array_filter($rules, fn (array $rule): bool => $rule['action'] === 'accept'
                && ($rule['destination_port_start'] ?? null) === '443');
            $ipv6Drop = array_filter($rules, fn (array $rule): bool => $rule['direction'] === 'out'
                && $rule['action'] === 'drop' && $rule['family'] === 'IPv6');

            return $acceptsMetadata === [] && count($hub) === 1 && count($web) === 1 && count($ipv6Drop) === 1;
        });
        Http::assertSentCount(4);
    });

    it('retains sealed network intent after a lost firewall update and retries it on observation', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox)),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::sequence()
                ->push(compute_firewall())->push([], 503)->push(compute_firewall())->push([], 204)->push(compute_firewall(true)),
        ]);

        expect(fn () => app(ComputeDriver::class)->sealNetwork($sandbox))->toThrow(ComputeException::class, 'HTTP 503');
        expect($sandbox->fresh()->network_policy)->toBe('sealed');
        expect($sandbox->fresh()->firewall_configured_at)->toBeNull();
        expect($sandbox->fresh()->state)->toBe(SandboxState::Uncertain);
        expect(app(ComputeDriver::class)->observe($sandbox)->state)->toBe(SandboxState::Running);
        Http::assertSentCount(7);
    });
});

describe('UpCloud power and deletion', function (): void {
    it('parks a VM and observes shutdown on the next call without a polling loop', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::sequence()->push(compute_server($sandbox))->push(compute_server($sandbox, 'stopped')),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/stop' => Http::response([], 202),
        ]);

        expect(app(ComputeDriver::class)->park($sandbox)->state)->toBe(SandboxState::Stopping);
        expect(app(ComputeDriver::class)->observe($sandbox)->state)->toBe(SandboxState::Stopped);
        expect($sandbox->fresh()->desired_power)->toBe('stopped');
        Http::assertSentCount(3);
    });

    it('resumes a stopped VM after verifying its firewall', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response(compute_server($sandbox, 'stopped')),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::response(compute_firewall()),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/start' => Http::response([], 202),
        ]);

        expect(app(ComputeDriver::class)->resume($sandbox)->state)->toBe(SandboxState::Starting);
        expect($sandbox->fresh()->desired_power)->toBe('running');
        Http::assertSentCount(3);
    });

    it('confirms both server and disk deletion before releasing capacity', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::sequence()
                ->push(compute_server($sandbox))->push(compute_server($sandbox, 'stopped'))->push([], 404),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/stop' => Http::response([], 202),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/?storages=1' => Http::response([], 204),
            'https://api.upcloud.com/1.3/storage/00000000-0000-4000-8000-000000000002' => Http::response([], 404),
        ]);

        expect(app(ComputeDriver::class)->destroy($sandbox)->state)->toBe(SandboxState::Destroying);
        expect(app(ComputeDriver::class)->destroy($sandbox)->state)->toBe(SandboxState::Destroying);
        expect(app(ComputeDriver::class)->capacity())->toBe(1);
        expect(app(ComputeDriver::class)->observe($sandbox)->state)->toBe(SandboxState::Destroyed);
        expect(app(ComputeDriver::class)->capacity())->toBe(2);
        expect($sandbox->fresh()->destroyed_at)->not->toBeNull();
        Http::assertSentCount(6);
    });

    it('cleans up a detached owned disk after the server was deleted separately', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response([], 404),
            'https://api.upcloud.com/1.3/storage/00000000-0000-4000-8000-000000000002' => Http::sequence()->push(['storage' => [
                'uuid' => $sandbox->disk_id, 'title' => $sandbox->name.'-disk', 'servers' => ['server' => []],
            ]])->push([], 204)->push([], 404),
        ]);

        expect(app(ComputeDriver::class)->destroy($sandbox)->state)->toBe(SandboxState::Destroying);
        expect(app(ComputeDriver::class)->destroy($sandbox)->state)->toBe(SandboxState::Destroyed);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.upcloud.com/1.3/storage/00000000-0000-4000-8000-000000000002');
        Http::assertSentCount(5);
    });

    it('does not delete a remaining disk with another owner or attachment', function (string $change): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        $storage = ['uuid' => $sandbox->disk_id, 'title' => $sandbox->name.'-disk', 'servers' => ['server' => []]];
        if ($change === 'title') {
            $storage['title'] = 'unrelated-disk';
        } else {
            $storage['servers']['server'] = ['00000000-0000-4000-8000-000000000003'];
        }
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response([], 404),
            'https://api.upcloud.com/1.3/storage/00000000-0000-4000-8000-000000000002' => Http::response(['storage' => $storage]),
        ]);

        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'ownership');
        expect(app(ComputeDriver::class)->capacity())->toBe(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    })->with(['title', 'attachment']);

    it('retains deletion intent after a provider failure and retries from observed state', function (): void {
        compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::sequence()->push(compute_server($sandbox, 'stopped'))->push([], 404),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/?storages=1' => Http::response(['error' => 'ucat_test_only'], 503),
            'https://api.upcloud.com/1.3/storage/00000000-0000-4000-8000-000000000002' => Http::response([], 404),
        ]);

        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'HTTP 503');
        expect($sandbox->fresh()->desired_power)->toBe('destroyed');
        expect(app(ComputeDriver::class)->observe($sandbox)->state)->toBe(SandboxState::Destroyed);
        Http::assertSentCount(4);
    });

    it('refuses VM destruction while its Node is still enrolled', function (): void {
        compute_config();
        $node = Node::query()->create(['name' => 'sandbox-node', 'platform' => 'linux', 'public_ssh_host' => '203.0.113.20']);
        $sandbox = compute_sandbox(true);
        $sandbox->update(['node_id' => $node->id]);

        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'fleet');
        expect($sandbox->fresh()->desired_power)->toBe('running');
        Http::assertNothingSent();
    });

    it('releases a reservation that never attempted creation without contacting the provider', function (): void {
        compute_config();
        $sandbox = compute_sandbox();

        expect(app(ComputeDriver::class)->destroy($sandbox)->state)->toBe(SandboxState::Destroyed);
        Http::assertNothingSent();
    });

    it('continues cleanup when new UpCloud provisioning is disabled', function (): void {
        compute_config();
        config(['compute.upcloud.enabled' => false]);
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => Http::response([], 404),
            'https://api.upcloud.com/1.3/storage/00000000-0000-4000-8000-000000000002' => Http::response([], 404),
        ]);

        expect(app(ComputeDriver::class)->destroy($sandbox)->state)->toBe(SandboxState::Destroyed);
        Http::assertSentCount(2);
    });

    it('retains ownership when changed credentials could make another account report the VM absent', function (): void {
        $path = compute_config();
        $sandbox = compute_sandbox(true);
        file_put_contents($path, "token: ucat_different_account\n");

        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'credential changed');
        expect($sandbox->fresh()->state)->toBe(SandboxState::Running);
        expect($sandbox->fresh()->destroyed_at)->toBeNull();
        Http::assertNothingSent();
    });

    it('checks credential identity again between server and disk absence checks', function (): void {
        $path = compute_config();
        $sandbox = compute_sandbox(true);
        Http::fake([
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => function () use ($path) {
                file_put_contents($path, "token: ucat_different_account\n");

                return Http::response([], 404);
            },
        ]);

        expect(fn () => app(ComputeDriver::class)->destroy($sandbox))->toThrow(ComputeException::class, 'credential changed');
        expect($sandbox->fresh()->state)->toBe(SandboxState::Destroying);
        expect($sandbox->fresh()->destroyed_at)->toBeNull();
        Http::assertSentCount(1);
    });

    it('refuses a schema rollback that would forget an outstanding VM reservation', function (): void {
        compute_config();
        compute_sandbox();
        $migration = require __DIR__.'/../../../../database/migrations/2026_10_07_000200_create_task_sandboxes_table.php';

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Destroy outstanding');
        expect(TaskSandbox::query()->count())->toBe(1);
        Http::assertNothingSent();
    });
});

describe('UpCloud reservation and credentials', function (): void {
    it('reuses the group reservation and frozen network configuration', function (): void {
        compute_config();
        compute_keys();
        $group = compute_group();
        Http::fake([
            'https://api.upcloud.com/1.3/server' => fn (Request $request) => $request->method() === 'POST'
                ? Http::failedConnection()($request)
                : Http::response(['servers' => ['server' => [compute_server(TaskSandbox::query()->sole())['server']]]]),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001' => fn () => Http::response(compute_server(TaskSandbox::query()->sole())),
            'https://api.upcloud.com/1.3/server/00000000-0000-4000-8000-000000000001/firewall_rule' => Http::response(compute_firewall()),
        ]);
        expect(fn () => app(ProvisionTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class);
        $sandbox = TaskSandbox::query()->sole();
        config(['compute.upcloud.gateway_address' => '9.9.9.9']);

        $result = app(ProvisionTaskSandboxAction::class)->execute($group);

        expect($result->id)->toBe($sandbox->id);
        expect($result->spec['gateway_address'])->toBe('1.1.1.1');
        expect(TaskSandbox::query()->count())->toBe(1);
        expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
        Http::assertSentCount(4);
    });

    it('does not allocate another reservation when the VM budget is full', function (): void {
        compute_config();
        compute_keys();
        config(['compute.upcloud.max_vms' => 1]);
        compute_sandbox();
        $group = compute_group();

        expect(fn () => app(ProvisionTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class, 'budget is full');
        expect(TaskSandbox::query()->count())->toBe(1);
        Http::assertNothingSent();
    });

    it('refuses creation when recorded reservations already exceed the configured budget', function (): void {
        compute_config();
        config(['compute.upcloud.max_vms' => 1]);
        $sandbox = compute_sandbox();
        compute_sandbox();

        expect(fn () => app(ComputeDriver::class)->provision($sandbox))->toThrow(ComputeException::class, 'budget is full');
        expect($sandbox->fresh()->create_attempted_at)->toBeNull();
        Http::assertNothingSent();
    });

    it('starts a new reservation after the previous VM was confirmed destroyed', function (): void {
        compute_config();
        compute_keys();
        $group = compute_group();
        $sandbox = compute_sandbox();
        $sandbox->update(['group_id' => $group->id, 'state' => SandboxState::Destroyed, 'destroyed_at' => now()]);
        Http::fake(['https://api.upcloud.com/1.3/server' => Http::failedConnection()]);

        expect(fn () => app(ProvisionTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class);
        expect(TaskSandbox::query()->count())->toBe(2);
        expect(TaskSandbox::query()->where('state', '!=', 'destroyed')->sole()->id)->not->toBe($sandbox->id);
        Http::assertSentCount(1);
    });

    it('preserves sandbox ownership when the task group is deleted', function (): void {
        compute_config();
        $group = compute_group();
        $sandbox = compute_sandbox(true);
        $sandbox->update(['group_id' => $group->id]);

        $group->delete();

        expect($sandbox->fresh()->group_id)->toBeNull();
        expect($sandbox->fresh()->server_id)->toBe('00000000-0000-4000-8000-000000000001');
        Http::assertNothingSent();
    });

    it('refuses a concurrent operation while the provider lock is held', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        $lock = Cache::lock('orbit:compute:upcloud', 180);
        $lock->get();
        try {
            expect(fn () => app(ComputeDriver::class)->provision($sandbox))->toThrow(ComputeException::class, 'operation is running');
        } finally {
            $lock->release();
        }
        expect($sandbox->fresh()->create_attempted_at)->toBeNull();
        Http::assertNothingSent();
    });

    it('refuses new allocation when disabled', function (): void {
        compute_config();
        config(['compute.upcloud.enabled' => false]);
        $group = compute_group();

        expect(fn () => app(ProvisionTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class, 'disabled');
        expect(TaskSandbox::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('does not allocate a VM for an ended task group', function (TaskGroupStatus $status): void {
        compute_config();
        $group = compute_group();
        $group->update(['status' => $status]);

        expect(fn () => app(ProvisionTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class, 'ended task group');
        expect(TaskSandbox::query()->count())->toBe(0);
        Http::assertNothingSent();
    })->with([TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed]);

    it('does not allocate a VM for a group that already has a shared workspace', function (): void {
        compute_config();
        $group = compute_group();
        $node = Node::query()->create(['name' => 'shared-node', 'platform' => 'linux', 'public_ssh_host' => '203.0.113.20']);
        $instance = Instance::query()->create([
            'name' => 'existing-workspace', 'project_id' => $group->project_id, 'node_id' => $node->id,
            'checkout_path' => '/tmp/existing-workspace', 'status' => 'reserved',
        ]);
        $group->taskable()->associate($instance);
        $group->save();

        expect(fn () => app(ProvisionTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class, 'placement cannot change');
        expect(TaskSandbox::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('refuses token files that are public or malformed without contacting UpCloud', function (string $change): void {
        $path = compute_config();
        if ($change === 'public') {
            chmod($path, 0644);
        } else {
            file_put_contents($path, "token: ucat_test_only\ntoken: ucat_other\n");
        }

        expect(fn () => app(UpCloudToken::class)->read())->toThrow(ComputeException::class, 'private Gateway-owned');
        Http::assertNothingSent();
    })->with(['public', 'duplicate']);

    it('redacts provider response bodies and exception chains', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        Http::fake(['https://api.upcloud.com/1.3/server' => Http::response(['error' => 'ucat_test_only'], 403)]);

        try {
            app(ComputeDriver::class)->provision($sandbox);
            test()->fail('Expected a provider refusal.');
        } catch (ComputeException $exception) {
            expect($exception->getMessage())->toContain('HTTP 403')->not->toContain('ucat_test_only');
            expect($exception->getPrevious())->toBeNull();
        }
        expect($sandbox->fresh()->error_code)->toBe('compute.provider_failed');
        Http::assertSentCount(1);
    });

    it('refuses a redirect without sending the token to another origin', function (): void {
        compute_config();
        $sandbox = compute_sandbox();
        Http::fake(['https://api.upcloud.com/1.3/server' => Http::response('', 302, ['Location' => 'https://other.example/'])]);

        expect(fn () => app(ComputeDriver::class)->provision($sandbox))->toThrow(ComputeException::class, 'HTTP 302');
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://other.example/'));
    });

    it('rejects private endpoints or newline injection before a provider reservation', function (string $gateway, string $key): void {
        compute_config();

        expect(fn () => new SandboxSpec('nl-ams1', $gateway, '8.8.8.8', 51820, $key))->toThrow(ComputeException::class, 'invalid');
        Http::assertNothingSent();
    })->with([
        'private Gateway' => ['10.44.0.2', compute_spec()->publicKey],
        'key injection' => ['1.1.1.1', compute_spec()->publicKey."\nextra"],
    ]);
});
