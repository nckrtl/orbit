<?php

declare(strict_types=1);

use App\Commands\Apps\UpdateAppCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Requests\Apps\DestroyAppRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Requests\Apps\UpdateAppRequest;
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
    it('creates an app through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateAppRequest::class => app_mock_response(201),
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
            ->toBeInstanceOf(CreateAppRequest::class)
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
            CreateAppRequest::class => app_mock_response(201),
        ]);

        $this->artisan('project:create', [
            'slug' => 'node-kit',
            'type' => 'node-package',
            'repository' => 'https://github.com/acme/node-kit.git',
            '--root' => '.',
        ])->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateAppRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toMatchArray(['type' => 'node-package', 'root' => '.']);
    });

    it('reports the created app for humans', function (): void {
        MockClient::global([CreateAppRequest::class => app_mock_response(201)]);

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
            CreateAppRequest::class => app_mock_response(201),
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
                'code' => 'app.slug_invalid',
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

    it('passes app slug policy values through the typed SDK request', function (): void {
        $mockClient = MockClient::global([
            CreateAppRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'Orbit App',
                'type' => 'laravel-app',
                'repository' => 'nckrtl/orbit',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateAppRequest::class)
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
                'code' => 'app.repository_invalid',
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
            CreateAppRequest::class => app_mock_response(201),
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
            CreateAppRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('project:create', [
                'slug' => 'orbit',
                'type' => 'laravel-app',
                'repository' => $repository,
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateAppRequest::class)
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
    it('lists apps as JSON', function (): void {
        MockClient::global([
            ListAppsRequest::class => MockResponse::make([
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

    it('lists apps for humans', function (): void {
        MockClient::global([
            ListAppsRequest::class => MockResponse::make([
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
            ListAppsRequest::class => MockResponse::make([
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
            ListAppsRequest::class => static function (PendingRequest $pendingRequest): never {
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
    it('shows an app as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowAppRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('project:show', ['project' => '3', '--json' => true])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/projects/3');
    });

    it('shows app details for humans', function (): void {
        MockClient::global([
            ShowAppRequest::class => app_mock_response(),
            ListAppInstancesRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => app_request_id()]]),
        ]);

        expect(Artisan::call('project:show', ['project' => '3']))->toBe(0);
        expect(Artisan::output())->toContain('Project: orbit')
            ->toContain('Orbit')
            ->toContain('git@github.com:nckrtl/orbit.git')
            ->toContain('Default branch')
            ->toContain('main')
            ->toContain('Web root')
            ->toContain('public')
            ->toContain('No Instances.')
            ->not->toContain(app_request_id());
    });

    it('returns legacy null source defaults unchanged', function (): void {
        $payload = [...app_payload(), 'default_branch' => null, 'root' => null];
        MockClient::global([
            ShowAppRequest::class => MockResponse::make([
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
    it('updates an app through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            UpdateAppRequest::class => app_mock_response(),
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
            ->toBeInstanceOf(UpdateAppRequest::class)
            ->and($request?->body()->all())
            ->toBe([
                'repository_url' => 'https://github.com/nckrtl/orbit.git',
                'default_branch' => 'stable',
            ])
            ->and($request?->body()->all())
            ->not
            ->toHaveKey('main_branch');
    });

    it('updates a Project to node-package through the typed SDK request', function (): void {
        $mockClient = MockClient::global([
            UpdateAppRequest::class => app_mock_response(),
        ]);

        $this->artisan('project:update', [
            'project' => '3',
            '--type' => 'node-package',
            '--json' => true,
        ])->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(UpdateAppRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe(['type' => 'node-package']);
    });

    it('reports the updated app for humans', function (): void {
        MockClient::global([UpdateAppRequest::class => app_mock_response()]);

        $this
            ->artisan('project:update', [
                'project' => '3',
                '--slug' => 'orbit',
            ])
            ->expectsOutput('Project [orbit] updated.')
            ->expectsOutputToContain(app_request_id())
            ->assertExitCode(0);
    });

    it('refuses an empty update without gateway IO', function (): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('project:update', ['project' => '3'])
            ->expectsOutputToContain('Provide at least one Project update.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('does not expose a main-branch option', function (): void {
        $definition = $this->app->make(UpdateAppCommand::class)->getDefinition();

        expect($definition->hasOption('default-branch'))
            ->toBeTrue()
            ->and($definition->hasOption('main-branch'))
            ->toBeFalse();
    });
});

describe('project:destroy', function (): void {
    it('removes an app as JSON', function (): void {
        $mockClient = MockClient::global([
            DestroyAppRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('project:destroy', ['project' => '3', '--yes' => true, '--json' => true])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/projects/3');
    });

    it('reports the removed app for humans', function (): void {
        MockClient::global([DestroyAppRequest::class => app_mock_response()]);

        $this
            ->artisan('project:destroy', ['project' => '3', '--yes' => true])
            ->expectsOutput('Project [orbit] removed.')
            ->expectsOutputToContain(app_request_id())
            ->assertExitCode(0);
    });
});

it('rejects invalid app IDs before making an API request', function (string $command, string $appId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, ['project' => $appId])
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
        'default_branch' => 'main',
        'root' => 'public',
        'defaults' => ['php_version' => '8.5'],
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
    $mock = MockClient::global([ShowAppRequest::class => app_mock_response()]);
    $arguments = ['project' => '3', '--no-interaction' => true];

    if ($json) {
        $arguments['--json'] = true;
    }

    expect(Artisan::call($command, $arguments))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Supply --yes to confirm this operation.')
        ->not->toContain($running, "\e[");
    expect($mock->getLastRequest())->toBeInstanceOf(ShowAppRequest::class)
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
        ShowAppRequest::class => MockResponse::make([
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
    expect($mock->getLastRequest())->toBeInstanceOf(ShowAppRequest::class)
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
        ListAppsRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => app_request_id()]]),
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
