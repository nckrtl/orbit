<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\GitRegistrationFacts;
use App\Services\Git\NativeGitRegistrationDiscovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->registrationGit = new class implements GitRegistrationDiscovery
    {
        public ?GitRegistrationFacts $facts;

        public function __construct()
        {
            $this->facts = new GitRegistrationFacts(
                path: '/work/acme',
                repositoryUrl: 'git@github.com:acme/acme.git',
            );
        }

        public function inspect(string $path): ?GitRegistrationFacts
        {
            return $this->facts;
        }
    };
    app()->instance(GitRegistrationDiscovery::class, $this->registrationGit);
});

describe('instance:rename', function (): void {
    it('sends the selected fields and renders the Instance in human and JSON modes', function (bool $json, array $fields): void {
        $mock = MockClient::global([RenameInstanceRequest::class => instance_mock_response()]);
        $arguments = ['instance' => 3, ...$fields];
        if ($json) {
            $arguments['--json'] = true;
        }
        expect(Artisan::call('instance:rename', $arguments))->toBe(0);
        $body = [];
        foreach ($fields as $key => $value) {
            $body[substr($key, 2)] = $value;
        }
        expect($mock->getLastRequest())->toBeInstanceOf(RenameInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe($body);
        if ($json) {
            expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('id', 5);
        } else {
            expect(instance_source_text(Artisan::output()))->toContain('Instance:', 'Selected branch');
        }
    })->with([false, true])->with([
        'branch' => [['--branch' => 't3code/login']],
        'domain' => [['--domain' => 'login.example.test']],
        'both' => [['--branch' => 't3code/login', '--domain' => 'login.example.test']],
    ]);

    it('requires a positive Instance ID and at least one field before HTTP', function (array $arguments, string $code): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:rename', [...$arguments, '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('error.code', $code)
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'invalid ID' => [['instance' => 'abc', '--branch' => 't3code/login'], 'instance.id_invalid'],
        'no fields' => [['instance' => 3], 'validation.failed'],
        'empty branch with domain' => [['instance' => 3, '--branch' => '', '--domain' => 'login.example.test'], 'validation.failed'],
        'empty domain with branch' => [['instance' => 3, '--domain' => '', '--branch' => 't3code/login'], 'validation.failed'],
    ]);

    it('preserves HEAD refusal and correlation in JSON errors', function (): void {
        MockClient::global([RenameInstanceRequest::class => MockResponse::make([
            'error' => ['code' => 'instance.branch_not_checked_out', 'message' => 'HEAD is not on the requested branch.', 'details' => [], 'request_id' => 'a6cc838a-e4a2-42fb-b2e6-ffabbe9c0d07'],
        ], 409)]);
        expect(Artisan::call('instance:rename', ['instance' => 3, '--branch' => 't3code/login', '--json' => true]))->toBe(1);
        expect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('error.code', 'instance.branch_not_checked_out');
    });
});

describe('instance:register', function (): void {
    it('refuses outside Git before sending a request', function (): void {
        $this->registrationGit->facts = null;
        $mockClient = MockClient::global();

        $this
            ->artisan('instance:register', ['--path' => '/tmp/not-git'])
            ->expectsOutputToContain('The current path is not a supported Git checkout or worktree.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('registers the source after explicit ownership consent', function (): void {
        $mockClient = MockClient::global([RegisterInstanceRequest::class => registration_mock_response()]);
        expect(Artisan::call('instance:register', ['--yes' => true]))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance: default', 'Source layout checkout',
            'Managed path /home/orbit/apps/acme/default',
        );
        expect($mockClient->getLastRequest())->toBeInstanceOf(RegisterInstanceRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())->toBe(['source_path' => '/work/acme']);
    });

    it('registers with --json on an interactive terminal without a prompt or prose', function (): void {
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $exitCode = Artisan::call('instance:register', ['--json' => true, '--yes' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(0);
        expect($output)
            ->toBe(registration_json())
            ->not->toContain("\n", 'Source:', 'Transfer this source to Orbit ownership?');
        expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(json_decode(registration_json(), associative: true, flags: JSON_THROW_ON_ERROR));
        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/acme',
        ]);
    });

    it('omits inferred creation values when canonical repository lookup can resolve a different Project identity', function (): void {
        $this->registrationGit->facts = new GitRegistrationFacts(
            path: '/work/legacy-default',
            repositoryUrl: 'https://github.com/laravel/laravel.git',
        );
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--no-interaction' => true,
                '--json' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/legacy-default',
        ]);
    });

    it('transports app overrides for the existing Project', function (): void {
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--app-overrides' => '{"web":{"path":"web","web_root":"public"}}',
                '--no-interaction' => true,
                '--json' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toEqual([
            'source_path' => '/work/acme',
            'app_overrides' => (object) ['web' => ['path' => 'web', 'web_root' => 'public']],
        ]);
    });

    it('rejects invalid app overrides before sending a request', function (string $overrides): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--app-overrides' => $overrides,
                '--no-interaction' => true,
                '--json' => true,
            ])
            ->expectsOutput(instance_app_overrides_invalid_json())
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with(instance_invalid_app_overrides());

    it('renders the Gateway refusal when no Project owns the repository', function (): void {
        MockClient::global([
            RegisterInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.project_missing',
                    'message' => 'No Project owns repository [git@github.com:acme/acme.git]. Create it with `orbit project:create` first.',
                    'request_id' => 'request-id',
                ],
            ], 422),
        ]);

        $exitCode = Artisan::call('instance:register', ['--yes' => true, '--no-interaction' => true, '--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'])
            ->toBe('instance.project_missing');
    });

    it('transports include worktrees and omits inferred values for a selected Project', function (): void {
        $mockClient = MockClient::global([
            RegisterInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--project' => '3',
                '--include-worktrees' => true,
                '--name' => 'feature',
                '--domain' => 'feature.test',
                '--json' => true,
                '--no-interaction' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/acme',
            'include_worktrees' => true,
            'project_id' => 3,
            'instance_name' => 'feature',
            'domain' => 'feature.test',
        ]);
    });

    it('rejects the removed hostname option', function (): void {
        $mockClient = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:register']);

        expect($tester->execute([
            '--hostname' => 'feature.test',
            '--json' => true,
            '--no-interaction' => true,
        ], ['interactive' => false]))->toBe(1);
        expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.invalid',
                'message' => 'The "--hostname" option does not exist.',
                'request_id' => null,
            ],
        ]);
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });
});

describe('credential-bearing registration origins', function (): void {
    it('refuses a credential-bearing discovered origin without printing it', function (bool $json): void {
        $userinfo = Str::random(12).':'.Str::random(24);
        $this->registrationGit->facts = new GitRegistrationFacts(
            path: '/work/acme',
            repositoryUrl: "https://{$userinfo}@example.test/acme.git",
        );
        $mockClient = MockClient::global();

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--json' => $json,
                '--no-interaction' => true,
            ])
            ->expectsOutputToContain('not a supported Git checkout or worktree')
            ->doesntExpectOutputToContain($userinfo)
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with(['interactive output' => false, 'JSON output' => true]);

    it('rejects a credential-bearing native Git origin during local discovery', function (): void {
        $directory = sys_get_temp_dir().'/orbit-cli-origin-'.Str::uuid();
        $userinfo = Str::random(12).':'.Str::random(24);
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);

        try {
            $commands = [
                ['git', 'init', '--initial-branch=main', $directory],
                ['git', '-C',   $directory,              'config',   'user.email', 'orb105@example.test'],
                ['git', '-C',   $directory,              'config',   'user.name',  'ORB-105'],
            ];
            file_put_contents(filename: $directory.'/README.md', data: "test\n");
            $commands[] = ['git', '-C', $directory, 'add', 'README.md'];
            $commands[] = ['git', '-C', $directory, 'commit', '-m', 'Initial'];
            $commands[] = [
                'git',
                '-C',
                $directory,
                'remote',
                'add',
                'origin',
                "https://{$userinfo}@example.test/acme.git",
            ];

            foreach ($commands as $command) {
                new Process($command)->mustRun();
            }

            expect(new NativeGitRegistrationDiscovery()->inspect($directory))->toBeNull();
        } finally {
            $files->deleteDirectory($directory);
        }
    });
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance:create', function (): void {
    it('documents the development contract and directs production to instance:clone', function (): void {
        $this
            ->artisan('help', ['command_name' => 'instance:create'])
            ->expectsOutputToContain('Create a development Instance on an app-dev Node.')
            ->expectsOutputToContain('default is reserved for the default development source')
            ->expectsOutputToContain('New production Instances require a candidate. Use instance:clone.')
            ->assertExitCode(0);
    });

    it('creates an Instance with inherited apps as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--json' => true,
            ])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances')
            ->and($request)
            ->toBeInstanceOf(CreateInstanceRequest::class)
            ->and($request?->body()->all())
            ->toBe(['project_id' => 3, 'node_id' => 2, 'name' => 'dev']);
    });

    it('transports optional app overrides without execution controls', function (): void {
        $mockClient = MockClient::global([
            CreateInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--app-overrides' => '{"web":{"path":"site","web_root":"public"}}',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toEqual([
            'project_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'app_overrides' => (object) ['web' => ['path' => 'site', 'web_root' => 'public']],
        ])
            ->and((string) $mockClient->getLastRequest()?->body())
            ->toContain('"app_overrides":{"web":{"path":"site","web_root":"public"}}');
    });

    it('rejects invalid app overrides before sending a request', function (string $overrides): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--app-overrides' => $overrides,
                '--json' => true,
            ])
            ->expectsOutput(instance_app_overrides_invalid_json())
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with(instance_invalid_app_overrides());

    it('refuses the removed root option before sending a request', function (): void {
        $mockClient = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:create']);

        expect($tester->execute([
            'project' => '3',
            'node' => '2',
            'name' => 'dev',
            '--root' => 'public',
            '--json' => true,
        ], ['interactive' => false]))->toBe(1);
        expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR)['error']['message'])
            ->toBe('The "--root" option does not exist.');
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('transports an optional Route domain without local policy validation', function (): void {
        $mockClient = MockClient::global([
            CreateInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'dev',
                '--domain' => 'Odd_Value',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'project_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'domain' => 'Odd_Value',
        ]);
    });

    it('rejects the removed hostname option', function (): void {
        $mockClient = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:create']);

        expect($tester->execute([
            'project' => '3',
            'node' => '2',
            'name' => 'dev',
            '--hostname' => 'Odd_Value',
            '--json' => true,
        ], ['interactive' => false]))->toBe(1);
        expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.invalid',
                'message' => 'The "--hostname" option does not exist.',
                'request_id' => null,
            ],
        ]);
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('transports an optional branch without local policy validation', function (): void {
        $mockClient = MockClient::global([
            CreateInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'project' => '3',
                'node' => '2',
                'name' => 'default',
                '--branch' => 'release',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'project_id' => 3,
            'node_id' => 2,
            'name' => 'default',
            'branch' => 'release',
        ]);
    });

    it('reports the created Instance for humans', function (): void {
        MockClient::global([CreateInstanceRequest::class => instance_mock_response(201)]);

        expect(Artisan::call('instance:create', ['project' => '3', 'node' => '2', 'name' => 'dev']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance: dev',
            'Source layout checkout',
            'Apps web: . · web root public · laravel-app',
            'App overrides —',
            'Selected branch dev',
            'Branch override —',
            'Domain dev.orbit.test',
            'URL https://dev.orbit.test',
        );
    });

    it('reports production placement identity for humans', function (): void {
        $payload = [
            ...instance_payload(),
            'production_user' => 'orbit-app-3',
            'production_home' => '/home/orbit-app-3',
            'checkout_path' => '/home/orbit-app-3',
            'apps' => [['name' => 'web', 'path' => 'site', 'web_root' => 'public', 'type' => 'laravel-app']],
            'app_overrides' => ['web' => ['path' => 'site', 'web_root' => 'public']],
        ];
        MockClient::global([CreateInstanceRequest::class => instance_mock_response(201, $payload)]);

        expect(Artisan::call('instance:create', ['project' => '3', 'node' => '2', 'name' => 'dev']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Production user orbit-app-3',
            'Production home /home/orbit-app-3',
            'Apps web: site · web root public · laravel-app',
            'App overrides web',
        );
    });
});

describe('instance:list', function (): void {
    it('lists Instances as JSON', function (): void {
        MockClient::destroyGlobal();
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'instances' => [[
                ...instance_payload(),
                'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
            ]],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('lists Instance source identity for humans', function (): void {
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        expect(Artisan::call('instance:list'))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'ID PROJECT NODE VITE PORT NAME SOURCE LAYOUT APPS SELECTED BRANCH BRANCH OVERRIDE ROUTE DOMAIN URL STATUS REMOVAL',
            '5 3 2 — dev checkout web dev — dev.orbit.test https://dev.orbit.test active —',
            'Request ID: '.instance_request_id(),
        );
    });

    it('lists bounded unfinished removal progress for humans and JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload());
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        expect(Artisan::call('instance:list'))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('normal 0/1 completed; 1 remaining; runtime_cleanup; failed runtime_cleanup (instance.runtime_interrupted)');

        MockClient::destroyGlobal();
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutputToContain('"removal":{"operation_id":"0198e15d-16c4-7855-8eb2-182b53ad28bb"')
            ->assertExitCode(0);
    });
});

describe('instance:update', function (): void {
    it('shows the accepted deployment branch separately from the unchanged source branch', function (): void {
        $payload = instance_payload();
        $payload['selected_branch'] = 'main';
        $mock = MockClient::global([
            UpdateInstanceRequest::class => instance_mock_response(payload: $payload),
        ]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--branch' => 'release/next']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('Deployment branch release/next Selected branch main');
        expect($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release/next']);
    });

    it('updates the deployment branch without sending steps', function (): void {
        $mock = MockClient::global([
            UpdateInstanceRequest::class => instance_mock_response(),
        ]);

        $this
            ->artisan('instance:update', ['instance' => '5', '--branch' => 'release/next', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(UpdateInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release/next']);
    });
});

describe('instance:show', function (): void {
    it('shows an Instance as JSON', function (): void {
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(), ListProcessesRequest::class => no_processes_response()]);

        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);
    });

    it('shows Instance source details for humans', function (): void {
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance: dev ID 5 Project orbit-docs Node beast Status active',
            'Project orbit-docs',
            'Node beast',
            'Source layout checkout',
            'Checkout /home/orbit/apps/orbit-docs/dev',
            'Apps web: . · web root public · laravel-app',
            'App overrides —',
            'Selected branch dev',
            'Branch override —',
            'Domain dev.orbit.test',
            'URL https://dev.orbit.test',
            'No Processes.',
        );
    });

    it('prints deploy steps in phase and placement order', function (): void {
        $payload = instance_payload();
        $payload['deploy_steps'] = [[
            'name' => 'migrate',
            'phase' => 'before_activation',
            'command' => 'php artisan migrate --force',
            'timeout_seconds' => 300,
        ]];
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'NAME',
            'migrate',
            'before_activation',
            '300',
        );
    });

    it('shows bounded unfinished removal progress for humans', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::destroyGlobal();
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance: dev ID 5 Project orbit-docs Node beast Status removing',
            'Removal mode forced',
            'Removal progress 0/1 completed; 1 remaining',
            'Removal step runtime_cleanup',
            'Removal failed step runtime_cleanup',
            'Removal error code instance.runtime_interrupted',
        );
    });

    it('shows bounded unfinished removal progress as JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::global([ShowInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        $expected = json_encode([
            ...$payload,
            'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });
});

describe('instance:destroy', function (): void {
    it('removes an Instance in normal mode by default', function (): void {
        $mockClient = MockClient::global([DestroyInstanceRequest::class => removal_mock_response()]);

        $this
            ->artisan('instance:destroy', ['--yes' => true, 'instance' => '5', '--json' => true])
            ->expectsOutput(removal_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBeEmpty();
    });

    it('transports explicit force and renders bounded progress', function (): void {
        $mockClient = MockClient::global([DestroyInstanceRequest::class => removal_mock_response(force: true)]);

        expect(Artisan::call('instance:destroy', ['--yes' => true, 'instance' => '5', '--force' => true]))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance [dev] removed.',
            'Mode forced',
            'Progress 1/1 completed; 0 remaining',
            'Current step —',
        );

        expect($mockClient->getLastRequest()?->body()->all())->toBe(['force' => true]);
    });

    it('preserves bounded failed removal progress in human and JSON errors', function (): void {
        $failure = [
            'error' => [
                'code' => 'instance.runtime_interrupted',
                'message' => 'Instance removal was accepted but remains incomplete.',
                'details' => ['removal' => removal_progress_payload()],
                'request_id' => instance_request_id(),
            ],
        ];
        MockClient::global([
            DestroyInstanceRequest::class => MockResponse::make(
                $failure,
                502,
                ['X-Orbit-Request-Id' => instance_request_id()],
            ),
        ]);

        expect(Artisan::call('instance:destroy', ['--yes' => true, 'instance' => '5']))->toBe(1);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Instance removal was accepted but remains incomplete.',
            'Mode normal',
            'Progress 0/1 completed; 1 remaining',
            'Current step runtime_cleanup',
            'Failed step runtime_cleanup',
            'Error code instance.runtime_interrupted',
        );

        MockClient::destroyGlobal();
        MockClient::global([
            DestroyInstanceRequest::class => MockResponse::make(
                $failure,
                502,
                ['X-Orbit-Request-Id' => instance_request_id()],
            ),
        ]);
        $expected = json_encode($failure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this
            ->artisan('instance:destroy', ['--yes' => true, 'instance' => '5', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(1);
    });
});

it('rejects invalid Instance IDs before making an API request', function (string $command, string $instanceId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, ['instance' => $instanceId])
        ->expectsOutputToContain('Instance ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['instance:show', '0'],
    'destroy negative' => ['instance:destroy', '-1'],
    'update zero' => ['instance:update', '0'],
]);

it('does not register replaced instance lifecycle names', function (): void {
    expect(Artisan::all())->not->toHaveKeys(['instance:new', 'instance:remove']);
});

it('rejects invalid parent IDs before creating an Instance', function (
    string $projectId,
    string $nodeId,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('instance:create', ['project' => $projectId, 'node' => $nodeId, 'name' => 'dev'])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'invalid app' => ['0', '2', 'Project ID must be a positive integer.'],
    'invalid node' => ['3', '-1', 'Node ID must be a positive integer.'],
]);

function no_processes_response(): MockResponse
{
    return MockResponse::make(['data' => [], 'meta' => ['request_id' => instance_request_id()]]);
}

function instance_app_overrides_invalid_json(): string
{
    return json_encode([
        'error' => [
            'code' => 'instance.app_overrides_invalid',
            'message' => 'Pass --app-overrides as a JSON object keyed by app name, each with path and web_root.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @return array<string, array{string}> */
function instance_invalid_app_overrides(): array
{
    return [
        'not JSON' => ['{web'],
        'a list' => ['[{"path":"site","web_root":null}]'],
        'a missing web root' => ['{"web":{"path":"site"}}'],
        'an extra key' => ['{"web":{"path":"site","web_root":null,"type":"laravel-app"}}'],
        'a path that is not text' => ['{"web":{"path":7,"web_root":null}}'],
    ];
}
