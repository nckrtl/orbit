<?php

declare(strict_types=1);

use App\Commands\Instances\TransferInstanceCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\TransferAppInstanceRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-transfer-'.Str::uuid();
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

describe('instance:transfer request', function (): void {
    it('sends one typed transfer mutation after confirmation', function (): void {
        $mock = MockClient::global(transfer_cli_responses());

        $this
            ->artisan('instance:transfer', [
                'instance' => '11',
                'node' => '8',
                '--force' => true,
            ])
            ->assertExitCode(0);

        [$response] = $mock->getRecordedResponses();
        $request = $response->getPendingRequest()->getRequest();

        expect($request)
            ->toBeInstanceOf(TransferAppInstanceRequest::class)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances/11/transfer')
            ->and($request->body()->all())
            ->toBe(['node_id' => 8]);
    });

    it('transports optional rename and SQLite inputs without local policy', function (): void {
        $mock = MockClient::global(transfer_cli_responses());

        $this
            ->artisan('instance:transfer', [
                'instance' => '11',
                'node' => '8',
                '--name' => 'preview',
                '--sqlite-source-path' => '../private/database.sqlite',
                '--force' => true,
            ])
            ->assertExitCode(0);

        [$response] = $mock->getRecordedResponses();
        $request = $response->getPendingRequest()->getRequest();

        expect($request->body()->all())->toBe([
            'node_id' => 8,
            'name' => 'preview',
            'sqlite_source_path' => '../private/database.sqlite',
        ]);
    });

    it('requires positive numeric instance and Node IDs before mutation', function (
        string $instance,
        string $node,
        string $code,
        string $message,
    ): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:transfer', [
            'instance' => $instance,
            'node' => $node,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(transfer_cli_error($code, $message))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    })->with([
        'instance name' => ['web', '8', 'instance.id_invalid', 'Instance ID must be a positive integer.'],
        'zero instance' => ['0', '8', 'instance.id_invalid', 'Instance ID must be a positive integer.'],
        'Node name' => ['11', 'destination', 'node.id_invalid', 'Node ID must be a positive integer.'],
        'zero Node' => ['11', '0', 'node.id_invalid', 'Node ID must be a positive integer.'],
    ]);

    it('requires explicit force consent for noninteractive transfer', function (): void {
        $mock = MockClient::global([
            ShowAppInstanceRequest::class => MockResponse::make(['data' => transfer_cli_payload(), 'meta' => ['request_id' => transfer_cli_request_id()]]),
            ShowNodeRequest::class => MockResponse::make(['data' => ['id' => 8, 'name' => 'destination'], 'meta' => ['request_id' => transfer_cli_request_id()]]),
        ]);

        $exitCode = Artisan::call('instance:transfer', [
            'instance' => '11',
            'node' => '8',
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(Artisan::output())
            ->toContain('Use --force to confirm AppInstance transfer downtime and old-placement deletion.')
            ->and($mock->getRecordedResponses())->toHaveCount(2);
        expect($mock->getLastRequest())->toBeInstanceOf(ShowNodeRequest::class);
    });
});

describe('instance:transfer output', function (): void {
    it('reports destination placement, domain, and completed cleanup', function (): void {
        MockClient::global(transfer_cli_responses());

        expect(Artisan::call('instance:transfer', [
            'instance' => '11',
            'node' => '8',
            '--force' => true,
        ]))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'App instance: preview',
            'ID 11',
            'Destination Node 8',
            'Destination path /srv/orbit/apps/shop/preview',
            'Authoritative domain preview.shop.other.orbit',
            'Cleanup completed',
            'Request ID '.transfer_cli_request_id(),
        );
    });

    it('renders a deterministic JSON result without prompting', function (): void {
        MockClient::global(transfer_cli_responses());

        $exitCode = Artisan::call('instance:transfer', [
            'instance' => '11',
            'node' => '8',
            '--json' => true,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(trim(Artisan::output()))
            ->toBe(json_encode([
                'id' => 11,
                'node_id' => 8,
                'name' => 'preview',
                'checkout_path' => '/srv/orbit/apps/shop/preview',
                'domain' => 'preview.shop.other.orbit',
                'transfer' => transfer_cli_progress(),
                'request_id' => transfer_cli_request_id(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    });
});

describe('instance:transfer help and execution boundary', function (): void {
    it('describes eligibility, destination naming, downtime, and cleanup', function (): void {
        $commands = app(Kernel::class)->all();
        expect($commands['instance:transfer']->getHelp())
            ->toContain(
                'active development AppInstance',
                'same Cluster or another Cluster',
                'independent destination checkout',
                'one selected SQLite snapshot',
                'deletes the old managed placement',
                '--name',
            );
    });

    it('depends only on typed Gateway transport and contains no execution adapter', function (): void {
        $path = (new ReflectionClass(TransferInstanceCommand::class))->getFileName();
        $source = is_string($path) ? file_get_contents($path) : false;

        expect($source)
            ->toBeString()
            ->toContain(TransferAppInstanceRequest::class)
            ->not->toContain('Symfony\\Component\\Process', 'shell_exec', 'proc_open', 'passthru');
    });
});

/** @return array<class-string, MockResponse> */
function transfer_cli_responses(): array
{
    return [
        TransferAppInstanceRequest::class => MockResponse::make([
            'data' => transfer_cli_payload(),
            'meta' => ['request_id' => transfer_cli_request_id()],
        ], 201),
    ];
}

/** @return array<string, mixed> */
function transfer_cli_payload(): array
{
    return [
        'id' => 11,
        'app_id' => 3,
        'node_id' => 8,
        'name' => 'preview',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/shop/preview',
        'production_user' => null,
        'production_home' => null,
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'main',
        'branch_override' => null,
        'migration_required' => false,
        'starting_commit' => str_repeat('a', 40),
        'detached' => true,
        'status' => 'active',
        'route' => [
            'id' => 41,
            'kind' => 'app',
            'app_id' => 3,
            'node_id' => null,
            'cluster_id' => 2,
            'generation_basis_node_id' => 8,
            'domain' => 'preview.shop.other.orbit',
            'provenance' => 'generated',
            'publication' => 'private',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
            'target' => ['id' => 51, 'app_instance_id' => 11, 'position' => 0],
            'process_id' => null,
            'upstream' => null,
        ],
        'domain' => 'preview.shop.other.orbit',
        'url' => 'https://preview.shop.other.orbit',
        'removal' => null,
        'transfer' => transfer_cli_progress(),
        'deploy_steps' => [],
    ];
}

/** @return array<string, mixed> */
function transfer_cli_progress(): array
{
    return [
        'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846',
        'id' => 11,
        'source_node_id' => 7,
        'destination_node_id' => 8,
        'destination_name' => 'preview',
        'destination_path' => '/srv/orbit/apps/shop/preview',
        'destination_domain' => 'preview.shop.other.orbit',
        'sqlite_selected' => false,
        'status' => 'completed',
        'current_step' => 'completed',
        'cutover_completed' => true,
        'cleanup_completed' => true,
        'failed_step' => null,
        'error_code' => null,
        'recovery_evidence' => null,
    ];
}

function transfer_cli_error(string $code, string $message, ?string $requestId = null): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function transfer_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a847';
}
