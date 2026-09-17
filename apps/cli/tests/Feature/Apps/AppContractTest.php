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
    putenv('COLUMNS=120');
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
function run_app_contract(string $fixture, string $command, array $arguments, string $expected, int $exitCode): void
{
    // A global mock keeps its first responses, so replace it for every replay.
    MockClient::destroyGlobal();
    MockClient::global(gateway_fixture_mock($fixture));

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}

describe('app contract', function (): void {
    it('renders app:list from the recorded response', function (): void {
        run_app_contract('apps/app-list/default', 'app:list', [], 'apps/app-list/default.human.txt', 0);
        run_app_contract('apps/app-list/default', 'app:list', ['--json' => true], 'apps/app-list/default.json', 0);
    });

    it('renders app:list with several apps', function (): void {
        run_app_contract('apps/app-list/several', 'app:list', [], 'apps/app-list/several.human.txt', 0);
        run_app_contract('apps/app-list/several', 'app:list', ['--json' => true], 'apps/app-list/several.json', 0);
        run_app_contract('apps/app-show/charlie-shop', 'app:show', ['app' => '3'], 'apps/app-show/charlie-shop.human.txt', 0);
    });

    it('renders app:show from the recorded response', function (): void {
        run_app_contract('apps/app-show/default', 'app:show', ['app' => '1'], 'apps/app-show/default.human.txt', 0);
        run_app_contract('apps/app-show/default', 'app:show', ['app' => '1', '--json' => true], 'apps/app-show/default.json', 0);
    });

    it('renders a created app', function (): void {
        $arguments = ['slug' => 'acme', 'repository' => 'git@github.com:acme/site.git', '--default-branch' => 'main'];
        run_app_contract('apps/app-create/created', 'app:create', $arguments, 'apps/app-create/created.human.txt', 0);
        run_app_contract('apps/app-create/created', 'app:create', [...$arguments, '--json' => true], 'apps/app-create/created.json', 0);
    });

    it('renders a removed app', function (): void {
        run_app_contract('apps/app-destroy/removed', 'app:destroy', ['app' => '1', '--yes' => true], 'apps/app-destroy/removed.human.txt', 0);
        run_app_contract('apps/app-destroy/removed', 'app:destroy', ['app' => '1', '--yes' => true, '--json' => true], 'apps/app-destroy/removed.json', 0);
    });
});
