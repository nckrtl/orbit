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
 * @param  string|list<string>  $fixtures
 * @param  array<string, mixed>  $arguments
 */
function run_project_contract(string|array $fixtures, string $command, array $arguments, string $expected, int $exitCode): void
{
    // A global mock keeps its first responses, so replace it for every replay.
    MockClient::destroyGlobal();
    MockClient::global(gateway_fixture_mock(...(array) $fixtures));

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}

describe('project contract', function (): void {
    it('renders project:list from the recorded response', function (): void {
        run_project_contract('projects/project-list/default', 'project:list', [], 'projects/project-list/default.human.txt', 0);
        run_project_contract('projects/project-list/default', 'project:list', ['--json' => true], 'projects/project-list/default.json', 0);
    });

    it('renders project:list with several projects', function (): void {
        run_project_contract('projects/project-list/several', 'project:list', [], 'projects/project-list/several.human.txt', 0);
        run_project_contract('projects/project-list/several', 'project:list', ['--json' => true], 'projects/project-list/several.json', 0);
        run_project_contract(['projects/project-show/charlie-shop', 'instances/instance-list/charlie-shop'], 'project:show', ['project' => '3'], 'projects/project-show/charlie-shop.human.txt', 0);
    });

    it('renders project:show from the recorded response', function (): void {
        run_project_contract(['projects/project-show/default', 'instances/instance-list/default'], 'project:show', ['project' => '1'], 'projects/project-show/default.human.txt', 0);
        run_project_contract('projects/project-show/default', 'project:show', ['project' => '1', '--json' => true], 'projects/project-show/default.json', 0);
    });

    it('renders a created project', function (): void {
        $arguments = ['slug' => 'acme', 'type' => 'laravel-app', 'repository' => 'git@github.com:acme/site.git', '--default-branch' => 'main', '--apps' => '[{"name":"web","path":".","web_root":"public","type":"laravel-app"}]'];
        run_project_contract('projects/project-create/created', 'project:create', $arguments, 'projects/project-create/created.human.txt', 0);
        run_project_contract('projects/project-create/created', 'project:create', [...$arguments, '--json' => true], 'projects/project-create/created.json', 0);
    });

    it('renders a removed project', function (): void {
        run_project_contract('projects/project-destroy/removed', 'project:destroy', ['project' => '1', '--yes' => true], 'projects/project-destroy/removed.human.txt', 0);
        run_project_contract('projects/project-destroy/removed', 'project:destroy', ['project' => '1', '--yes' => true, '--json' => true], 'projects/project-destroy/removed.json', 0);
    });
});
