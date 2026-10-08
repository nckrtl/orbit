<?php

declare(strict_types=1);

use App\Commands\Projects\CreateProjectCommand;
use App\Commands\Projects\UpdateProjectCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Projects\CreateProjectRequest;
use Orbit\Sdk\Requests\Projects\DestroyProjectRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Requests\Projects\UpdateProjectRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
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
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('project:create', function (): void {
    it('creates a project through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository' => 'git@github.com:nckrtl/orbit.git',
                '--name' => 'Orbit',
                '--default-branch' => 'stable',
                '--json' => true,
            ])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/projects')
            ->and($request)
            ->toBeInstanceOf(CreateProjectRequest::class)
            ->and($request?->body()->all())
            ->toBe([
                'name' => 'Orbit',
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository_url' => 'git@github.com:nckrtl/orbit.git',
                'default_branch' => 'stable',
                'root' => 'public',
            ]);
    });

    it('creates a node-package Project through the typed SDK request', function (): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this->artisan('project:create', [
            'slug' => 'node-kit',
            'type' => 'node-package',
            'repository' => 'https://github.com/acme/node-kit.git',
            '--root' => '.',
        ])->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateProjectRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toMatchArray(['type' => 'node-package', 'root' => '.']);
    });

    it('creates a Project that reads through the GitHub CLI', function (): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this->artisan('project:create', [
            'slug' => 'leden',
            'type' => 'laravel-app',
            'repository' => 'git@github.com:acme/leden.git',
            '--source-access' => 'gh_cli',
        ])->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toMatchArray(['source_access' => 'gh_cli']);
    });

    it('refuses an unknown source access before contacting the Gateway', function (string $command, array $arguments): void {
        $mockClient = MockClient::global();

        $this->artisan($command, [...$arguments, '--source-access' => 'token', '--json' => true])
            ->expectsOutputToContain('project.source_access_invalid')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'create' => ['project:create', ['slug' => 'leden', 'type' => 'laravel-app', 'repository' => 'git@github.com:acme/leden.git']],
        'update' => ['project:update', ['project' => '14']],
    ]);

    it('defaults the root by Project type when --root is omitted', function (string $type, string $root): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this->artisan('project:create', [
            'slug' => 'kit',
            'type' => $type,
            'repository' => 'https://github.com/acme/kit.git',
        ])->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())
            ->toMatchArray(['type' => $type, 'root' => $root]);
    })->with([
        'node-package' => ['node-package', '.'],
        'laravel-package' => ['laravel-package', '.'],
        'laravel-app' => ['laravel-app', 'public'],
        'monorepo' => ['monorepo', 'public'],
    ]);

    it('reports the created project for humans', function (): void {
        MockClient::global([CreateProjectRequest::class => app_mock_response(201)]);

        $this
            ->artisan('project:create', [
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository' => 'git@github.com:nckrtl/orbit.git',
            ])
            ->expectsOutput('Project [orbit] created.')
            ->expectsOutputToContain(app_request_id())
            ->assertExitCode(0);
    });

    it('transports a custom relative root', function (): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository' => 'git@github.com:nckrtl/orbit.git',
                '--root' => 'web/public',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all()['root'] ?? null)
            ->toBe('web/public');
    });

    it('rejects an unbounded or control-bearing slug without disclosure or gateway IO', function (string $slug): void {
        $mockClient = MockClient::global();
        $expected = json_encode([
            'error' => [
                'code' => 'project.slug_invalid',
                'message' => 'Project slug is invalid.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('project:create', [
            'slug' => $slug,
            'type' => 'laravel-app',
            'repository' => 'git@github.com:nckrtl/orbit.git',
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('slug-secret');
        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'line feed' => "orbit\nslug-secret",
        'NUL' => "orbit\0slug-secret",
        'over maximum length' => str_repeat(string: 'a', times: 64).'slug-secret',
    ]);

    it('sends task workspace routing only when the caller sets true or false', function (?string $option, array $expected): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);
        $arguments = [
            'slug' => 'kit',
            'type' => 'node-package',
            'repository' => 'https://github.com/acme/kit.git',
            '--root' => '.',
        ];

        if ($option !== null) {
            $arguments['--task-workspace-routed'] = $option;
        }

        $this->artisan('project:create', $arguments)->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'slug' => 'kit',
            'type' => 'node-package',
            'repository_url' => 'https://github.com/acme/kit.git',
            'root' => '.',
            ...$expected,
        ]);
    })->with([
        'omitted' => [null, []],
        'true' => ['true', ['task_workspace_routed' => true]],
        'false' => ['false', ['task_workspace_routed' => false]],
    ]);

    it('rejects an invalid task workspace routing value before a request', function (?string $value): void {
        $mockClient = MockClient::global();

        $this->artisan('project:create', [
            'slug' => 'kit',
            'type' => 'node-package',
            'repository' => 'https://github.com/acme/kit.git',
            '--task-workspace-routed' => $value,
            '--json' => true,
        ])->expectsOutput(json_encode([
            'error' => [
                'code' => 'project.task_workspace_routed_invalid',
                'message' => 'Task workspace routed must be true or false.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'yes' => 'yes',
        'no' => 'no',
        'one' => '1',
        'zero' => '0',
        'uppercase' => 'TRUE',
        'mixed case' => 'False',
        'empty' => '',
        'missing value' => null,
    ]);

    it('does not describe a type-specific task check default', function (): void {
        $definition = $this->app->make(CreateProjectCommand::class)->getDefinition();

        expect($definition->getOption('task-check')->getDescription())
            ->toBe('Task check command. Omitted stores none')
            ->and($definition->getOption('task-workspace-routed')->getDescription())
            ->toBe('Whether new task workspaces get a Route (true or false)');
    });

    it('passes project slug policy values through the typed SDK request', function (): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'Orbit App',
                'type' => 'laravel-app',
                'repository' => 'nckrtl/orbit',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateProjectRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe([
                'slug' => 'Orbit App',
                'type' => 'laravel-app',
                'repository_url' => 'nckrtl/orbit',
                'root' => 'public',
            ]);
    });
});

describe('project:create repository boundary', function (): void {
    it('rejects unsafe repository input without disclosure or gateway IO', function (string $repository): void {
        $mockClient = MockClient::global();
        $expected = json_encode([
            'error' => [
                'code' => 'project.repository_invalid',
                'message' => 'Repository URL is invalid.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('project:create', [
            'slug' => 'orbit',
            'type' => 'laravel-app',
            'repository' => $repository,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('repository-secret');
        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'HTTPS username' => 'https://repository-secret@example.test/orbit.git',
        'HTTPS password' => 'https://user:'.app_cli_secret().'@example.test/orbit.git',
        'HTTP username' => 'http://repository-secret@example.test/orbit.git',
        'SSH password' => 'ssh://git:'.app_cli_secret().'@example.test/orbit.git',
        'malformed credential authority' => 'https://user:repository-secret@',
        'query' => 'https://example.test/orbit.git?token=repository-secret',
        'fragment' => 'https://example.test/orbit.git#repository-secret',
        'line feed' => "https://example.test/orbit.git\nrepository-secret",
        'carriage return' => "https://example.test/orbit.git\rrepository-secret",
        'NUL' => "https://example.test/orbit.git\0repository-secret",
        'Unicode format character' => "https://example.test/orbit\u{200B}repository-secret",
        'over maximum length' => 'https://example.test/'.str_repeat(string: 'a', times: 2028).'repository-secret',
        'credential-shaped token' => 'API_TOKEN='.app_cli_secret(),
    ]);

    it('accepts one explicit safe repository reference', function (string $repository): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository' => $repository,
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all()['repository_url'] ?? null)
            ->toBe($repository);
    })->with([
        'GitHub shorthand' => 'nckrtl/orbit',
        'HTTPS URL' => 'https://github.com/nckrtl/orbit.git',
        'SSH URL with conventional Git user' => 'ssh://git@github.com/nckrtl/orbit.git',
        'scp-style SSH URL' => 'git@github.com:nckrtl/orbit.git',
    ]);

    it('passes repository policy values through the typed SDK request', function (string $repository): void {
        $mockClient = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository' => $repository,
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateProjectRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe([
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository_url' => $repository,
                'root' => 'public',
            ]);
    })->with([
        'unrecognized reference' => 'not-a-repository',
        'file scheme' => 'file:///tmp/repository',
        'plain path' => '/tmp/repository',
    ]);
});

describe('project:list', function (): void {
    it('lists projects as JSON', function (): void {
        MockClient::global([
            ListProjectsRequest::class => MockResponse::make([
                'data' => [app_payload()],
                'meta' => ['request_id' => app_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'projects' => [app_payload()],
            'request_id' => app_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('project:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('lists projects for humans', function (): void {
        MockClient::global([
            ListProjectsRequest::class => MockResponse::make([
                'data' => [app_payload()],
                'meta' => ['request_id' => app_request_id()],
            ]),
        ]);

        expect(Artisan::call('project:list'))->toBe(0);
        expect(Artisan::output())->toContain('NAME')
            ->toContain('SLUG')
            ->toContain('TYPE')
            ->toContain('REPOSITORY')
            ->toContain('WEB ROOT')
            ->toContain(app_request_id());
    });

    it('fails clearly when no gateway profile is active', function (): void {
        new Filesystem()->deleteDirectory($this->orbitHome);
        $mockClient = MockClient::global();

        $this
            ->artisan('project:list')
            ->expectsOutputToContain('No active gateway profile.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('fails closed on a corrupted persisted profile without sending a request', function (): void {
        $configPath = $this->orbitHome.'/config.json';
        file_put_contents($configPath, json_encode([
            'active_gateway' => 'test',
            'gateways' => [
                'test' => [
                    'url' => 'https://user:profile-secret@10.44.0.1',
                    'ca_path' => '/tmp/profile-secret.pem',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        chmod(filename: $configPath, permissions: 0o600);
        $mockClient = MockClient::global();
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.config_invalid',
                'message' => 'Orbit gateway configuration is invalid.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('project:list', ['--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('profile-secret');
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('reports gateway API errors', function (): void {
        MockClient::global([
            ListProjectsRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'Gateway is unavailable.',
                    'details' => [],
                ],
            ], 503),
        ]);

        $this
            ->artisan('project:list')
            ->expectsOutputToContain('Gateway is unavailable.')
            ->assertExitCode(1);
    });

    it('reports transport failures without exposing transport details', function (): void {
        MockClient::global([
            ListProjectsRequest::class => static function (PendingRequest $pendingRequest): never {
                throw new FatalRequestException(
                    new RuntimeException('TLS failed for token super-secret'),
                    $pendingRequest,
                );
            },
        ]);

        $this
            ->artisan('project:list')
            ->expectsOutputToContain('Could not reach the gateway.')
            ->doesntExpectOutputToContain('TLS failed')
            ->doesntExpectOutputToContain('super-secret')
            ->assertExitCode(1);
    });
});

describe('project:show', function (): void {
    it('shows a project as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowProjectRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('project:show', ['project' => '3', '--json' => true])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/projects/3');
    });

    it('shows project details for humans', function (): void {
        MockClient::global([
            ShowProjectRequest::class => app_mock_response(),
            ListInstancesRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => app_request_id()]]),
        ]);

        expect(Artisan::call('project:show', ['project' => '3']))->toBe(0);
        expect(Artisan::output())->toContain('Project: orbit')
            ->toContain('Orbit')
            ->toContain('git@github.com:nckrtl/orbit.git')
            ->toContain('Default branch')
            ->toContain('main')
            ->toContain('Web root')
            ->toContain('public')
            ->toContain('Task check')
            ->toContain('composer check')
            ->toContain('No Instances.')
            ->not->toContain(app_request_id());
    });

    it('shows task workspace routing in human and JSON detail', function (bool $routed, string $label): void {
        $payload = [...app_payload(), 'task_workspace_routed' => $routed];
        MockClient::global([
            ShowProjectRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => app_request_id()],
            ]),
            ListInstancesRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => app_request_id()]]),
        ]);

        expect(Artisan::call('project:show', ['project' => '3']))->toBe(0);
        expect(Artisan::output())->toContain('Task workspace routed')
            ->toContain($label);

        MockClient::global([
            ShowProjectRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => app_request_id()],
            ]),
        ]);

        expect(Artisan::call('project:show', ['project' => '3', '--json' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['task_workspace_routed'])->toBe($routed);
    })->with([
        'routed' => [true, 'yes'],
        'unrouted' => [false, 'no'],
    ]);

    it('returns legacy null source defaults unchanged', function (): void {
        $payload = [...app_payload(), 'default_branch' => null, 'root' => null];
        MockClient::global([
            ShowProjectRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => app_request_id()],
            ]),
        ]);
        $expected = json_encode([
            ...$payload,
            'request_id' => app_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('project:show', ['project' => '3', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });
});

describe('project:update', function (): void {
    it('updates a project through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            UpdateProjectRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('project:update', [
                'project' => '3',
                '--repository' => 'https://github.com/nckrtl/orbit.git',
                '--default-branch' => 'stable',
                '--json' => true,
            ])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/projects/3')
            ->and($request)
            ->toBeInstanceOf(UpdateProjectRequest::class)
            ->and($request?->body()->all())
            ->toBe([
                'repository_url' => 'https://github.com/nckrtl/orbit.git',
                'default_branch' => 'stable',
            ])
            ->and($request?->body()->all())
            ->not
            ->toHaveKey('main_branch');
    });

    it('sets or clears the Project task check through the SDK request', function (): void {
        $setClient = MockClient::global([UpdateProjectRequest::class => app_mock_response()]);

        $this->artisan('project:update', [
            'project' => '3',
            '--task-check' => 'vp run check',
        ])->assertExitCode(0);

        expect($setClient->getLastRequest()?->body()->all())
            ->toBe(['task_check' => 'vp run check']);

        $clearClient = MockClient::global([UpdateProjectRequest::class => app_mock_response()]);
        $this->artisan('project:update', [
            'project' => '3',
            '--clear-task-check' => true,
        ])->assertExitCode(0);

        expect($clearClient->getLastRequest()?->body()->all())
            ->toBe(['task_check' => null]);

    });

    it('switches a Project to the GitHub CLI with its default branch in one update', function (): void {
        $mockClient = MockClient::global([UpdateProjectRequest::class => app_mock_response()]);

        $this->artisan('project:update', [
            'project' => '14',
            '--source-access' => 'gh_cli',
            '--default-branch' => 'main',
        ])->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())
            ->toBe(['source_access' => 'gh_cli', 'default_branch' => 'main']);
    });

    it('refuses --task-check with --clear-task-check before contacting the Gateway', function (): void {
        $mockClient = MockClient::global([UpdateProjectRequest::class => app_mock_response()]);

        $this->artisan('project:update', [
            'project' => '3',
            '--task-check' => 'composer check',
            '--clear-task-check' => true,
            '--json' => true,
        ])->expectsOutputToContain('project.task_check_conflict')
            ->assertExitCode(1);

        expect($mockClient->getLastRequest())->toBeNull();
    });

    it('updates a Project to node-package through the typed SDK request', function (): void {
        $mockClient = MockClient::global([
            UpdateProjectRequest::class => app_mock_response(),
        ]);

        $this->artisan('project:update', [
            'project' => '3',
            '--type' => 'node-package',
            '--json' => true,
        ])->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(UpdateProjectRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe(['type' => 'node-package']);
    });

    it('reports the updated project for humans', function (): void {
        MockClient::global([UpdateProjectRequest::class => app_mock_response()]);

        $this
            ->artisan('project:update', [
                'project' => '3',
                '--slug' => 'orbit',
            ])
            ->expectsOutput('Project [orbit] updated.')
            ->expectsOutputToContain(app_request_id())
            ->assertExitCode(0);
    });

    it('updates task workspace routing alone and omits it when the flag is absent', function (?string $option, array $body): void {
        $mockClient = MockClient::global([
            UpdateProjectRequest::class => app_mock_response(),
        ]);
        $arguments = ['project' => '3'];

        if ($option !== null) {
            $arguments['--task-workspace-routed'] = $option;
        } else {
            $arguments['--slug'] = 'orbit';
        }

        $this->artisan('project:update', $arguments)->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe($body);
    })->with([
        'false only' => ['false', ['task_workspace_routed' => false]],
        'true only' => ['true', ['task_workspace_routed' => true]],
        'omitted' => [null, ['slug' => 'orbit']],
    ]);

    it('rejects an invalid task workspace routing value before a request', function (?string $value): void {
        $mockClient = MockClient::global();

        $this->artisan('project:update', [
            'project' => '3',
            '--slug' => 'orbit',
            '--task-workspace-routed' => $value,
            '--json' => true,
        ])->expectsOutput(json_encode([
            'error' => [
                'code' => 'project.task_workspace_routed_invalid',
                'message' => 'Task workspace routed must be true or false.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'yes' => 'yes',
        'no' => 'no',
        'one' => '1',
        'zero' => '0',
        'uppercase' => 'TRUE',
        'mixed case' => 'False',
        'empty' => '',
        'missing value' => null,
    ]);

    it('sends the review-and-merge switch and the merge check only when given', function (array $arguments, array $body): void {
        $mockClient = MockClient::global([
            UpdateProjectRequest::class => app_mock_response(),
        ]);

        $this->artisan('project:update', ['project' => '3', ...$arguments])->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe($body);
    })->with([
        'switch on with a check' => [['--review-and-merge' => 'true', '--merge-check' => 'Required checks'], ['review_and_merge' => true, 'merge_check' => 'Required checks']],
        'switch off' => [['--review-and-merge' => 'false'], ['review_and_merge' => false]],
        'clear the check' => [['--clear-merge-check' => true], ['merge_check' => null]],
    ]);

    it('rejects an invalid review-and-merge value or conflicting merge check flags before a request', function (array $arguments, string $code): void {
        $mockClient = MockClient::global();

        $this->artisan('project:update', ['project' => '3', '--json' => true, ...$arguments])
            ->expectsOutputToContain($code)
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'yes' => [['--review-and-merge' => 'yes'], 'project.review_and_merge_invalid'],
        'missing value' => [['--review-and-merge' => null], 'project.review_and_merge_invalid'],
        'both check flags' => [['--merge-check' => 'Required checks', '--clear-merge-check' => true], 'project.merge_check_conflict'],
        'blank check' => [['--merge-check' => '  '], 'project.merge_check_invalid'],
    ]);

    it('refuses an empty update without gateway IO', function (): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('project:update', ['project' => '3'])
            ->expectsOutputToContain('Provide at least one Project update.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('does not expose a main-branch option', function (): void {
        $definition = $this->app->make(UpdateProjectCommand::class)->getDefinition();

        expect($definition->hasOption('default-branch'))
            ->toBeTrue()
            ->and($definition->hasOption('main-branch'))
            ->toBeFalse();
    });
});

describe('project:destroy', function (): void {
    it('removes a project as JSON', function (): void {
        $mockClient = MockClient::global([
            DestroyProjectRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('project:destroy', ['project' => '3', '--yes' => true, '--json' => true])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/projects/3');
    });

    it('reports the removed project for humans', function (): void {
        MockClient::global([DestroyProjectRequest::class => app_mock_response()]);

        $this
            ->artisan('project:destroy', ['project' => '3', '--yes' => true])
            ->expectsOutput('Project [orbit] removed.')
            ->expectsOutputToContain(app_request_id())
            ->assertExitCode(0);
    });
});

it('rejects invalid project IDs before making an API request', function (string $command, string $projectId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, ['project' => $projectId])
        ->expectsOutputToContain('Project ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['project:show', '0'],
    'show negative' => ['project:show', '-1'],
    'update zero' => ['project:update', '0'],
    'remove non-numeric' => ['project:destroy', 'orbit'],
]);

/** @return array<string, int|string|array<string, string>> */
function app_payload(): array
{
    return [
        'id' => 3,
        'name' => 'Orbit',
        'slug' => 'orbit',
        'type' => 'laravel-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'source_access' => 'github_app',
        'default_branch' => 'main',
        'root' => 'public',
        'task_check' => 'composer check',
    ];
}

function app_mock_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => app_payload(),
        'meta' => ['request_id' => app_request_id()],
    ], $status);
}

function app_json(): string
{
    return json_encode([
        ...app_payload(),
        'request_id' => app_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function app_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function app_cli_secret(): string
{
    return implode('-', ['repository', 'secret']);
}

it('resolves destructive subjects but refuses automation without independent consent', function (
    string $command,
    string $running,
    bool $json,
): void {
    $mock = MockClient::global([ShowProjectRequest::class => app_mock_response()]);
    $arguments = ['project' => '3', '--no-interaction' => true];

    if ($json) {
        $arguments['--json'] = true;
    }

    expect(Artisan::call($command, $arguments))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Supply --yes to confirm this operation.')
        ->not->toContain($running, "\e[");
    expect($mock->getLastRequest())->toBeInstanceOf(ShowProjectRequest::class)
        ->and($mock->getRecordedResponses())->toHaveCount(1);

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.confirmation_required',
                'message' => 'Supply --yes to confirm this operation.',
                'request_id' => null,
            ],
        ]);
    }
})->with([
    'project:destroy' => ['project:destroy', 'Removing Project'],
])->with([false, true]);

it('preserves lookup failures before destructive consent without sending a mutation', function (
    string $command,
    string $running,
    bool $json,
): void {
    $mock = MockClient::global([
        ShowProjectRequest::class => MockResponse::make([
            'error' => ['code' => 'http.404', 'message' => 'Resource not found.', 'details' => []],
        ], 404),
    ]);
    $arguments = ['project' => '3', '--no-interaction' => true];

    if ($json) {
        $arguments['--json'] = true;
    }

    expect(Artisan::call($command, $arguments))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Resource not found.')
        ->not->toContain('Supply --yes', $running, "\e[");
    expect($mock->getLastRequest())->toBeInstanceOf(ShowProjectRequest::class)
        ->and($mock->getRecordedResponses())->toHaveCount(1);

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => ['code' => 'http.404', 'message' => 'Resource not found.', 'request_id' => null],
        ]);
    }
})->with([
    'project:destroy' => ['project:destroy', 'Removing Project'],
])->with([false, true]);

it('renders an explicit empty list and preserves the empty machine collection', function (bool $json): void {
    MockClient::global([
        ListProjectsRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => app_request_id()]]),
    ]);

    expect(Artisan::call('project:list', ['--json' => $json]))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain("\e[");

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'projects' => [], 'request_id' => app_request_id(),
        ]);
    } else {
        expect($output)->toContain('No Projects found.', app_request_id())->not->toContain('Operation failed.');
    }
})->with([false, true]);

describe('Project task compute', function (): void {
    it('transports a supplied mode on create and on a compute-only update', function (string $mode): void {
        $mock = MockClient::global([
            CreateProjectRequest::class => app_mock_response(201),
            UpdateProjectRequest::class => app_mock_response(),
        ]);
        $this->artisan('project:create', [
            'slug' => 'orbit', 'type' => 'monorepo',
            'repository' => 'https://github.com/nckrtl/orbit.git',
            '--task-compute' => $mode, '--json' => true,
        ])->assertExitCode(0);
        expect($mock->getLastRequest()?->body()->all()['task_compute'])->toBe($mode);

        $this->artisan('project:update', [
            'project' => '3', '--task-compute' => $mode, '--json' => true,
        ])->assertExitCode(0);
        expect($mock->getLastRequest()?->body()->all())->toBe(['task_compute' => $mode]);
    })->with(['shared', 'vm']);

    it('refuses invalid or bare compute flags without Gateway IO', function (?string $mode): void {
        $mock = MockClient::global();
        foreach ([
            'project:create' => ['slug' => 'orbit', 'type' => 'monorepo', 'repository' => 'https://github.com/nckrtl/orbit.git'],
            'project:update' => ['project' => '3'],
        ] as $command => $arguments) {
            $this->artisan($command, [...$arguments, '--task-compute' => $mode, '--json' => true])
                ->expectsOutputToContain('project.task_compute_invalid')
                ->assertExitCode(1);
        }
        expect($mock->getLastPendingRequest())->toBeNull();
    })->with(['auto', 'VM', '', null]);
});
