<?php

declare(strict_types=1);

use App\Commands\Instances\CloneInstanceCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\CloneAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-clone-'.Str::uuid();
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

describe('instance:clone request', function (): void {
    it('sends one typed clone mutation and reads the target release state', function (): void {
        $mock = MockClient::global(clone_cli_responses());

        $this
            ->artisan('instance:clone', [
                'candidate' => '11',
                'node' => '7',
                'name' => 'production',
                '--preview-name' => 'shop.com',
            ])
            ->assertExitCode(0);

        [$cloneResponse, $releaseResponse] = $mock->getRecordedResponses();
        $requests = [
            $cloneResponse->getPendingRequest()->getRequest(),
            $releaseResponse->getPendingRequest()->getRequest(),
        ];

        expect($requests)
            ->toHaveCount(2)
            ->and($requests[0])
            ->toBeInstanceOf(CloneAppInstanceRequest::class)
            ->and($requests[0]->resolveEndpoint())
            ->toBe('/api/v1/instances/11/clone')
            ->and($requests[0]->body()->all())
            ->toBe([
                'node_id' => 7,
                'name' => 'production',
                'preview_name' => 'shop.com',
            ])
            ->and($requests[1])
            ->toBeInstanceOf(ListAppInstanceReleasesRequest::class)
            ->and($requests[1]->resolveEndpoint())
            ->toBe('/api/v1/instances/29/releases');
    });

    it('transports optional branch and SQLite inputs without local policy', function (): void {
        $mock = MockClient::global(clone_cli_responses());

        $this
            ->artisan('instance:clone', [
                'candidate' => '11',
                'node' => '7',
                'name' => 'production',
                '--preview-name' => 'shop.com',
                '--branch' => 'feature/production',
                '--sqlite-source-path' => '../private/database.sqlite',
            ])
            ->assertExitCode(0);

        [$cloneResponse] = $mock->getRecordedResponses();
        $requests = [$cloneResponse->getPendingRequest()->getRequest()];

        expect($requests[0]->body()->all())->toBe([
            'node_id' => 7,
            'name' => 'production',
            'preview_name' => 'shop.com',
            'branch' => 'feature/production',
            'sqlite_source_path' => '../private/database.sqlite',
        ]);
    });

    it('requires positive numeric candidate and Node IDs before mutation', function (
        string $candidate,
        string $node,
        string $code,
        string $message,
    ): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:clone', [
            'candidate' => $candidate,
            'node' => $node,
            'name' => 'production',
            '--preview-name' => 'shop.com',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(clone_cli_error($code, $message))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    })->with([
        'candidate name' => ['candidate-name', '7', 'instance.candidate_id_invalid', 'Candidate ID must be a positive integer.'],
        'zero candidate' => ['0', '7', 'instance.candidate_id_invalid', 'Candidate ID must be a positive integer.'],
        'Node name' => ['11', 'production-node', 'node.id_invalid', 'Node ID must be a positive integer.'],
        'zero Node' => ['11', '0', 'node.id_invalid', 'Node ID must be a positive integer.'],
    ]);

    it('requires target and preview names before mutation', function (string $name, string $preview, string $code): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:clone', [
            'candidate' => '11',
            'node' => '7',
            'name' => $name,
            '--preview-name' => $preview,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(clone_cli_error(
                $code,
                $code === 'instance.name_required' ? 'Instance name is required.' : 'Preview name is required.',
            ))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    })->with([
        'target name' => ['', 'shop.com', 'instance.name_required'],
        'preview name' => ['production', '', 'instance.preview_name_required'],
    ]);
});

describe('instance:clone output', function (): void {
    it('reports the target, configured branch, actual preview, and absent first release', function (): void {
        MockClient::global(clone_cli_responses());

        $this
            ->artisan('instance:clone', [
                'candidate' => '11',
                'node' => '7',
                'name' => 'production',
                '--preview-name' => 'shop.com',
            ])
            ->expectsOutput('Production AppInstance [production] cloned.')
            ->expectsOutput('Target ID: 29')
            ->expectsOutput('Configured branch: release')
            ->expectsOutput('Preview hostname: shop.com.prod.orbit')
            ->expectsOutput('Selected release: -')
            ->expectsOutput('Clone request ID: '.clone_cli_request_id())
            ->expectsOutput('Release request ID: '.clone_cli_release_request_id())
            ->assertExitCode(0);
    });

    it('renders a deterministic JSON result without prompting', function (): void {
        MockClient::global(clone_cli_responses());

        $exitCode = Artisan::call('instance:clone', [
            'candidate' => '11',
            'node' => '7',
            'name' => 'production',
            '--preview-name' => 'shop.com',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(trim(Artisan::output()))
            ->toBe(json_encode([
                'target_id' => 29,
                'configured_branch' => 'release',
                'preview_hostname' => 'shop.com.prod.orbit',
                'selected_release' => null,
                'request_ids' => [
                    'clone' => clone_cli_request_id(),
                    'releases' => clone_cli_release_request_id(),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    });

    it('refuses a clone response without its sole preview Route', function (): void {
        $payload = clone_cli_payload();
        $payload['route'] = null;
        $payload['hostname'] = null;
        $payload['url'] = null;
        $mock = MockClient::global([
            CloneAppInstanceRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => clone_cli_request_id()],
            ], 201),
        ]);

        $exitCode = Artisan::call('instance:clone', [
            'candidate' => '11',
            'node' => '7',
            'name' => 'production',
            '--preview-name' => 'shop.com',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(clone_cli_error('gateway.invalid_response', 'Gateway response is invalid.', clone_cli_request_id()))
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('keeps a rejected SQLite path out of the bounded correlated error', function (): void {
        $path = '/srv/candidate/private-clone-secret.sqlite';
        MockClient::global([
            CloneAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'instance.clone_sqlite_source_invalid',
                    'message' => 'The selected SQLite source is invalid.',
                    'details' => ['source' => $path],
                ],
            ], 422, ['X-Orbit-Request-Id' => clone_cli_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:clone', [
            'candidate' => '11',
            'node' => '7',
            'name' => 'production',
            '--preview-name' => 'shop.com',
            '--sqlite-source-path' => $path,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(clone_cli_error(
                'instance.clone_sqlite_source_invalid',
                'The selected SQLite source is invalid.',
                clone_cli_request_id(),
            ))
            ->not->toContain($path);
    });
});

describe('instance:clone help and execution boundary', function (): void {
    it('describes candidate and App definitions, target cleanup, production TLD, environment, and deployment', function (): void {
        $commands = app(Kernel::class)->all();
        expect($commands['instance:clone']->getHelp())
            ->toContain(
                'candidate supplies source, stored environment values, and an optional SQLite snapshot',
                'App supplies production Process and Schedule definitions',
                'Clean application state on the target only',
                'node:provision --tld',
                'env:update and env:sync',
                'instance:deployment-config and instance:deploy',
            )
            ->and($commands['node:provision']->getDefinition()->getOption('tld')->getDescription())
            ->toContain('required for production clone preview hostnames');
    });

    it('depends only on typed Gateway transport and contains no execution adapter', function (): void {
        $path = (new ReflectionClass(CloneInstanceCommand::class))->getFileName();
        $source = is_string($path) ? file_get_contents($path) : false;

        expect($source)
            ->toBeString()
            ->toContain(CloneAppInstanceRequest::class, ListAppInstanceReleasesRequest::class)
            ->not->toContain('Symfony\\Component\\Process', 'shell_exec', 'proc_open', 'passthru');
    });
});

/** @return array<class-string, MockResponse> */
function clone_cli_responses(): array
{
    return [
        CloneAppInstanceRequest::class => MockResponse::make([
            'data' => clone_cli_payload(),
            'meta' => ['request_id' => clone_cli_request_id()],
        ], 201),
        ListAppInstanceReleasesRequest::class => MockResponse::make([
            'data' => ['releases' => [], 'selected_release' => null],
            'meta' => ['request_id' => clone_cli_release_request_id()],
        ]),
    ];
}

/** @return array<string, mixed> */
function clone_cli_payload(): array
{
    return [
        'id' => 29,
        'app_id' => 3,
        'node_id' => 7,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-29/releases/candidate',
        'production_user' => 'orbit-app-29',
        'production_home' => '/home/orbit-app-29',
        'root' => null,
        'effective_root' => '/home/orbit-app-29/current/public',
        'selected_branch' => 'release',
        'branch_override' => null,
        'migration_required' => false,
        'starting_commit' => str_repeat('a', 40),
        'detached' => false,
        'status' => 'active',
        'route' => [
            'id' => 41,
            'app_id' => 3,
            'node_id' => 7,
            'cluster_id' => null,
            'generation_basis_node_id' => null,
            'hostname' => 'shop.com.prod.orbit',
            'provenance' => 'explicit',
            'publication' => 'private',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
            'target' => ['id' => 51, 'app_instance_id' => 29, 'position' => 1],
        ],
        'hostname' => 'shop.com.prod.orbit',
        'url' => 'https://shop.com.prod.orbit',
        'removal' => null,
    ];
}

function clone_cli_error(string $code, string $message, ?string $requestId = null): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function clone_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function clone_cli_release_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a845';
}
