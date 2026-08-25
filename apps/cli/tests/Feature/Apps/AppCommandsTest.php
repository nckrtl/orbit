<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
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
            ->toMatchArray([
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

    it('rejects an invalid slug before making an API request', function (string $slug): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('app:new', [
                'slug' => $slug,
                'repository' => 'git@github.com:nckrtl/orbit.git',
            ])
            ->expectsOutputToContain('App slug must contain lowercase letters, numbers, and single hyphens only.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'uppercase' => 'Orbit',
        'space' => 'orbit app',
        'double hyphen' => 'orbit--app',
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
