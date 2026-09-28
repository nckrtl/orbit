<?php

declare(strict_types=1);

use App\Commands\ProxyCli\StatusProxyCliCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Support\ExtensionCommandVisibility;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    GatewayExtensionState::reset();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proxycli-ext-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('hides proxycli commands according to the Gateway extension switch', function (bool $enabled): void {
    MockClient::global([ListExtensionsRequest::class => MockResponse::make([
        'data' => ['tasks' => false, 'proxycli' => $enabled], 'meta' => ['request_id' => 'extensions-id'],
    ])]);

    $command = app(StatusProxyCliCommand::class);
    expect($command->isHidden())->toBeFalse()
        ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))
        ->toBe(! $enabled);
})->with(['disabled' => [false], 'enabled' => [true]]);
