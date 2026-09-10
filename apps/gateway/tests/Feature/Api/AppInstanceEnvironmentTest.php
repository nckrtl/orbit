<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    [$this->caller, $this->instance, $this->route] = environment_api_fixture();
    $this->access = new EnvironmentApiAccess;
    app()->instance(AppInstanceOperationPreflight::class, $this->access);
    app()->instance(AppInstanceEnvironmentReader::class, $this->access);
    app()->instance(AppInstanceEnvironmentWriter::class, $this->access);
});

it('updates missing existing and identical values without contacting the workload Node', function (): void {
    app()->instance(SshExecutor::class, new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw new RuntimeException('Update contacted SSH.');
        }
    });
    $url = "/api/v1/instances/{$this->instance->id}/environment/FLAG";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => 'false'])
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'app_instance_id' => $this->instance->id,
                'operation' => 'update',
                'changed' => true,
                'key_count' => 1,
            ],
            'meta' => ['request_id' => request_id_from_test_response()],
        ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => 'false'])
        ->assertJsonPath('data.changed', false);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => '0'])
        ->assertJsonPath('data.changed', true);
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => ''])
        ->assertJsonPath('data.changed', true);

    expect(AppInstanceEnvironmentValue::query()->sole()->env_value)
        ->toBe('')
        ->and($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0);
});

it('imports by exact Route hostname and applies conflict and replacement semantics', function (): void {
    $this->access->contents = "APP_KEY=synthetic-key\nNEW=value\n";
    $url = "/api/v1/instances/{$this->route->hostname}/environment/import";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.key_count', 2);

    $this->access->contents = "APP_KEY=other\nTHIRD=three\n";
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.import_conflict');

    expect($this->instance->environmentValues()->orderBy('env_key')->pluck('env_key')->all())
        ->toBe(['APP_KEY', 'NEW']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($url, ['replace' => true])
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.key_count', 3);
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($url, ['replace' => true])
        ->assertJsonPath('data.changed', false);
});

it('normalizes Laravel APP_URL while preserving literal APP_KEY', function (): void {
    $this->instance->update(['source_is_laravel' => true]);
    $this->access->contents = "APP_KEY=base64:synthetic\nAPP_URL=http://old.test\n";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/import",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertOk();

    expect($this->instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)
        ->toBe('base64:synthetic')
        ->and($this->instance->environmentValues()->where('env_key', 'APP_URL')->sole()->env_value)
        ->toBe('https://{{app_instance.hostname}}');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/APP_URL", ['value' => 'https://wrong.test'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'env.configuration_invalid');
});

it('rejects malformed duplicate unknown and wrongly typed request members before mutation', function (
    string $method,
    string $suffix,
    string $body,
): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            $method,
            "/api/v1/instances/{$this->instance->id}/environment/{$suffix}",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(AppInstanceEnvironmentValue::query()->count())
        ->toBe(0)
        ->and($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0);
})->with([
    'import unknown' => ['POST', 'import', '{"node_id":1}'],
    'import duplicate' => ['POST', 'import', '{"replace":true,"replace":false}'],
    'import escaped duplicate' => ['POST', 'import', '{"replace":true,"repl\u0061ce":false}'],
    'import wrong type' => ['POST', 'import', '{"replace":"true"}'],
    'import malformed' => ['POST', 'import', '{"replace":'],
    'update unknown' => ['PUT', 'KEY', '{"value":"safe","path":"/tmp"}'],
    'update duplicate' => ['PUT', 'KEY', '{"value":"one","value":"two"}'],
    'update escaped duplicate' => ['PUT', 'KEY', '{"value":"one","\u0076alue":"two"}'],
    'update wrong type' => ['PUT', 'KEY', '{"value":false}'],
    'update malformed' => ['PUT', 'KEY', '{"value":'],
]);

it('returns 404 for an unknown selector and 409 for an ambiguous Route target', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson('/api/v1/instances/999999/environment/KEY', ['value' => 'value'])
        ->assertNotFound();

    [$hostname] = ambiguous_environment_target($this->caller);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$hostname}/environment/KEY", ['value' => 'value'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.target_ambiguous');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->route->hostname}")
        ->assertNotFound();
});

it('enforces peer and owning-Node access before reading configuration or using SSH', function (): void {
    $denied = Node::query()->create([
        'name' => 'denied-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.202',
        'wireguard_ip' => '10.44.0.202',
        'user' => 'orbit',
    ]);
    DB::table('app_instance_environment_values')->insert([
        'app_instance_id' => $this->instance->id,
        'env_key' => 'BROKEN',
        'env_value' => 'not-ciphertext',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'arbitrary-visible-value'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0)
        ->and($this->access->writePreflights)
        ->toBe(0)
        ->and($this->access->writes)
        ->toBe([])
        ->and(AppInstanceEnvironmentValue::query()->count())
        ->toBe(1);
});

it('requires an active complete owner while keeping stored updates offline', function (): void {
    $this->instance->node->update(['status' => LifecycleStatus::Failed]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'offline'])
        ->assertOk();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/import",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.owner_unavailable');

    $this->instance->node->update(['status' => LifecycleStatus::Active]);
    $this->instance->update(['migration_required' => true]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/OTHER", ['value' => 'value'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.owner_unavailable');
});

it('synchronizes by the existing selector with a narrow value-free result', function (): void {
    $this->instance
        ->environmentValues()
        ->createMany([
            ['env_key' => 'Z_LITERAL', 'env_value' => 'local-$VALUE'],
            ['env_key' => 'APP_URL', 'env_value' => 'https://{{app_instance.hostname}}'],
            ['env_key' => 'APP_KEY', 'env_value' => 'base64:stored-key'],
        ]);
    $url = "/api/v1/instances/{$this->route->hostname}/environment/sync";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'app_instance_id' => $this->instance->id,
                'operation' => 'sync',
                'changed' => true,
                'key_count' => 3,
            ],
            'meta' => ['request_id' => request_id_from_test_response()],
        ]);

    expect($this->access->writePreflights)
        ->toBe(1)
        ->and($this->access->requiredCapacities[0])
        ->toBeGreaterThan(1_048_576)
        ->and($this->access->writes)
        ->toBe([
            "APP_KEY=\"base64:stored-key\"\nAPP_URL=\"https://environment-api.test\"\nZ_LITERAL=\"local-\\\$VALUE\"\n",
        ]);

    $this->access->writeResult = AppInstanceEnvironmentWriteResult::unchanged();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()
        ->assertJsonPath('data.changed', false);
});

it('requires exactly an empty JSON object before synchronization work', function (string $body): void {
    $this->instance->environmentValues()->create(['env_key' => 'KEY', 'env_value' => 'value']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->access->writePreflights)->toBe(0)->and($this->access->writes)->toBe([]);
})->with([
    'missing body' => '',
    'array' => '[]',
    'null' => 'null',
    'unknown member' => '{"replace":true}',
    'malformed' => '{',
]);

it('refuses missing configuration before remote work', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.configuration_missing');

    expect($this->access->writePreflights)->toBe(0)->and($this->access->writes)->toBe([]);
});

it('preflights from encrypted-size metadata before decrypting or writing', function (): void {
    DB::table('app_instance_environment_values')->insert([
        'app_instance_id' => $this->instance->id,
        'env_key' => 'BROKEN',
        'env_value' => 'not-ciphertext',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $url = "/api/v1/instances/{$this->instance->id}/environment/sync";
    $this->access->refuseWritePreflight = true;

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.write_preflight_failed');

    expect($this->access->writePreflights)->toBe(1)->and($this->access->writes)->toBe([]);

    $this->access->refuseWritePreflight = false;
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.configuration_unreadable');

    expect($this->access->writePreflights)->toBe(2)->and($this->access->writes)->toBe([]);
});

it('reports an unconfirmed synchronization without values or an unchanged claim', function (): void {
    $sentinel = 'unconfirmed-secret-sentinel';
    $this->instance->environmentValues()->create(['env_key' => 'SECRET', 'env_value' => $sentinel]);
    $this->access->writeResult = AppInstanceEnvironmentWriteResult::unconfirmed();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.sync_unconfirmed')
        ->assertJsonMissingPath('data.changed');

    expect($response->getContent())->not->toContain($sentinel);
});

function request_id_from_test_response(): string
{
    return request()->attributes->getString('orbit.request_id');
}

/** @return array{Node, AppInstance, Route} */
function environment_api_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.200',
        'wireguard_ip' => '10.44.0.200',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create([
        'name' => 'environment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.201',
        'wireguard_ip' => '10.44.0.201',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Environment API',
        'slug' => 'environment-api',
        'repository_url' => 'https://example.test/environment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/environment-api/default',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'environment-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);

    return [$caller, $instance->fresh(['node']), $route];
}

/** @return array{string} */
function ambiguous_environment_target(Node $caller): array
{
    $cluster = Cluster::query()->create(['name' => 'environment-cluster', 'status' => 'active']);
    $app = OrbitApp::query()->create([
        'name' => 'Shared environment',
        'slug' => 'shared-environment',
        'repository_url' => 'https://example.test/shared-environment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'shared-environment.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    foreach ([203, 204] as $suffix) {
        $node = Node::query()->create([
            'name' => "production-{$suffix}",
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
            'user' => 'orbit',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => "production-{$suffix}",
            'environment' => 'production',
            'checkout_path' => "/home/app-{$suffix}",
            'production_home' => "/home/app-{$suffix}",
            'production_user' => "app-{$suffix}",
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => $suffix - 203]);
    }

    $route->update(['status' => RouteStatus::Active]);
    AppInstance::query()->where('app_id', $app->id)->update(['status' => 'active']);

    return [$route->hostname];
}

final class EnvironmentApiAccess implements AppInstanceEnvironmentReader, AppInstanceEnvironmentWriter, AppInstanceOperationPreflight
{
    public int $preflights = 0;

    public int $reads = 0;

    public int $writePreflights = 0;

    /** @var list<int> */
    public array $requiredCapacities = [];

    /** @var list<string> */
    public array $writes = [];

    public bool $refuseWritePreflight = false;

    public AppInstanceEnvironmentWriteResult $writeResult;

    public string $contents = "KEY=value\n";

    public function __construct()
    {
        $this->writeResult = AppInstanceEnvironmentWriteResult::changed();
    }

    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void
    {
        $this->preflights++;
    }

    public function assertEnvironmentWritable(
        AppInstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        $this->writePreflights++;
        $this->requiredCapacities[] = $requiredCapacityBytes;

        if ($this->refuseWritePreflight) {
            throw new ResourceOperationException(
                errorCode: 'env.write_preflight_failed',
                message: 'The recorded AppInstance environment file cannot be replaced safely.',
                status: 409,
            );
        }
    }

    public function read(AppInstanceEnvironmentContext $context): string
    {
        $this->reads++;

        return $this->contents;
    }

    public function write(
        AppInstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): AppInstanceEnvironmentWriteResult {
        $this->writes[] = $contents;

        return $this->writeResult;
    }
}
