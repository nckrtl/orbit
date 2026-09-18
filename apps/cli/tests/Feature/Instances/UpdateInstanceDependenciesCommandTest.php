<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleInterrupted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\UpdateInstanceDependenciesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-dependency-update-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function update_cli_id(): string
{
    return '11111111-1111-4111-8111-111111111111';
}

/** @return array<string, mixed> */
function update_cli_inventory(bool $succeeded = true): array
{
    $snapshot = ['observed_at' => '2026-09-16T17:00:00+00:00', 'source' => [
        'project_root' => '/home/orbit/apps/demo', 'reference' => null,
        'file_hashes' => ['composer.json' => str_repeat('a', 64), 'composer.lock' => null], 'format' => null,
    ], 'graph' => ['resolutions' => [], 'requirements' => []]];
    $result = ['state' => 'present', 'succeeded' => true, 'attempted_at' => '2026-09-16T17:00:00+00:00',
        'error_code' => null, 'snapshot' => $snapshot];
    $javascript = $result;
    if (! $succeeded) {
        $javascript['state'] = 'stale';
        $javascript['succeeded'] = false;
        $javascript['error_code'] = 'dependencies.unreadable_source';
        $javascript['attempted_at'] = '2026-09-16T17:05:00+00:00';
    }
    $resolution = ['id' => 'node_modules/widget', 'ecosystem' => 'npm', 'name' => 'widget', 'version' => '1.1.0',
        'regular' => true, 'development' => true, 'source_reference' => null, 'integrity' => null];
    $javascript['snapshot']['graph'] = ['resolutions' => [$resolution], 'requirements' => [
        ['from' => null, 'to' => $resolution['id'], 'name' => 'widget', 'constraint' => '^1.0.0',
            'kind' => 'dependency', 'scope' => 'regular', 'optional' => false],
    ]];

    return [
        'instance_id' => 17,
        'succeeded' => $succeeded,
        'composer' => ['ecosystem' => 'composer', ...$result],
        'javascript' => ['ecosystem' => 'npm', ...$javascript],
    ];
}

/**
 * @param  array<string, mixed>|false|null  $inventory
 * @return array{data: array<string, mixed>, meta: array{request_id: string}}
 */
function update_cli_envelope(
    bool $succeeded = true,
    ?string $errorCode = null,
    array|false|null $inventory = false,
    string $composerStatus = 'succeeded',
    string $javascriptStatus = 'succeeded',
    ?bool $mayHaveMutated = null,
): array {
    $composerMutated = $composerStatus === 'succeeded' || $composerStatus === 'failed';
    $javascriptMutated = $javascriptStatus === 'succeeded' || $javascriptStatus === 'failed';
    $composer = [
        'ecosystem' => 'composer',
        'status' => $composerStatus,
        'may_have_mutated' => $composerMutated,
        'error_code' => $composerStatus === 'failed' ? 'dependencies.update_failed' : null,
    ];
    $javascript = [
        'ecosystem' => 'npm',
        'status' => $javascriptStatus,
        'may_have_mutated' => $javascriptMutated,
        'error_code' => $javascriptStatus === 'failed' ? 'dependencies.update_failed' : null,
    ];
    if ($inventory === false) {
        if ($composerStatus === 'not_run' && $javascriptStatus === 'not_run') {
            $inventory = null;
        } elseif ($composerStatus === 'succeeded' && $javascriptStatus === 'succeeded' && $errorCode === null && ! $succeeded) {
            $inventory = update_cli_inventory(false);
        } else {
            $inventory = update_cli_inventory(true);
        }
    }
    $mayHaveMutated ??= ($composer['may_have_mutated'] || $javascript['may_have_mutated']);

    return [
        'data' => [
            'instance_id' => 17,
            'succeeded' => $succeeded,
            'error_code' => $errorCode,
            'may_have_mutated' => $mayHaveMutated,
            'composer' => $composer,
            'javascript' => $javascript,
            'inventory' => $inventory,
        ],
        'meta' => ['request_id' => update_cli_id()],
    ];
}

function update_cli_mock(?array $envelope = null, bool $domain = true): MockClient
{
    $envelope ??= update_cli_envelope();
    $target = ['instance_id' => 17, 'app_id' => 3, 'node_id' => 9, 'environment' => 'development'];
    if ($domain) {
        $target = ['domain' => 'fixture.example.test', ...$target];
    }

    return MockClient::global([
        ($domain ? ResolveAppInstanceRequest::class : ResolveDirectoryInstanceRequest::class) => MockResponse::make([
            'data' => $target,
            'meta' => ['request_id' => update_cli_id()],
        ]),
        UpdateInstanceDependenciesRequest::class => MockResponse::make($envelope),
    ]);
}

describe('single instance dependency update', function (): void {
    it('preserves the complete typed result in one plain JSON document', function (): void {
        $envelope = update_cli_envelope();
        $mock = update_cli_mock($envelope);
        $code = Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        expect($code)->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))
            ->toBe([...$envelope['data'], 'request_id' => update_cli_id()])
            ->and(Artisan::output())->not->toContain("\e", 'Working', 'Resolving')
            ->and($mock->getLastPendingRequest()?->getRequest())->toBeInstanceOf(UpdateInstanceDependenciesRequest::class)
            ->and($mock->getLastPendingRequest()?->getRequest()->resolveEndpoint())->toBe('/api/v1/instances/17/dependencies/update')
            ->and((string) $mock->getLastPendingRequest()?->createPsrRequest()->getBody())->toBe('{}');
    });

    it('uses the directory selector when the domain is omitted', function (): void {
        $mock = update_cli_mock(domain: false);
        expect(Artisan::call('instance:dependencies:update', ['--json' => true]))->toBe(0);
        $request = $mock->getRecordedResponses()[0]->getPendingRequest();
        expect($request->getRequest())->toBeInstanceOf(ResolveDirectoryInstanceRequest::class)
            ->and($request->query()->all())->toBe(['directory' => realpath(getcwd())]);
    });

    it('shows truthful step statuses inventory and terminal outcomes', function (): void {
        update_cli_mock();
        expect(Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--no-interaction' => true,
        ]))->toBe(0);
        $text = Artisan::output();
        expect($text)->toContain(
            'Instance: #17',
            'Composer',
            'JavaScript',
            'succeeded',
            'Dependency update complete.',
            update_cli_id(),
        )->not->toContain("\e", 'Choose', 'Confirm', 'rollback complete');
    });

    it('returns failure for production refusal without claiming mutation', function (): void {
        $envelope = update_cli_envelope(
            succeeded: false,
            errorCode: 'dependencies.production_update_forbidden',
            inventory: null,
            composerStatus: 'not_run',
            javascriptStatus: 'not_run',
            mayHaveMutated: false,
        );
        update_cli_mock($envelope);
        expect(Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--json' => true,
        ]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['succeeded'])->toBeFalse()
            ->and($json['error_code'])->toBe('dependencies.production_update_forbidden')
            ->and($json['may_have_mutated'])->toBeFalse()
            ->and($json['composer']['status'])->toBe('not_run')
            ->and($json['javascript']['status'])->toBe('not_run')
            ->and($json['inventory'])->toBeNull();
    });

    it('returns failure for partial javascript mutation with retained inventory', function (): void {
        $envelope = update_cli_envelope(
            succeeded: false,
            javascriptStatus: 'failed',
            mayHaveMutated: true,
        );
        update_cli_mock($envelope);
        expect(Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--json' => true,
        ]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['succeeded'])->toBeFalse()
            ->and($json['composer']['status'])->toBe('succeeded')
            ->and($json['javascript']['status'])->toBe('failed')
            ->and($json['may_have_mutated'])->toBeTrue()
            ->and($json['inventory']['javascript']['snapshot']['graph']['resolutions'][0]['version'])->toBe('1.1.0');
    });

    it('returns failure when post-update inventory refresh fails', function (): void {
        $envelope = update_cli_envelope(succeeded: false, inventory: update_cli_inventory(false));
        update_cli_mock($envelope);
        expect(Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--no-interaction' => true,
        ]))->toBe(1);
        expect(Artisan::output())->toContain('Dependency update failed.', 'stale', 'dependencies.unreadable_source')
            ->not->toContain('Dependency update complete.', 'rollback complete');
    });

    it('rejects --all before HTTP', function (): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:dependencies:update', ['--all' => true, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('dependencies.all_forbidden')
            ->and($mock->getLastPendingRequest())->toBeNull();
    });

    it('rejects --latest before HTTP', function (): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:dependencies:update', ['--latest' => true, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('dependencies.latest_forbidden')
            ->and($mock->getLastPendingRequest())->toBeNull();
    });

    it('rejects an empty explicit domain without directory fallback', function (): void {
        $mock = MockClient::global();
        expect(Artisan::call('instance:dependencies:update', ['--app' => '', '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('dependencies.domain_invalid')
            ->and($mock->getLastPendingRequest())->toBeNull();
    });

    it('never updates after target refusal', function (): void {
        $mock = MockClient::global([
            ResolveAppInstanceRequest::class => MockResponse::make([
                'error' => ['code' => 'dependencies.target_not_found', 'message' => 'Target refused.', 'details' => []],
            ], 409, ['X-Orbit-Request-Id' => update_cli_id()]),
        ]);
        expect(Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--json' => true,
        ]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['error']['code'])->toBe('dependencies.target_not_found')
            ->and($json['error']['request_id'])->toBe(update_cli_id())
            ->and($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('reports cancellation without claiming rollback', function (): void {
        MockClient::global([
            ResolveAppInstanceRequest::class => function (): never {
                throw new ConsoleInterrupted(2);
            },
        ]);
        expect(Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--json' => true,
        ]))->toBe(1);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['error']['code'])->toBe('input.cancelled')
            ->and($json['error']['message'])->toContain('not rolled back automatically')
            ->and(Artisan::output())->not->toContain('rollback complete');
    });

    it('does not emit progress chrome for noninteractive JSON', function (): void {
        update_cli_mock();
        Artisan::call('instance:dependencies:update', [
            '--app' => 'fixture.example.test',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        expect(Artisan::output())->not->toContain("\e", 'Working', 'Resolving', 'Updating');
    });
});
