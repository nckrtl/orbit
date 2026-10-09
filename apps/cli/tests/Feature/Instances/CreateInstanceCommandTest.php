<?php

declare(strict_types=1);

use App\Commands\Instances\CreateInstanceCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

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

describe('process:create preset target validation', function (): void {
    it('refuses a preset with project before sending a request', function (): void {
        $mock = MockClient::global([]);

        $exitCode = Artisan::call('process:create', [
            'name' => 'assets',
            '--preset' => 'vp-dev',
            '--project' => '7',
            '--json' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('process.preset_target_invalid');
        $mock->assertNothingSent();
    });
});

describe('instance:create production refusal', function (): void {
    it('renders the candidate-required error and directs callers to instance:clone', function (): void {
        MockClient::global([
            CreateInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.candidate_required',
                    'message' => 'New production Instances require a candidate. Use instance:clone.',
                ],
            ], 409, ['X-Orbit-Request-Id' => instance_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:create', [
            'project' => '3',
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
                    'message' => 'New production Instances require a candidate. Use instance:clone.',
                    'request_id' => instance_request_id(),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->and($output)
            ->toContain('instance:clone');

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '4',
                'name' => 'production',
            ])
            ->expectsOutputToContain('New production Instances require a candidate. Use instance:clone.')
            ->assertExitCode(1);
    });

    it('documents that new production Instances use instance:clone', function (): void {
        $commands = app(Kernel::class)->all();

        expect($commands['instance:create'])
            ->toBeInstanceOf(CreateInstanceCommand::class)
            ->and($commands['instance:create']->getDescription())
            ->toBe('Create a development Instance on an app-dev Node.')
            ->and($commands['instance:create']->getHelp())
            ->toContain('New production Instances require a candidate. Use instance:clone.');
    });
});

describe('instance:create database server', function (): void {
    it('sends --database-server as database_server', function (): void {
        $mock = MockClient::global([
            CreateInstanceRequest::class => instance_mock_response(201),
        ]);

        $exitCode = Artisan::call('instance:create', [
            'project' => '3',
            'node' => '4',
            'name' => 'default',
            '--database-server' => 'beast-mysql',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(0);
        $mock->assertSent(static fn (CreateInstanceRequest $request): bool => $request->body()->all() === [
            'project_id' => 3,
            'node_id' => 4,
            'name' => 'default',
            'database_server' => 'beast-mysql',
        ]);
    });
});

describe('instance registration project options', function (): void {
    it('rejects the removed Project creation options and the app name option', function (): void {
        app()->instance(GitRegistrationDiscovery::class, new class implements GitRegistrationDiscovery
        {
            public function inspect(string $path): ?GitRegistrationFacts
            {
                return new GitRegistrationFacts(
                    path: '/work/acme',
                    repositoryUrl: 'git@github.com:acme/acme.git',
                );
            }
        });

        foreach (['--project-name', '--project-slug', '--default-branch'] as $option) {
            $tester = new CommandTester(app(Kernel::class)->all()['instance:register']);

            expect($tester->execute([$option => 'value', '--json' => true, '--no-interaction' => true], ['interactive' => false]))
                ->toBe(1)
                ->and(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'])
                ->toBe('input.invalid');
        }

        MockClient::destroyGlobal();
        $rejected = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:register']);

        expect($tester->execute([
            '--app-name' => 'Legacy',
            '--json' => true,
            '--no-interaction' => true,
        ], ['interactive' => false]))->toBe(1);
        expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.invalid',
                'message' => 'The "--app-name" option does not exist.',
                'request_id' => null,
            ],
        ]);
        expect($rejected->getLastPendingRequest())->toBeNull();
    });
});
