<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Apps\RemoveAppRequest;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
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

describe('app:new', function (): void {
    it('creates an app through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateAppRequest::class => app_mock_response(201),
        ]);

        $this
            ->artisan('app:new', [
                'slug' => 'orbit',
                'repository' => 'git@github.com:nckrtl/orbit.git',
                '--name' => 'Orbit',
                '--json' => true,
            ])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/apps')
            ->and($request)
            ->toBeInstanceOf(CreateAppRequest::class)
            ->and($request?->body()->all())
            ->toBe([
                'name' => 'Orbit',
                'slug' => 'orbit',
                'repository_url' => 'git@github.com:nckrtl/orbit.git',
            ]);
    });

    it('reports the created app for humans', function (): void {
        MockClient::global([CreateAppRequest::class => app_mock_response(201)]);

        $this
            ->artisan('app:new', [
                'slug' => 'orbit',
                'repository' => 'git@github.com:nckrtl/orbit.git',
            ])
            ->expectsOutput('App [orbit] created.')
            ->expectsOutput('Request ID: '.app_request_id())
            ->assertExitCode(0);
    });

    it('rejects an unbounded or control-bearing slug without disclosure or gateway IO', function (string $slug): void {
        $mockClient = MockClient::global();
        $expected = json_encode([
            'error' => [
                'code' => 'app.slug_invalid',
                'message' => 'App slug is invalid.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('app:new', [
            'slug' => $slug,
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
            ->artisan('app:new', [
                'slug' => 'Orbit App',
                'repository' => 'nckrtl/orbit',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateAppRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe([
                'slug' => 'Orbit App',
                'repository_url' => 'nckrtl/orbit',
            ]);
    });
});

describe('app:new repository boundary', function (): void {
    it('rejects unsafe repository input without disclosure or gateway IO', function (string $repository): void {
        $mockClient = MockClient::global();
        $expected = json_encode([
            'error' => [
                'code' => 'app.repository_invalid',
                'message' => 'Repository URL is invalid.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('app:new', [
            'slug' => 'orbit',
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
            ->artisan('app:new', [
                'slug' => 'orbit',
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
            ->artisan('app:new', [
                'slug' => 'orbit',
                'repository' => $repository,
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateAppRequest::class)
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe([
                'slug' => 'orbit',
                'repository_url' => $repository,
            ]);
    })->with([
        'unrecognized reference' => 'not-a-repository',
        'file scheme' => 'file:///tmp/repository',
        'plain path' => '/tmp/repository',
    ]);
});

describe('app:list', function (): void {
    it('lists apps as JSON', function (): void {
        MockClient::global([
            ListAppsRequest::class => MockResponse::make([
                'data' => [app_payload()],
                'meta' => ['request_id' => app_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'apps' => [app_payload()],
            'request_id' => app_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('app:list', ['--json' => true])
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

        $this
            ->artisan('app:list')
            ->expectsTable(
                ['ID', 'Name', 'Slug', 'Repository'],
                [[3, 'Orbit', 'orbit', 'git@github.com:nckrtl/orbit.git']],
            )
            ->expectsOutput('Request ID: '.app_request_id())
            ->assertExitCode(0);
    });

    it('fails clearly when no gateway profile is active', function (): void {
        new Filesystem()->deleteDirectory($this->orbitHome);
        $mockClient = MockClient::global();

        $this
            ->artisan('app:list')
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

        $exitCode = Artisan::call('app:list', ['--json' => true]);
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
            ->artisan('app:list')
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
            ->artisan('app:list')
            ->expectsOutputToContain('Could not reach the gateway.')
            ->doesntExpectOutputToContain('TLS failed')
            ->doesntExpectOutputToContain('super-secret')
            ->assertExitCode(1);
    });
});

describe('app:show', function (): void {
    it('shows an app as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowAppRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('app:show', ['app' => '3', '--json' => true])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/apps/3');
    });

    it('shows app details for humans', function (): void {
        MockClient::global([ShowAppRequest::class => app_mock_response()]);

        $this
            ->artisan('app:show', ['app' => '3'])
            ->expectsOutput('Orbit [orbit] (#3)')
            ->expectsOutput('Repository: git@github.com:nckrtl/orbit.git')
            ->expectsOutput('Request ID: '.app_request_id())
            ->assertExitCode(0);
    });
});

describe('app:remove', function (): void {
    it('removes an app as JSON', function (): void {
        $mockClient = MockClient::global([
            RemoveAppRequest::class => app_mock_response(),
        ]);

        $this
            ->artisan('app:remove', ['app' => '3', '--json' => true])
            ->expectsOutput(app_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/apps/3');
    });

    it('reports the removed app for humans', function (): void {
        MockClient::global([RemoveAppRequest::class => app_mock_response()]);

        $this
            ->artisan('app:remove', ['app' => '3'])
            ->expectsOutput('App [orbit] removed.')
            ->expectsOutput('Request ID: '.app_request_id())
            ->assertExitCode(0);
    });
});

it('rejects invalid app IDs before making an API request', function (string $command, string $appId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, ['app' => $appId])
        ->expectsOutputToContain('App ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['app:show', '0'],
    'show negative' => ['app:show', '-1'],
    'remove non-numeric' => ['app:remove', 'orbit'],
]);

/** @return array<string, int|string|array<string, string>> */
function app_payload(): array
{
    return [
        'id' => 3,
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
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
