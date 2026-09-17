<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
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

/**
 * @param  array<string, mixed>  $arguments
 */
function run_instance_contract(string $fixture, string $command, array $arguments, string $expected, int $exitCode): void
{
    MockClient::global(gateway_fixture_mock($fixture));

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}

describe('instance contract', function (): void {
    it('renders instance:list from the recorded response', function (): void {
        run_instance_contract('instances/instance-list/default', 'instance:list', [], 'instances/instance-list/default.human.txt', 0);
        run_instance_contract('instances/instance-list/default', 'instance:list', ['--json' => true], 'instances/instance-list/default.json', 0);
    });

    it('renders instance:show from the recorded response', function (): void {
        run_instance_contract('instances/instance-show/default', 'instance:show', ['instance' => '1'], 'instances/instance-show/default.human.txt', 0);
        run_instance_contract('instances/instance-show/default', 'instance:show', ['instance' => '1', '--json' => true], 'instances/instance-show/default.json', 0);
    });

    it('renders a created development instance', function (): void {
        $arguments = ['app' => '1', 'node' => '1', 'name' => 'dev'];
        run_instance_contract('instances/instance-create/created', 'instance:create', $arguments, 'instances/instance-create/created.human.txt', 0);
        run_instance_contract('instances/instance-create/created', 'instance:create', [...$arguments, '--json' => true], 'instances/instance-create/created.json', 0);
    });

    it('renders the candidate-required refusal', function (): void {
        $arguments = ['app' => '1', 'node' => '3', 'name' => 'release-name'];
        run_instance_contract('instances/instance-create/candidate-required', 'instance:create', $arguments, 'instances/instance-create/candidate-required.human.txt', 1);
        run_instance_contract('instances/instance-create/candidate-required', 'instance:create', [...$arguments, '--json' => true], 'instances/instance-create/candidate-required.json', 1);
    });
});
