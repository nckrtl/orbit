<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Projects\CreateProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Requests\Projects\DestroyProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Requests\Projects\ListProjectDevelopmentDeployStepsRequest;
use Orbit\Sdk\Requests\Projects\UpdateProjectDevelopmentDeployStepRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-development-steps-'.Str::uuid();
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

it('creates a deploy step with command phase timeout and placement', function (): void {
    $mock = MockClient::global([
        CreateProjectDevelopmentDeployStepRequest::class => development_step_mock_response(),
    ]);

    $this
        ->artisan('project:dev-deploy-step:create', [
            '--project' => '17',
            'name' => 'migrate',
            '--command' => 'php artisan migrate --force',
            '--required' => 'false',
            '--timeout' => '60',
            '--after' => 'cache',
            '--json' => true,
        ])
        ->expectsOutput(development_step_json())
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(CreateProjectDevelopmentDeployStepRequest::class)
        ->and($mock->getLastRequest()?->body()->all())->toBe([
            'name' => 'migrate',
            'command' => 'php artisan migrate --force',
            'timeout_seconds' => 60,
            'after' => 'cache',
            'required' => false,
        ]);
});

it('lists deploy steps in the stored order', function (): void {
    MockClient::global([
        ListProjectDevelopmentDeployStepsRequest::class => MockResponse::make([
            'data' => [development_step_payload(), [
                'name' => 'optimize',
                'required' => true,
                'command' => 'php artisan optimize',
                'timeout_seconds' => 45,
            ]],
            'meta' => ['request_id' => development_step_request_id()],
        ]),
    ]);

    expect(Artisan::call('project:dev-deploy-step:list', ['--project' => '17']))->toBe(0);
    expect(instance_source_text(Artisan::output()))->toContain(
        '1. migrate (300s)',
        'php artisan migrate --force',
        '2. optimize (45s)',
    );
});

it('updates and destroys a deploy step by name', function (): void {
    $mock = MockClient::global([
        UpdateProjectDevelopmentDeployStepRequest::class => development_step_mock_response(),
        DestroyProjectDevelopmentDeployStepRequest::class => development_step_mock_response(),
    ]);

    $this
        ->artisan('project:dev-deploy-step:update', [
            '--project' => '17',
            'name' => 'migrate',
            '--command' => 'php artisan migrate --force',
            '--json' => true,
        ])
        ->expectsOutput(development_step_json())
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(UpdateProjectDevelopmentDeployStepRequest::class)
        ->and($mock->getLastRequest()?->body()->all())->toBe([
            'command' => 'php artisan migrate --force',
        ]);

    $this
        ->artisan('project:dev-deploy-step:destroy', ['--yes' => true,
            '--project' => '17',
            'name' => 'migrate',
            '--json' => true,
        ])
        ->expectsOutput(development_step_json())
        ->assertExitCode(0);

    expect($mock->getLastRequest())->toBeInstanceOf(DestroyProjectDevelopmentDeployStepRequest::class);
});

/** @return array{name: string, required: bool, command: string, timeout_seconds: int} */
function development_step_payload(): array
{
    return [
        'name' => 'migrate',
        'command' => 'php artisan migrate --force',
        'timeout_seconds' => 300,
        'required' => false,
    ];
}

function development_step_mock_response(): MockResponse
{
    return MockResponse::make([
        'data' => development_step_payload(),
        'meta' => ['request_id' => development_step_request_id()],
    ]);
}

function development_step_json(): string
{
    return json_encode([
        ...development_step_payload(),
        'request_id' => development_step_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function development_step_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}

it('refuses invalid required and timeout values without sending a request', function (string $option, string $value): void {
    $mock = MockClient::global([]);
    $this->artisan('project:dev-deploy-step:create', ['--project' => '17', 'name' => 'install', '--command' => 'true', $option => $value, '--json' => true])->assertExitCode(1);
    $mock->assertNothingSent();
})->with([['--required', '0'], ['--required', 'yes'], ['--timeout', 'invalid']]);

it('updates only required while preserving explicit false', function (): void {
    $mock = MockClient::global([UpdateProjectDevelopmentDeployStepRequest::class => development_step_mock_response()]);
    $this->artisan('project:dev-deploy-step:update', ['--project' => '17', 'name' => 'migrate', '--required' => 'false', '--json' => true])->expectsOutput(development_step_json())->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['required' => false]);
});

it('omits optional fields on create so Gateway defaults apply', function (): void {
    $mock = MockClient::global([CreateProjectDevelopmentDeployStepRequest::class => development_step_mock_response()]);
    $this->artisan('project:dev-deploy-step:create', ['--project' => '17', 'name' => 'migrate', '--command' => 'true', '--json' => true])->expectsOutput(development_step_json())->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['name' => 'migrate', 'command' => 'true']);
});

it('escapes terminal controls and preserves literal formatter tags in human step results', function (bool $list): void {
    $command = "printf '<error>literal</error>'\n\t\r\x1b]52;c;cGF5bG9hZA==\x07";
    $payload = [...development_step_payload(), 'command' => $command];
    MockClient::global([
        ($list ? ListProjectDevelopmentDeployStepsRequest::class : CreateProjectDevelopmentDeployStepRequest::class) => MockResponse::make([
            'data' => $list ? [$payload] : $payload,
            'meta' => ['request_id' => development_step_request_id()],
        ]),
    ]);
    $arguments = ['--project' => '17'];
    if (! $list) {
        $arguments += ['name' => 'migrate', '--command' => $command];
    }
    expect(Artisan::call('project:dev-deploy-step:'.($list ? 'list' : 'create'), $arguments))->toBe(0);
    expect(Artisan::output())
        ->toContain("printf '<error>literal</error>'\\n\\t\\r\\u{001B}]52;c;cGF5bG9hZA==\\u{0007}")
        ->not->toContain("\x1b", "\x07", "\t", "\r");
})->with(['single' => false, 'list' => true]);

it('preserves command controls and literal formatter tags in JSON step results', function (bool $list): void {
    $command = "printf '<error>literal</error>'\n\t\r\x1b]52;c;cGF5bG9hZA==\x07";
    $payload = [...development_step_payload(), 'command' => $command];
    MockClient::global([
        ($list ? ListProjectDevelopmentDeployStepsRequest::class : CreateProjectDevelopmentDeployStepRequest::class) => MockResponse::make([
            'data' => $list ? [$payload] : $payload,
            'meta' => ['request_id' => development_step_request_id()],
        ]),
    ]);
    $arguments = ['--project' => '17', '--json' => true];
    if (! $list) {
        $arguments += ['name' => 'migrate', '--command' => $command];
    }
    expect(Artisan::call('project:dev-deploy-step:'.($list ? 'list' : 'create'), $arguments))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($list ? $result['steps'][0]['command'] : $result['command'])->toBe($command);
})->with(['single' => false, 'list' => true]);

it('requires explicit destructive consent in machine mode', function (): void {
    $mock = MockClient::global([]);
    $this->artisan('project:dev-deploy-step:destroy', ['--project' => '17', 'name' => 'migrate', '--json' => true])->assertExitCode(1);
    $mock->assertNothingSent();
});
