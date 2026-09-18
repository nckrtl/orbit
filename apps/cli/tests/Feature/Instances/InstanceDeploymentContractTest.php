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

describe('instance deployment contract', function (): void {
    it('renders instance:deployment:list from the recorded response', function (): void {
        run_contract('instances/instance-deployment-list/default', 'instance:deployment:list', ['instance' => '1'], 'instances/instance-deployment-list/default.human.txt', 0);
        run_contract('instances/instance-deployment-list/default', 'instance:deployment:list', ['instance' => '1', '--json' => true], 'instances/instance-deployment-list/default.json', 0);
    });

    it('renders instance:deployment:show from the recorded response', function (): void {
        run_contract('instances/instance-deployment-show/default', 'instance:deployment:show', ['deployment' => '1'], 'instances/instance-deployment-show/default.human.txt', 0);
        run_contract('instances/instance-deployment-show/default', 'instance:deployment:show', ['deployment' => '1', '--json' => true], 'instances/instance-deployment-show/default.json', 0);
    });
});
