<?php

declare(strict_types=1);

use App\Actions\Instances\CloneInstanceDatabaseAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\LifecycleSshExecutor;

const DATABASE_CLONE_ROOT_SECRET = 'clone-root-secret-77ab';

beforeEach(function (): void {
    app()->instance(InstanceDestinationGuard::class, new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    });
    $this->configuration = new class implements DevelopmentInstanceConfigurator
    {
        public bool $laravel = true;

        public function inspect(Instance $instance): DevelopmentSourceProfile
        {
            return new DevelopmentSourceProfile('8.5', $this->laravel);
        }

        public function configureLaravelUrl(Instance $instance, string $url): void {}
    };
    app()->instance(DevelopmentInstanceConfigurator::class, $this->configuration);
    app()->instance(DevelopmentRouteProjector::class, new class implements DevelopmentRouteProjector
    {
        public function converge(Instance $instance, Route $route): void {}
    });
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(DevelopmentInstanceSourceLifecycle::class, new class implements DevelopmentInstanceSourceLifecycle
    {
        public function prepare(Instance $instance, bool $allowExisting): void {}

        public function inspectPrepared(Instance $instance): void {}

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution($instance->branch_override ?? $instance->name, str_repeat('a', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            return $this->resolve($instance);
        }
    });
    $removal = new DatabaseCloneRemovalFakes;
    app()->instance(DevelopmentInstanceSourceRemoval::class, $removal);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $removal);
    app()->instance(InstanceRemovalProjector::class, $removal);
    $this->environment = new DatabaseCloneEnvironmentFakes;
    app()->instance(InstanceOperationPreflight::class, $this->environment);
    app()->instance(InstanceEnvironmentReader::class, $this->environment);
    app()->instance(InstanceEnvironmentWriter::class, $this->environment);
    app()->instance(InstanceTestEnvironmentWriter::class, $this->environment);
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);
    $this->sqlite = new DatabaseCloneSqliteFake;
    app()->instance(InstanceSqliteCloner::class, $this->sqlite);

    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->default = Instance::query()->create([
        'project_id' => $this->project->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
});

/** Attach a source connection to the default Instance under prefix DB. */
function database_clone_source(Instance $default, array $attributes): DatabaseConnection
{
    $connection = DatabaseConnection::query()->create(['slug' => 'acme-default', ...$attributes]);
    DatabaseConnectionTarget::query()->create([
        'instance_id' => $default->id,
        'database_connection_id' => $connection->id,
        'prefix' => 'DB',
    ]);

    return $connection;
}

function database_clone_server(Node $node): DatabaseServer
{
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'beast-mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => [], 'volumes' => []],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return DatabaseServer::query()->create([
        'slug' => 'beast-mysql',
        'node_id' => $node->id,
        'process_id' => $process->id,
        'tag' => '8.4',
        'port' => 3306,
        'root_password' => DATABASE_CLONE_ROOT_SECRET,
        'status' => LifecycleStatus::Active,
    ]);
}

/** @return array<string, mixed> */
function database_clone_request(Project $project, Node $node, string $name = 'feature-x'): array
{
    return ['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name];
}

/** @return array<string, string> */
function database_clone_env(string $contents): array
{
    $values = [];

    foreach (array_filter(explode("\n", $contents)) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = trim($value, '"');
    }

    return $values;
}

describe('instance:create database clone', function (): void {
    it('copies a MySQL default database into the new Instance before setup and writes .env.testing', function (): void {
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'node_id' => $this->node->id,
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'port' => 3306,
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
        ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'name' => 'migrate', 'command' => 'php artisan migrate', 'timeout_seconds' => 30, 'position' => 0]);
        $copiesAtSetup = null;
        $transport = new LifecycleSshExecutor(result: function () use (&$copiesAtSetup): int {
            $copiesAtSetup = count($this->admin->copies);

            return 0;
        });
        app()->instance(ProjectLifecycleRunner::class, $transport->runner());

        $response = $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();

        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $clone = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->sole();
        $password = (string) $clone->password;
        $env = database_clone_env((string) $this->environment->contents);
        $testing = database_clone_env((string) $this->environment->testingContents);

        expect($copiesAtSetup)->toBe(1)
            ->and($this->admin->copies)->toBe([['source' => 'acme_default', 'target' => 'acme_feature_x']])
            ->and($clone->slug)->toBe('acme-feature-x')
            ->and($clone->database)->toBe('acme_feature_x')
            ->and($clone->username)->toBe('acme_feature_x')
            ->and($clone->test_database)->toBe('acme_feature_x_test')
            ->and($clone->database_server_id)->toBe($server->id)
            ->and($this->admin->sql())
            ->toContain('CREATE DATABASE `acme_feature_x`;')
            ->toContain('CREATE DATABASE `acme_feature_x_test`;')
            ->toContain("GRANT ALL PRIVILEGES ON `acme\\_feature\\_x\\_test%`.* TO 'acme_feature_x'@'%';")
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->sole()->database_connection_id)->toBe($clone->id)
            ->and($this->environment->reads)->toBe(1)
            ->and($env['APP_URL'])->toStartWith('https://feature-x.')
            ->and($env)->toMatchArray([
                'APP_KEY' => 'base64:kept',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'acme_feature_x',
                'DB_USERNAME' => 'acme_feature_x',
                'DB_PASSWORD' => $password,
            ])
            ->and($testing)->toMatchArray([
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:kept',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'acme_feature_x_test',
                'DB_HOST' => '10.44.0.3',
                'DB_PASSWORD' => $password,
                'DB_PORT' => '3306',
                'DB_USERNAME' => 'acme_feature_x',
            ])
            ->and($this->environment->testingManagedKeys)->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
            ->and($response->getContent())->not->toContain($password)->not->toContain(DATABASE_CLONE_ROOT_SECRET);
    });

    it('points the copy at the server\'s WireGuard address over the imported .env and an older MySQL Process', function (): void {
        Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->node->id,
            'name' => 'mysql-84',
            'runtime' => ProcessRuntime::Docker,
            'working_directory' => '/app',
            'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => ['3308:3306'], 'volumes' => []],
            'restart_policy' => 'unless-stopped',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'node_id' => $this->node->id,
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'port' => 3306,
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
        $this->environment->source = "APP_KEY=base64:kept\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=13306\n";

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();

        $endpoint = ['DB_HOST' => '10.44.0.3', 'DB_PORT' => '3306'];

        expect(database_clone_env((string) $this->environment->contents))->toMatchArray($endpoint)
            ->and(database_clone_env((string) $this->environment->testingContents))->toMatchArray($endpoint);
    });

    it('copies a SQLite default database into the same relative path of the new checkout', function (): void {
        database_clone_source($this->default, [
            'driver' => 'sqlite',
            'node_id' => $this->node->id,
            'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
        ]);

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();

        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $clone = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->sole();

        expect($this->sqlite->copies)->toBe([[
            'source' => 'default',
            'source_path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
            'target' => 'feature-x',
            'target_path' => '/srv/orbit/apps/acme/feature-x/database/database.sqlite',
        ]])
            ->and($clone->driver->value)->toBe('sqlite')
            ->and($clone->node_id)->toBe($this->node->id)
            ->and($clone->path)->toBe('/srv/orbit/apps/acme/feature-x/database/database.sqlite')
            ->and($clone->test_database)->toBe(':memory:')
            ->and(database_clone_env((string) $this->environment->contents)['DB_DATABASE'])->toBe('/srv/orbit/apps/acme/feature-x/database/database.sqlite')
            ->and(database_clone_env((string) $this->environment->testingContents))->toMatchArray([
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:kept',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
            ])
            ->and(database_clone_env((string) $this->environment->testingContents))->not->toHaveKey('DB_HOST');
    });

    it('creates no copy without a DB attachment on the default Instance or for the default Instance itself', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();

        expect(DatabaseConnection::query()->exists())->toBeFalse()
            ->and($this->environment->contents)->toBeNull();

        database_clone_source($this->default, [
            'driver' => 'sqlite',
            'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
        ]);

        expect(app(InstanceDatabaseClonePlanner::class)->plan($this->project, 'default'))->toBeNull();
        expect($this->sqlite->copies)->toBe([]);
    });

    it('refuses a source that Orbit cannot copy before anything changes', function (array $source): void {
        database_clone_source($this->default, $source);

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.database_clone_unsupported');

        expect(Instance::query()->where('name', 'feature-x')->exists())->toBeFalse()
            ->and($this->admin->statements)->toBe([])
            ->and($this->sqlite->copies)->toBe([]);
    })->with([
        'mysql without a server' => [['driver' => 'mysql', 'host' => 'db.example.test', 'database' => 'acme', 'username' => 'acme', 'password' => 'x']],
        'sqlite outside the checkout' => [['driver' => 'sqlite', 'path' => '/var/lib/acme/database.sqlite']],
        'sqlite escaping the checkout' => [['driver' => 'sqlite', 'path' => '/srv/orbit/apps/acme/default/../other/database.sqlite']],
        'pgsql' => [['driver' => 'pgsql', 'host' => 'db.example.test', 'database' => 'acme', 'username' => 'acme', 'password' => 'x']],
    ]);

    it('removes the Instance and drops the partial copy when the copy fails', function (): void {
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
        $this->admin->failCopy = true;

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'instance.database_clone_failed');

        expect(Instance::query()->where('name', 'feature-x')->exists())->toBeFalse()
            ->and(DatabaseConnection::query()->where('slug', 'acme-feature-x')->exists())->toBeFalse()
            ->and($this->admin->sql())
            ->toContain('DROP DATABASE IF EXISTS `acme_feature_x`;')
            ->toContain('DROP DATABASE IF EXISTS `acme_feature_x_test`;')
            ->toContain("DROP USER IF EXISTS 'acme_feature_x'@'%';");
    });

    it('returns an existing copy on a repeated clone', function (): void {
        database_clone_source($this->default, [
            'driver' => 'sqlite',
            'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
        ]);
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $plan = app(InstanceDatabaseClonePlanner::class)->plan($this->project, 'feature-x');

        expect($plan)->not->toBeNull();

        $again = app(CloneInstanceDatabaseAction::class)->execute($instance, $plan);

        expect($again->owner_instance_id)->toBe($instance->id)
            ->and($this->sqlite->copies)->toHaveCount(1)
            ->and(DatabaseConnection::query()->where('owner_instance_id', $instance->id)->count())->toBe(1);
    });
});

describe('interrupted clones', function (): void {
    beforeEach(function (): void {
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
        ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'name' => 'migrate', 'command' => 'php artisan migrate', 'timeout_seconds' => 30, 'position' => 0]);
        $this->transport = new LifecycleSshExecutor;
        app()->instance(ProjectLifecycleRunner::class, $this->transport->runner());
    });

    /** Leave the Instance as a create that stopped at `$step`: active, setup pending, copy unfinished. */
    function interrupt_database_clone(string $step): Instance
    {
        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $clone = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->sole();
        DatabaseConnectionTarget::query()->where('database_connection_id', $clone->id)->delete();
        $clone->update(['clone_step' => $step]);
        $instance->update(['failed_step' => 'setup']);

        return $instance;
    }

    it('drops and copies again a database that was being filled, then runs setup', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $password = DatabaseConnection::query()->where('slug', 'acme-feature-x')->sole()->password;
        $instance = interrupt_database_clone('recorded');
        $this->admin->statements = [];
        $this->admin->copies = [];

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertOk();

        $clone = DatabaseConnection::query()->where('slug', 'acme-feature-x')->sole();

        expect($this->admin->sql())->toBe(implode("\n", [
            "CREATE USER IF NOT EXISTS 'acme_feature_x'@'%' IDENTIFIED BY '{$password}';",
            "ALTER USER 'acme_feature_x'@'%' IDENTIFIED BY '{$password}';",
            'DROP DATABASE IF EXISTS `acme_feature_x`;',
            'DROP DATABASE IF EXISTS `acme_feature_x_test`;',
            'CREATE DATABASE `acme_feature_x`;',
            "GRANT ALL PRIVILEGES ON `acme_feature_x`.* TO 'acme_feature_x'@'%';",
            'CREATE DATABASE `acme_feature_x_test`;',
            "GRANT ALL PRIVILEGES ON `acme\\_feature\\_x\\_test%`.* TO 'acme_feature_x'@'%';",
        ])."\n")
            ->and($this->admin->copies)->toBe([['source' => 'acme_default', 'target' => 'acme_feature_x']])
            ->and($clone->password)->toBe($password)
            ->and($clone->clone_step?->value)->toBe('complete')
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->sole()->database_connection_id)->toBe($clone->id)
            ->and($instance->refresh()->failed_step)->toBeNull()
            ->and($this->transport->inputs)->toHaveCount(2);
    });

    it('keeps a finished copy and only attaches and synchronizes it on a retry', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = interrupt_database_clone('filled');
        $this->admin->statements = [];
        $this->admin->copies = [];
        $this->environment->contents = null;

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertOk();

        expect($this->admin->statements)->toBe([])
            ->and($this->admin->copies)->toBe([])
            ->and($this->environment->contents)->toContain('DB_DATABASE="acme_feature_x"')
            ->and(DatabaseConnection::query()->where('slug', 'acme-feature-x')->sole()->clone_step?->value)->toBe('complete')
            ->and($instance->refresh()->failed_step)->toBeNull();
    });

    it('still points a retry with a finished copy and pending setup to instance:setup', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        Instance::query()->where('name', 'feature-x')->sole()->update(['failed_step' => 'setup']);

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.setup_step_failed');

        expect($this->admin->copies)->toHaveCount(1);
    });
});

describe('environment import before the first synchronization', function (): void {
    beforeEach(function (): void {
        $this->configuration->laravel = false;
        database_clone_source($this->default, [
            'driver' => 'sqlite',
            'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
        ]);
    });

    it('imports the existing .env of a non-Laravel Instance so its other keys stay', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();

        expect(database_clone_env((string) $this->environment->contents))->toMatchArray([
            'APP_KEY' => 'base64:kept',
            'APP_URL' => 'http://localhost',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/srv/orbit/apps/acme/feature-x/database/database.sqlite',
        ]);
    });

    it('writes only the stored keys when the Instance has no .env', function (): void {
        $this->environment->missing = true;

        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();

        expect(array_keys(database_clone_env((string) $this->environment->contents)))->toBe(['DB_CONNECTION', 'DB_DATABASE'])
            ->and($this->environment->reads)->toBe(1);
    });
});

describe('.env.testing', function (): void {
    it('writes no .env.testing for a DB database the Instance only has attached', function (): void {
        database_clone_source($this->default, [
            'driver' => 'sqlite',
            'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
        ]);
        $route = Route::query()->create([
            'project_id' => $this->project->id,
            'node_id' => $this->node->id,
            'domain' => 'default.acme.test',
            'provenance' => 'explicit',
            'publication' => 'private',
            'status' => 'pending',
        ]);
        $route->targets()->create(['instance_id' => $this->default->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $this->default->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'base64:default']);

        app(SynchronizeInstanceEnvironmentAction::class)->execute($this->default);

        expect($this->environment->contents)->not->toBeNull()
            ->and($this->environment->testingContents)->toBeNull();
    });
});

describe('.env.testing on synchronization', function (): void {
    beforeEach(function (): void {
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'node_id' => $this->node->id,
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'port' => 3306,
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
    });

    // The writer seeds a missing file with these contents and merges only the managed keys into an existing one.
    it('sends the full .env.testing seed with the test database and records the test database', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $this->environment->testingContents = null;

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertOk()
            ->assertJsonMissingPath('data.testing');

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect(database_clone_env((string) $this->environment->testingContents))->toMatchArray([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:kept',
            'DB_DATABASE' => 'acme_feature_x_test',
            'DB_HOST' => '10.44.0.3',
        ])
            ->and($this->environment->testingManagedKeys)->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
            ->and($activity->properties?->toArray()['testing'] ?? null)->toBe([
                'file' => '.env.testing',
                'status' => 'written',
                'test_database' => 'acme_feature_x_test',
            ]);
    });

    it('never writes a .env.testing that Git tracks and records the test database instead', function (): void {
        $this->environment->testingTracked = true;
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertOk();

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($this->environment->testingContents)->toBeNull()
            ->and($this->environment->contents)->not->toBeNull()
            ->and($activity->properties?->toArray()['testing'] ?? null)->toBe([
                'file' => '.env.testing',
                'status' => 'skipped_tracked',
                'test_database' => 'acme_feature_x_test',
            ]);
    });
});

describe('instance:destroy owned databases', function (): void {
    it('drops the databases the Instance owns and keeps an attached database it does not own', function (): void {
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $shared = DatabaseConnection::query()->create(['slug' => 'shared-cache', 'driver' => 'redis', 'host' => '10.44.0.3']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $shared->id, 'prefix' => 'REDIS']);
        $this->admin->statements = [];
        $this->admin->rows = ["LIKE 'acme\\\\_feature\\\\_x\\\\_test%'" => ['acme_feature_x_test']];

        $this->deleteJson("/api/v1/instances/{$instance->id}")->assertOk();

        expect(Instance::query()->whereKey($instance->id)->exists())->toBeFalse()
            ->and(DatabaseConnection::query()->where('slug', 'acme-feature-x')->exists())->toBeFalse()
            ->and(DatabaseConnection::query()->where('slug', 'shared-cache')->exists())->toBeTrue()
            ->and(DatabaseConnection::query()->where('slug', 'acme-default')->exists())->toBeTrue()
            ->and(end($this->admin->statements))->toBe(implode("\n", [
                'DROP DATABASE IF EXISTS `acme_feature_x`;',
                'DROP DATABASE IF EXISTS `acme_feature_x_test`;',
                "DROP USER IF EXISTS 'acme_feature_x'@'%';",
            ])."\n");
    });

    it('keeps the member in runtime cleanup when a drop fails and finishes on retry', function (): void {
        database_clone_source($this->default, [
            'driver' => 'sqlite',
            'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
        ]);
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $this->sqlite->failRemove = true;

        $this->deleteJson("/api/v1/instances/{$instance->id}")->assertStatus(502);

        expect(InstanceRemovalMember::query()->where('instance_id', $instance->id)->sole()->runtime_cleaned_at)->toBeNull()
            ->and(DatabaseConnection::query()->where('owner_instance_id', $instance->id)->exists())->toBeTrue();

        $this->sqlite->failRemove = false;
        $this->deleteJson("/api/v1/instances/{$instance->id}")->assertOk();

        expect($this->sqlite->removed)->toBe(['/srv/orbit/apps/acme/feature-x/database/database.sqlite'])
            ->and(DatabaseConnection::query()->where('slug', 'acme-feature-x')->exists())->toBeFalse();
    });
});

it('runs Project setup after the database clone without an automatic dependency copy', function (): void {
    database_clone_source($this->default, [
        'driver' => 'sqlite', 'node_id' => $this->node->id,
        'path' => '/srv/orbit/apps/acme/default/database/database.sqlite',
    ]);
    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'name' => 'install', 'command' => 'composer install', 'timeout_seconds' => 30, 'position' => 0]);
    $clonesAtSetup = null;
    $transport = new LifecycleSshExecutor(result: function () use (&$clonesAtSetup): int {
        $clonesAtSetup = count($this->sqlite->copies);

        return 0;
    });
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
    expect($clonesAtSetup)->toBe(1);
});

final class DatabaseCloneSqliteFake implements InstanceSqliteCloner
{
    /** @var list<array{source: string, source_path: string, target: string, target_path: string}> */
    public array $copies = [];

    /** @var list<string> */
    public array $removed = [];

    public bool $failRemove = false;

    public function copy(Instance $source, string $sourcePath, Instance $target, string $targetPath): void
    {
        $this->copies[] = ['source' => $source->name, 'source_path' => $sourcePath, 'target' => $target->name, 'target_path' => $targetPath];
    }

    public function remove(Instance $owner, string $path): void
    {
        if ($this->failRemove) {
            throw new ResourceOperationException('instance.database_clone_failed', 'The SQLite database could not be removed.', 502);
        }

        $this->removed[] = $path;
    }
}

final class DatabaseCloneEnvironmentFakes implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight, InstanceTestEnvironmentWriter
{
    public ?string $contents = null;

    public ?string $testingContents = null;

    /** @var list<string> */
    public array $testingManagedKeys = [];

    public bool $testingTracked = false;

    public int $reads = 0;

    public bool $missing = false;

    public string $source = "APP_KEY=base64:kept\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\n";

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}

    public function read(InstanceEnvironmentContext $context): string
    {
        $this->reads++;

        if ($this->missing) {
            throw new ResourceOperationException('env.import_source_missing', 'The recorded Instance environment file does not exist.', 404);
        }

        return $this->source;
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        $this->contents = $contents;

        return InstanceEnvironmentWriteResult::changed();
    }

    public function mergeTesting(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents, array $managedKeys): InstanceEnvironmentWriteResult
    {
        $this->testingManagedKeys = $managedKeys;

        if ($this->testingTracked) {
            return InstanceEnvironmentWriteResult::tracked();
        }

        $this->testingContents = $contents;

        return InstanceEnvironmentWriteResult::changed();
    }
}

final class DatabaseCloneRemovalFakes implements DevelopmentInstanceSourceFinalizer, DevelopmentInstanceSourceRemoval, InstanceRemovalProjector
{
    public function inspect(Instance $instance, bool $force, bool $inspectContent = true): InstanceSourceInventory
    {
        $payload = ['instance_id' => $instance->id, 'checkout_path' => $instance->checkout_path];

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: dirname(dirname($instance->checkout_path)),
            branch: $instance->branch,
            startingCommit: $instance->starting_commit,
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: "test:{$instance->id}",
            linkedWorktreePaths: [$instance->checkout_path],
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function remove(Instance $instance, InstanceSourceInventory $inventory, bool $force): void {}

    public function prepare(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): void {}

    public function revalidate(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): InstanceSourceRevalidationState
    {
        return $member->source_finalized_at === null ? InstanceSourceRevalidationState::Present : InstanceSourceRevalidationState::Completed;
    }

    public function inspectRecorded(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceInventory {
        $instance = Instance::query()->with('project')->findOrFail($member->instance_id);

        return $this->inspect($instance, true);
    }

    public function finalize(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): string
    {
        return hash('sha256', "receipt\0{$member->source_digest}");
    }

    public function clearRouteTarget(InstanceRemovalMember $member): string
    {
        $route = Route::query()->find($member->route_id);

        if (! $route instanceof Route) {
            return 'deleted';
        }

        $route->targets()->where('instance_id', $member->instance_id)->delete();
        $route->delete();

        return 'deleted';
    }

    public function withdrawPhpPool(InstanceRemovalMember $member): void {}

    public function cleanupRuntime(InstanceRemovalMember $member): void {}
}
