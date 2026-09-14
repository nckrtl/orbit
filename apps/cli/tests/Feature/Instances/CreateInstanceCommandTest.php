<?php

declare(strict_types=1);

use App\Commands\Instances\CreateInstanceCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-create-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance:create development contract', function (): void {
    it('creates a development AppInstance through the ordinary create request', function (): void {
        $mock = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--json' => true,
            ])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(CreateAppInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())
            ->toBe(['app_id' => 3, 'node_id' => 2, 'name' => 'dev'])
            ->and($mock->getLastRequest()?->body()->all())
            ->not->toHaveKey('environment');
    });
});

describe('instance:create production refusal', function (): void {
    it('renders the candidate-required error and directs callers to instance:clone', function (): void {
        MockClient::global([
            CreateAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.candidate_required',
                    'message' => 'New production AppInstances require a candidate. Use instance:clone.',
                ],
            ], 409, ['X-Orbit-Request-Id' => instance_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:create', [
            'app' => '3',
            'node' => '4',
            'name' => 'production',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        $output = trim(Artisan::output());

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toBe(json_encode([
                'error' => [
                    'code' => 'instance.candidate_required',
                    'message' => 'New production AppInstances require a candidate. Use instance:clone.',
                    'request_id' => instance_request_id(),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->and($output)
            ->toContain('instance:clone');

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '4',
                'name' => 'production',
            ])
            ->expectsOutputToContain('New production AppInstances require a candidate. Use instance:clone.')
            ->assertExitCode(1);
    });

    it('documents that new production AppInstances use instance:clone', function (): void {
        $commands = app(Kernel::class)->all();

        expect($commands['instance:create'])
            ->toBeInstanceOf(CreateInstanceCommand::class)
            ->and($commands['instance:create']->getDescription())
            ->toBe('Create a development AppInstance on an app-dev Node.')
            ->and($commands['instance:create']->getHelp())
            ->toContain('New production AppInstances require a candidate. Use instance:clone.');
    });
});
