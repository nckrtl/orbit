<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\CreateInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Requests\Deployments\UpdateInstanceDeployStepRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-deploy-steps-'.Str::uuid();
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
        CreateInstanceDeployStepRequest::class => deploy_step_mock_response(),
    ]);

    $this
        ->artisan('instance:deploy-step:create', [
            'instance' => '17',
            'name' => 'migrate',
            '--command' => 'php artisan migrate --force',
            '--phase' => 'before_activation',
            '--timeout' => '60',
            '--after' => 'cache',
            '--json' => true,
        ])
        ->expectsOutput(deploy_step_json())
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(CreateInstanceDeployStepRequest::class)
        ->and($mock->getLastRequest()?->body()->all())->toBe([
            'name' => 'migrate',
            'command' => 'php artisan migrate --force',
            'phase' => 'before_activation',
            'timeout_seconds' => 60,
            'after' => 'cache',
        ]);
});

it('lists deploy steps in the stored order', function (): void {
    MockClient::global([
        ListInstanceDeployStepsRequest::class => MockResponse::make([
            'data' => [deploy_step_payload(), [
                'name' => 'optimize',
                'phase' => 'after_activation',
                'command' => 'php artisan optimize',
                'timeout_seconds' => 45,
            ]],
            'meta' => ['request_id' => deploy_step_request_id()],
        ]),
    ]);

    expect(Artisan::call('instance:deploy-step:list', ['instance' => '17']))->toBe(0);
    expect(instance_source_text(Artisan::output()))->toContain(
        'NAME PHASE COMMAND TIMEOUT (SECONDS)',
        'migrate before_activation php artisan migrate --force 300',
        'optimize after_activation php artisan optimize 45',
    );
});

it('updates and destroys a deploy step by name', function (): void {
    $mock = MockClient::global([
        UpdateInstanceDeployStepRequest::class => deploy_step_mock_response(),
        DestroyInstanceDeployStepRequest::class => deploy_step_mock_response(),
    ]);

    $this
        ->artisan('instance:deploy-step:update', [
            'instance' => '17',
            'name' => 'migrate',
            '--command' => 'php artisan migrate --force',
            '--json' => true,
        ])
        ->expectsOutput(deploy_step_json())
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(UpdateInstanceDeployStepRequest::class)
        ->and($mock->getLastRequest()?->body()->all())->toBe([
            'command' => 'php artisan migrate --force',
        ]);

    $this
        ->artisan('instance:deploy-step:destroy', ['--yes' => true,
            'instance' => '17',
            'name' => 'migrate',
            '--json' => true,
        ])
        ->expectsOutput(deploy_step_json())
        ->assertExitCode(0);

    expect($mock->getLastRequest())->toBeInstanceOf(DestroyInstanceDeployStepRequest::class);
});

/** @return array{name: string, phase: string, command: string, timeout_seconds: int} */
function deploy_step_payload(): array
{
    return [
        'name' => 'migrate',
        'phase' => 'before_activation',
        'command' => 'php artisan migrate --force',
        'timeout_seconds' => 300,
    ];
}

function deploy_step_mock_response(): MockResponse
{
    return MockResponse::make([
        'data' => deploy_step_payload(),
        'meta' => ['request_id' => deploy_step_request_id()],
    ]);
}

function deploy_step_json(): string
{
    return json_encode([
        ...deploy_step_payload(),
        'request_id' => deploy_step_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function deploy_step_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
