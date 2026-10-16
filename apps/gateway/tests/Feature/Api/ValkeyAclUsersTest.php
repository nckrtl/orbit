<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeValkeyServerAdmin;

const VALKEY_ACL_ADMIN_SECRET = 'valkey-admin-secret-4b2e';

beforeEach(function (): void {
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);
    $this->valkey = new FakeValkeyServerAdmin;
    app()->instance(ValkeyServerAdmin::class, $this->valkey);

    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->dbNode = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'services-valkey',
        'driver' => 'redis',
        'node_id' => $this->dbNode->id,
        'tag' => '8.1',
        'port' => 6380,
        'root_password' => VALKEY_ACL_ADMIN_SECRET,
        'status' => LifecycleStatus::Active,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function valkey_acl_user_request(array $overrides = []): array
{
    return [
        'slug' => 'gateway-queue',
        'server' => 'services-valkey',
        'patterns' => ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
        'allow' => ['@connection', '@list', 'eval', 'client|setname'],
        ...$overrides,
    ];
}

function valkey_acl_user_instance(Node $node): Instance
{
    $project = Project::query()->create([
        'name' => 'DLF',
        'slug' => 'dlf',
        'repository_url' => 'https://example.test/dlf.git',
        'default_branch' => 'main',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature-x',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/dlf/feature-x',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

/** @param list<string> $secrets */
function valkey_acl_user_assert_no_secret(TestResponse $response, array $secrets): void
{
    $activity = Activity::query()->where('request_id', $response->headers->get('X-Orbit-Request-Id'))->sole();

    foreach ($secrets as $secret) {
        expect($response->getContent())->not->toContain($secret)
            ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    }
}

describe('database:create --server on a Valkey server', function (): void {
    it('creates the ACL user and records a redis connection that logs in as it', function (): void {
        $response = $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['database' => 1]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.slug', 'gateway-queue')
            ->assertJsonPath('data.driver', 'redis')
            ->assertJsonPath('data.node_id', $this->dbNode->id)
            ->assertJsonPath('data.host', '10.44.0.80')
            ->assertJsonPath('data.port', 6380)
            ->assertJsonPath('data.database', '1')
            ->assertJsonPath('data.path', null)
            ->assertJsonPath('data.username', 'gateway-queue')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.server', 'services-valkey')
            ->assertJsonPath('data.owner_instance_id', null)
            ->assertJsonPath('data.test_database', null)
            ->assertJsonMissingPath('data.password');

        $connection = DatabaseConnection::query()->sole();
        $password = (string) $connection->password;
        $rules = 'on ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +@list +eval +client|setname -@admin -@dangerous';
        $live = $this->valkey->users['gateway-queue'];

        expect(strlen($password))->toBe(40)
            ->and($connection->database_server_id)->toBe($this->server->id)
            ->and($this->valkey->calls)->toBe(['config get databases', 'exists gateway-queue', 'setuser gateway-queue'])
            ->and($live->rules)->toBe($rules)
            ->and($live->hasOnlyPassword($password))->toBeTrue()
            ->and($this->admin->statements)->toBe([]);

        $user = DatabaseUser::query()->sole();
        $stored = DB::table('database_users')->where('id', $user->id)->value('password');

        expect($user->database_connection_id)->toBe($connection->id)
            ->and($user->username)->toBe('gateway-queue')
            ->and($user->privileges)->toBe($rules)
            ->and($user->password)->toBe($password)
            ->and($stored)->toBeString()->not->toContain($password)
            ->and($user->created_by)->toBe('gateway');

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($activity->command)->toBe('database:create')
            ->and($activity->properties?->get('input'))->toMatchArray([
                'patterns' => ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
                'allow' => ['@connection', '@list', 'eval', 'client|setname'],
            ]);

        valkey_acl_user_assert_no_secret($response, [$password, ValkeyAclUser::passwordHash($password), VALKEY_ACL_ADMIN_SECRET]);

        $this->getJson('/api/v1/database-connections/gateway-queue/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'gateway-queue')
            ->assertJsonPath('data.0.privileges', $rules)
            ->assertJsonMissingPath('data.0.password')
            ->assertDontSee($password);
        $this->getJson('/api/v1/database-servers/services-valkey')
            ->assertOk()
            ->assertJsonPath('data.databases_count', 1)
            ->assertJsonPath('data.databases.0.slug', 'gateway-queue');
    });

    it('refuses a competing create without overwriting or deleting the first user', function (): void {
        $this->valkey->afterSetUser = function (): void {
            $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
                ->assertConflict()
                ->assertJsonPath('error.code', 'database.server_in_use');
        };

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();

        $connection = DatabaseConnection::query()->sole();
        expect($this->valkey->users['gateway-queue']->hasOnlyPassword((string) $connection->password))->toBeTrue()
            ->and($this->valkey->calls)->toBe(['exists gateway-queue', 'setuser gateway-queue']);

        $this->valkey->afterSetUser = null;
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.slug_conflict');
    });

    it('releases the ACL lock after a refused rule so the next create can succeed', function (): void {
        $this->valkey->refuseRules = 'Unknown command';
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertUnprocessable();
        $this->valkey->refuseRules = null;

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();

        expect(DatabaseConnection::query()->count())->toBe(1);
    });

    it('records no database index without one', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertCreated()
            ->assertJsonPath('data.database', null);
    });

    it('refuses a user that the server already has and Orbit did not record', function (): void {
        $this->valkey->users['gateway-queue'] = new ValkeyAclUser('gateway-queue', 'on ~* +@all', []);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.name_conflict');

        expect($this->valkey->calls)->toBe(['exists gateway-queue'])
            ->and($this->valkey->users['gateway-queue']->rules)->toBe('on ~* +@all')
            ->and(DatabaseConnection::query()->exists())->toBeFalse();
    });

    it('refuses a username that a connection on the server already has', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'services-valkey',
            'driver' => 'redis',
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'port' => 6380,
            'username' => 'gateway-queue',
            'password' => 'other-secret',
        ]);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.name_conflict');

        expect($this->valkey->calls)->toBe([]);
    });

    it('returns the message of a rule that Valkey refuses and records nothing', function (): void {
        $this->valkey->refuseRules = "Error in ACL SETUSER modifier '+nosuch': Unknown command";

        $response = $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['allow' => ['nosuch']]));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'database.acl_rules_invalid')
            ->assertJsonPath('error.message', "Valkey refused a key pattern or a rule: Error in ACL SETUSER modifier '+nosuch': Unknown command.");

        expect($this->valkey->calls)->toBe(['exists gateway-queue', 'setuser gateway-queue', 'deluser gateway-queue'])
            ->and($this->valkey->users)->toBe([])
            ->and(DatabaseConnection::query()->exists())->toBeFalse()
            ->and(DatabaseUser::query()->exists())->toBeFalse();

        valkey_acl_user_assert_no_secret($response, [VALKEY_ACL_ADMIN_SECRET]);
    });

    it('removes the user when Orbit cannot record its connection', function (): void {
        $this->valkey->afterSetUser = function (): void {
            DatabaseConnection::query()->create(['slug' => 'gateway-queue', 'driver' => 'sqlite', 'path' => '/tmp/queue.sqlite']);
        };

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.slug_conflict');

        expect($this->valkey->calls)->toBe(['exists gateway-queue', 'setuser gateway-queue', 'deluser gateway-queue'])
            ->and($this->valkey->users)->toBe([])
            ->and(DatabaseConnection::query()->sole()->driver->value)->toBe('sqlite')
            ->and(DatabaseUser::query()->exists())->toBeFalse();
    });

    it('fails without output when an admin command fails', function (): void {
        $this->valkey->failCommands = true;

        $response = $this->postJson('/api/v1/database-connections', valkey_acl_user_request());

        $response
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_command_failed')
            ->assertJsonPath('error.message', 'Database server [services-valkey] could not run an admin command.');

        expect(DatabaseConnection::query()->exists())->toBeFalse();

        valkey_acl_user_assert_no_secret($response, [VALKEY_ACL_ADMIN_SECRET]);
    });

    it('refuses an existing slug and a server that is not active before any admin command', function (): void {
        DatabaseConnection::query()->create(['slug' => 'gateway-queue', 'driver' => 'sqlite', 'path' => '/tmp/queue.sqlite']);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.slug_conflict');

        $this->server->update(['status' => LifecycleStatus::Provisioning]);

        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['slug' => 'other']))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_inactive');

        expect($this->valkey->calls)->toBe([]);
    });

    it('refuses an Instance with database.driver_unsupported', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['instance_id' => valkey_acl_user_instance($this->dbNode)->id]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'database.driver_unsupported');

        expect($this->valkey->calls)->toBe([])
            ->and($this->admin->statements)->toBe([])
            ->and(DatabaseConnection::query()->exists())->toBeFalse();
    });

    it('refuses an invalid slug, pattern, rule, or database index', function (array $overrides, string $field): void {
        $this->postJson('/api/v1/database-connections', [...valkey_acl_user_request(), ...$overrides])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors($field, 'error.details');

        expect($this->valkey->calls)->toBe([]);
    })->with([
        'the admin user' => [['slug' => 'default'], 'slug'],
        'no patterns' => [['patterns' => []], 'patterns'],
        'a pattern string' => [['patterns' => 'orbit-gateway:*'], 'patterns'],
        'a pattern map' => [['patterns' => ['a' => 'orbit-gateway:*']], 'patterns'],
        '33 patterns' => [['patterns' => array_map(static fn (int $index): string => "p{$index}:*", range(1, 33))], 'patterns'],
        'a pattern with a space' => [['patterns' => ['orbit gateway:*']], 'patterns.0'],
        'a pattern with a control character' => [['patterns' => ["orbit-gateway:*\n"]], 'patterns.0'],
        'a pattern that starts with ~' => [['patterns' => ['~orbit-gateway:*']], 'patterns.0'],
        'a read pattern' => [['patterns' => ['%R~orbit-gateway:*']], 'patterns.0'],
        'a channel pattern' => [['patterns' => ['&orbit-gateway:*']], 'patterns.0'],
        'a long pattern' => [['patterns' => [str_repeat('k', 256)]], 'patterns.0'],
        'no rules' => [['allow' => []], 'allow'],
        '65 rules' => [['allow' => array_fill(0, 65, 'get')], 'allow'],
        'a rule with a plus' => [['allow' => ['+@list']], 'allow.0'],
        'a deny rule' => [['allow' => ['-@all']], 'allow.0'],
        'an uppercase rule' => [['allow' => ['@List']], 'allow.0'],
        'two rules in one' => [['allow' => ['get set']], 'allow.0'],
        'a key pattern as a rule' => [['allow' => ['~*']], 'allow.0'],
        'an empty subcommand' => [['allow' => ['client|']], 'allow.0'],
        'a password rule' => [['allow' => ['>secret']], 'allow.0'],
        'a string index' => [['database' => '1'], 'database'],
        'a negative index' => [['database' => -1], 'database'],
        'a large index' => [['database' => 1000], 'database'],
        'a host' => [['host' => '10.44.0.80'], 'host'],
        'a username' => [['username' => 'gateway-queue'], 'username'],
        'a password' => [['password' => 'chosen'], 'password'],
    ]);

    it('reports a missing server before it checks the Valkey fields', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['server' => 'missing', 'patterns' => []]))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'database.server_missing');
    });
});

describe('the Valkey fields elsewhere', function (): void {
    it('refuses patterns, rules, and a database index on a MySQL server', function (array $overrides): void {
        DatabaseServer::query()->create([
            'slug' => 'beast-mysql',
            'node_id' => $this->dbNode->id,
            'tag' => '8.4',
            'port' => 3306,
            'root_password' => 'mysql-root-secret',
            'status' => LifecycleStatus::Active,
        ]);

        $this->postJson('/api/v1/database-connections', ['slug' => 'reporting', 'server' => 'beast-mysql', ...$overrides])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($this->admin->statements)->toBe([])
            ->and(DatabaseConnection::query()->exists())->toBeFalse();
    })->with([
        'patterns' => [['patterns' => ['reporting:*']]],
        'rules' => [['allow' => ['@read']]],
        'a database index' => [['database' => 1]],
    ]);

    it('refuses patterns and rules on a registered connection', function (string $field): void {
        $this->postJson('/api/v1/database-connections', [
            'slug' => 'cache',
            'driver' => 'redis',
            'host' => '10.44.0.80',
            $field => ['@read'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseConnection::query()->exists())->toBeFalse();
    })->with(['patterns', 'allow']);

    it('refuses database:user:create on a connection of a Valkey server', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();
        $this->valkey->calls = [];

        $this->postJson('/api/v1/database-connections/gateway-queue/users', [
            'username' => 'reporting',
            'password' => 'reporting-secret',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'database.driver_unsupported');

        expect($this->valkey->calls)->toBe([])
            ->and($this->admin->statements)->toBe([])
            ->and(DatabaseUser::query()->count())->toBe(1);
    });
});

describe('database:destroy on a Valkey server', function (): void {
    it('deletes the ACL user and its records, and keeps the keys', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();
        $this->valkey->calls = [];

        $this->deleteJson('/api/v1/database-connections/gateway-queue')
            ->assertOk()
            ->assertJsonPath('data.slug', 'gateway-queue');

        expect($this->valkey->calls)->toBe(['deluser gateway-queue'])
            ->and($this->valkey->users)->toBe([])
            ->and($this->admin->statements)->toBe([])
            ->and(DatabaseConnection::query()->exists())->toBeFalse()
            ->and(DatabaseUser::query()->exists())->toBeFalse();
    });

    it('deletes only the record of a connection that logs in as default', function (?string $username): void {
        DatabaseConnection::query()->create([
            'slug' => 'services-valkey',
            'driver' => 'redis',
            'node_id' => $this->dbNode->id,
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'port' => 6380,
            'username' => $username,
            'password' => VALKEY_ACL_ADMIN_SECRET,
        ]);

        $this->deleteJson('/api/v1/database-connections/services-valkey')->assertOk();

        expect($this->valkey->calls)->toBe([])
            ->and(DatabaseConnection::query()->exists())->toBeFalse();
    })->with(['no username' => [null], 'default' => ['default']]);

    it('keeps the record when the user cannot be deleted', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();
        $this->valkey->failCommands = true;

        $this->deleteJson('/api/v1/database-connections/gateway-queue')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_command_failed');

        expect(DatabaseConnection::query()->where('slug', 'gateway-queue')->exists())->toBeTrue()
            ->and($this->valkey->users)->toHaveKey('gateway-queue');
    });
});

describe('database:update on a Valkey server', function (): void {
    it('refuses changing the managed user or endpoint without changing either record or ACL', function (array $change): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();
        $before = DatabaseConnection::query()->sole()->getAttributes();
        $this->valkey->calls = [];

        $this->patchJson('/api/v1/database-connections/gateway-queue', $change)
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_connection_managed');

        expect(DatabaseConnection::query()->sole()->getAttributes())->toBe($before)
            ->and($this->valkey->calls)->toBe([]);
    })->with([
        'username' => [['username' => 'another-user']],
        'password' => [['password' => 'replacement']],
        'host' => [['host' => '10.44.0.99']],
        'port' => [['port' => 6379]],
        'driver' => [['driver' => 'mysql', 'database' => 'example']],
    ]);

    it('allows changing the database index while keeping the managed identity', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request())->assertCreated();
        $password = DatabaseConnection::query()->sole()->password;
        $this->valkey->calls = [];

        $this->patchJson('/api/v1/database-connections/gateway-queue', ['database' => '2'])
            ->assertOk()
            ->assertJsonPath('data.database', '2');

        expect(DatabaseConnection::query()->sole()->password)->toBe($password)
            ->and($this->valkey->calls)->toBe(['config get databases']);
    });
});

describe('Valkey database indexes', function (): void {
    it('accepts the last configured database and refuses the next before creating a user', function (): void {
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['database' => 16]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($this->valkey->users)->toBe([])->and(DatabaseConnection::query()->exists())->toBeFalse();
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['database' => 15]))
            ->assertCreated()->assertJsonPath('data.database', '15');
        $this->patchJson('/api/v1/database-connections/gateway-queue', ['database' => '16'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(DatabaseConnection::query()->sole()->database)->toBe('15');
        $this->patchJson('/api/v1/database-connections/gateway-queue', ['database' => 'named'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    });

    it('uses the adopted server configuration rather than a fixed limit', function (): void {
        $this->valkey->databases = 32;
        $this->postJson('/api/v1/database-connections', valkey_acl_user_request(['database' => 31]))->assertCreated();
    });
});
