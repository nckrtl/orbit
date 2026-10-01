<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

/**
 * Contract tests replay recorded Gateway responses and compare the complete command
 * output with tests/Expected. A fixture change that reaches a command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=160');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('database server contract', function (): void {
    it('renders database:server:create from the recorded response', function (): void {
        run_contract('database-servers/database-server-create/default', 'database:server:create', ['slug' => 'beast-mysql', '--node' => '2'], 'database-servers/database-server-create/default.human.txt', 0);
        run_contract('database-servers/database-server-create/default', 'database:server:create', ['slug' => 'beast-mysql', '--node' => '2', '--json' => true], 'database-servers/database-server-create/default.json', 0);
    });

    it('renders database:server:list from the recorded response', function (): void {
        run_contract('database-servers/database-server-list/default', 'database:server:list', [], 'database-servers/database-server-list/default.human.txt', 0);
        run_contract('database-servers/database-server-list/default', 'database:server:list', ['--json' => true], 'database-servers/database-server-list/default.json', 0);
    });

    it('renders database:server:show from the recorded response', function (): void {
        run_contract('database-servers/database-server-show/default', 'database:server:show', ['slug' => 'beast-mysql'], 'database-servers/database-server-show/default.human.txt', 0);
        run_contract('database-servers/database-server-show/default', 'database:server:show', ['slug' => 'beast-mysql', '--json' => true], 'database-servers/database-server-show/default.json', 0);
    });

    it('renders database:create --server from the recorded response', function (): void {
        run_contract('database-connections/database-create/server', 'database:create', ['slug' => 'dlf-leden', '--server' => 'beast-mysql', '--instance' => '1'], 'database-connections/database-create/server.human.txt', 0);
        run_contract('database-connections/database-create/server', 'database:create', ['slug' => 'dlf-leden', '--server' => 'beast-mysql', '--instance' => '1', '--json' => true], 'database-connections/database-create/server.json', 0);
    });

    it('renders database:user:create from the recorded response', function (): void {
        run_contract('database-connections/database-user-create/default', 'database:user:create', ['slug' => 'dlf-leden', '--username' => 'reporting', '--password' => 'reporting-secret', '--read-only' => true], 'database-connections/database-user-create/default.human.txt', 0);
        run_contract('database-connections/database-user-create/default', 'database:user:create', ['slug' => 'dlf-leden', '--username' => 'reporting', '--password' => 'reporting-secret', '--read-only' => true, '--json' => true], 'database-connections/database-user-create/default.json', 0);
    });
});
