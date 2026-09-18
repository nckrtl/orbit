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
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\DestroyAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\UpdateAppInstanceRequest;
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
                slug: 'acme',
                defaultBranch: 'main',
                branch: 'main',
                root: 'public',
                layout: 'checkout',
                commit: str_repeat(string: 'a', times: 40),
            );
        }

        public function inspect(string $path): ?GitRegistrationFacts
        {
            return $this->facts;
        }
    };
    app()->instance(GitRegistrationDiscovery::class, $this->registrationGit);
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
        $mockClient = MockClient::global([RegisterAppInstanceRequest::class => registration_mock_response()]);
        expect(Artisan::call('instance:register', ['--yes' => true]))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'App instance: default', 'Source layout checkout',
            'Managed path /home/orbit/apps/acme/default',
        );
        expect($mockClient->getLastRequest())->toBeInstanceOf(RegisterAppInstanceRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())->toBe(['source_path' => '/work/acme']);
    });

    it('registers with --json on an interactive terminal without a prompt or prose', function (): void {
        $mockClient = MockClient::global([
            RegisterAppInstanceRequest::class => registration_mock_response(),
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

    it('omits inferred creation values when canonical repository lookup can resolve a different App identity', function (): void {
        $this->registrationGit->facts = new GitRegistrationFacts(
            path: '/work/legacy-default',
            repositoryUrl: 'https://github.com/laravel/laravel.git',
            slug: 'laravel',
            defaultBranch: 'master',
            branch: '13.x',
            root: 'public',
            layout: 'checkout',
            commit: str_repeat(string: 'a', times: 40),
        );
        $mockClient = MockClient::global([
            RegisterAppInstanceRequest::class => registration_mock_response(),
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

    it('transports explicit creation values when App lookup is not selected', function (): void {
        $mockClient = MockClient::global([
            RegisterAppInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--app-name' => 'Confirmed',
                '--app-slug' => 'confirmed',
                '--default-branch' => 'trunk',
                '--root' => 'web',
                '--no-interaction' => true,
                '--json' => true,
            ])
            ->expectsOutput(registration_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'source_path' => '/work/acme',
            'app_name' => 'Confirmed',
            'app_slug' => 'confirmed',
            'default_branch' => 'trunk',
            'root' => 'web',
        ]);
    });

    it('refuses unresolved App values with one JSON error document without a prompt or request', function (array $parameters): void {
        $facts = $this->registrationGit->facts;
        assert(
            $facts instanceof GitRegistrationFacts,
            description: 'The registration fixture starts with discovered Git facts.',
        );
        $this->registrationGit->facts = new GitRegistrationFacts(
            path: $facts->path,
            repositoryUrl: $facts->repositoryUrl,
            slug: $facts->slug,
            defaultBranch: null,
            branch: $facts->branch,
            root: null,
            layout: $facts->layout,
            commit: $facts->commit,
        );
        $mockClient = MockClient::global();

        $exitCode = Artisan::call('instance:register', $parameters);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)->not->toContain("\n", 'Source:', 'Default branch', 'Transfer this source to Orbit ownership?');
        expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'instance.registration_values_unresolved',
                'message' => 'Non-interactive registration requires unresolved App values as options.',
                'request_id' => null,
            ],
        ]);
        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'JSON on an interactive terminal' => [['--json' => true]],
        'JSON without interaction' => [['--json' => true, '--no-interaction' => true]],
    ]);

    it('transports include worktrees and omits inferred values for a selected App', function (): void {
        $mockClient = MockClient::global([
            RegisterAppInstanceRequest::class => registration_mock_response(),
        ]);

        $this
            ->artisan('instance:register', [
                '--yes' => true,
                '--app' => '3',
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
            'app_id' => 3,
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
            slug: 'acme',
            defaultBranch: 'main',
            branch: 'main',
            root: 'public',
            layout: 'checkout',
            commit: str_repeat(string: 'a', times: 40),
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
            ->expectsOutputToContain('Create a development AppInstance on an app-dev Node.')
            ->expectsOutputToContain('default is reserved for the default development source')
            ->expectsOutputToContain('New production AppInstances require a candidate. Use instance:clone.')
            ->assertExitCode(0);
    });

    it('creates an AppInstance with inherited root as JSON', function (): void {
        $mockClient = MockClient::global([
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

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances')
            ->and($request)
            ->toBeInstanceOf(CreateAppInstanceRequest::class)
            ->and($request?->body()->all())
            ->toBe(['app_id' => 3, 'node_id' => 2, 'name' => 'dev']);
    });

    it('transports an optional root override without execution controls', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--root' => 'site/public',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'root' => 'site/public',
        ]);
    });

    it('transports an optional Route domain without local policy validation', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--domain' => 'Odd_Value',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'domain' => 'Odd_Value',
        ]);
    });

    it('rejects the removed hostname option', function (): void {
        $mockClient = MockClient::global();
        $tester = new CommandTester(app(Kernel::class)->all()['instance:create']);

        expect($tester->execute([
            'app' => '3',
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
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '2',
                'name' => 'default',
                '--branch' => 'release',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'default',
            'branch' => 'release',
        ]);
    });

    it('transports explicit source profile recovery and preserves ordinary omission', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(200),
        ]);

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '2',
                'name' => 'default',
                '--recover-source-profile' => true,
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'default',
            'recover_source_profile' => true,
        ]);

        $this
            ->artisan('instance:create', [
                'app' => '3',
                'node' => '2',
                'name' => 'default',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->not->toHaveKey('recover_source_profile');
    });

    it('reports the created AppInstance for humans', function (): void {
        MockClient::global([CreateAppInstanceRequest::class => instance_mock_response(201)]);

        expect(Artisan::call('instance:create', ['app' => '3', 'node' => '2', 'name' => 'dev']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'App instance: dev',
            'Source layout checkout',
            'Effective root public',
            'Selected branch dev',
            'Branch override —',
            'Migration required no',
            'Domain dev.orbit.test',
            'URL https://dev.orbit.test',
        );
    });

    it('reports production placement identity for humans', function (): void {
        $payload = [
            ...instance_payload(),
            'environment' => 'production',
            'production_user' => 'orbit-app-3',
            'production_home' => '/home/orbit-app-3',
            'checkout_path' => '/home/orbit-app-3',
            'effective_root' => '/home/orbit-app-3/current/public',
        ];
        MockClient::global([CreateAppInstanceRequest::class => instance_mock_response(201, $payload)]);

        expect(Artisan::call('instance:create', ['app' => '3', 'node' => '2', 'name' => 'dev']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'Production user orbit-app-3',
            'Production home /home/orbit-app-3',
            'Effective root /home/orbit-app-3/current/public',
        );
    });
});

describe('instance:list', function (): void {
    it('lists AppInstances as JSON', function (): void {
        MockClient::destroyGlobal();
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'app_instances' => [[
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

    it('lists AppInstance source identity for humans', function (): void {
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        expect(Artisan::call('instance:list'))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'ID APP NODE VITE PORT NAME ENVIRONMENT SOURCE LAYOUT ROOT SELECTED BRANCH BRANCH OVERRIDE MIGRATION REQUIRED ROUTE DOMAIN URL STATUS REMOVAL',
            '5 3 2 — dev development checkout public dev — no dev.orbit.test https://dev.orbit.test active —',
            'Request ID: '.instance_request_id(),
        );
    });

    it('lists bounded unfinished removal progress for humans and JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload());
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        expect(Artisan::call('instance:list'))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('normal 0/1 completed; 1 remaining; runtime_cleanup; failed runtime_cleanup (instance.runtime_interrupted)');

        MockClient::destroyGlobal();
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
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
        $payload['environment'] = 'production';
        $payload['selected_branch'] = 'main';
        $mock = MockClient::global([
            UpdateAppInstanceRequest::class => instance_mock_response(payload: $payload),
        ]);

        expect(Artisan::call('instance:update', ['instance' => '5', '--branch' => 'release/next']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain('Deployment branch release/next Selected branch main');
        expect($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release/next']);
    });

    it('updates the deployment branch without sending steps', function (): void {
        $mock = MockClient::global([
            UpdateAppInstanceRequest::class => instance_mock_response(),
        ]);

        $this
            ->artisan('instance:update', ['instance' => '5', '--branch' => 'release/next', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(UpdateAppInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())->toBe(['branch' => 'release/next']);
    });
});

describe('instance:show', function (): void {
    it('shows an AppInstance as JSON', function (): void {
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(), ListProcessesRequest::class => no_processes_response()]);

        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);
    });

    it('shows AppInstance source details for humans', function (): void {
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'App instance: dev ID 5 App orbit-docs Node beast Status active',
            'App orbit-docs',
            'Node beast',
            'Source layout checkout',
            'Checkout /home/orbit/apps/orbit-docs/dev',
            'Root override —',
            'Effective root public',
            'Selected branch dev',
            'Branch override —',
            'Migration required no',
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
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

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
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

        expect(Artisan::call('instance:show', ['instance' => '5']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'App instance: dev ID 5 App orbit-docs Node beast Status removing',
            'Removal mode forced',
            'Removal progress 0/1 completed; 1 remaining',
            'Removal step runtime_cleanup',
            'Removal failed step runtime_cleanup',
            'Removal error code instance.runtime_interrupted',
        );
    });

    it('shows bounded unfinished removal progress as JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(payload: $payload), ListProcessesRequest::class => no_processes_response()]);

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
    it('removes an AppInstance in normal mode by default', function (): void {
        $mockClient = MockClient::global([DestroyAppInstanceRequest::class => removal_mock_response()]);

        $this
            ->artisan('instance:destroy', ['--yes' => true, 'instance' => '5', '--json' => true])
            ->expectsOutput(removal_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBeEmpty();
    });

    it('transports explicit force and renders bounded progress', function (): void {
        $mockClient = MockClient::global([DestroyAppInstanceRequest::class => removal_mock_response(force: true)]);

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
                'message' => 'AppInstance removal was accepted but remains incomplete.',
                'details' => ['removal' => removal_progress_payload()],
                'request_id' => instance_request_id(),
            ],
        ];
        MockClient::global([
            DestroyAppInstanceRequest::class => MockResponse::make(
                $failure,
                502,
                ['X-Orbit-Request-Id' => instance_request_id()],
            ),
        ]);

        expect(Artisan::call('instance:destroy', ['--yes' => true, 'instance' => '5']))->toBe(1);
        expect(instance_source_text(Artisan::output()))->toContain(
            'AppInstance removal was accepted but remains incomplete.',
            'Mode normal',
            'Progress 0/1 completed; 1 remaining',
            'Current step runtime_cleanup',
            'Failed step runtime_cleanup',
            'Error code instance.runtime_interrupted',
        );

        MockClient::destroyGlobal();
        MockClient::global([
            DestroyAppInstanceRequest::class => MockResponse::make(
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

it('rejects invalid parent IDs before creating an AppInstance', function (
    string $appId,
    string $nodeId,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('instance:create', ['app' => $appId, 'node' => $nodeId, 'name' => 'dev'])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'invalid app' => ['0', '2', 'App ID must be a positive integer.'],
    'invalid node' => ['3', '-1', 'Node ID must be a positive integer.'],
]);

function no_processes_response(): MockResponse
{
    return MockResponse::make(['data' => [], 'meta' => ['request_id' => instance_request_id()]]);
}
