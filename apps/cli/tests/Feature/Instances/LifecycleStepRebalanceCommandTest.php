<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CreateProjectLifecycleStepRequest;
use Orbit\Sdk\Requests\Instances\UpdateProjectLifecycleStepRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-lifecycle-rebalance-'.Str::uuid();
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

it('sends repeated rebalance options with a setup step create and a teardown step update', function (string $command, string $request, string $collection): void {
    $mock = MockClient::global([
        $request => MockResponse::make([
            'data' => ['name' => 'browsers', 'command' => 'true', 'timeout_seconds' => 180],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846'],
        ], $request === CreateProjectLifecycleStepRequest::class ? 201 : 200),
    ]);

    $this->artisan($command, [
        'name' => 'browsers',
        '--project' => '4',
        '--command' => 'true',
        '--timeout' => '180',
        '--rebalance' => ['build-assets=360', 'install-js=300'],
        '--json' => true,
    ])->assertExitCode(0);

    $sent = $mock->getLastRequest();

    expect($sent)->toBeInstanceOf($request)
        ->and($sent?->resolveEndpoint())->toStartWith('/api/v1/projects/4/'.$collection)
        ->and($sent?->body()->all()['rebalance'] ?? null)->toBe([
            ['name' => 'build-assets', 'timeout_seconds' => 360],
            ['name' => 'install-js', 'timeout_seconds' => 300],
        ]);
})->with([
    'setup create' => ['instance:setup-step:create', CreateProjectLifecycleStepRequest::class, 'setup-steps'],
    'teardown update' => ['instance:teardown-step:update', UpdateProjectLifecycleStepRequest::class, 'teardown-steps'],
]);
