<?php

declare(strict_types=1);

use App\Commands\ProxyCli\StatusProxyCliCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\LocalExtensionState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-proxycli-ext-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(LocalExtensionState::class);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('hides and refuses proxycli commands until the extension is enabled', function (): void {
    expect(app(StatusProxyCliCommand::class)->isHidden())->toBeTrue();

    $this->artisan('proxycli:status', ['--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('extension.disabled');

    $this->artisan('extension:enable', ['extension' => 'proxycli', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"enabled":true');

    expect(app(StatusProxyCliCommand::class)->isHidden())->toBeFalse()
        ->and(app(LocalExtensionState::class)->enabled('proxycli'))->toBeTrue();

    $this->artisan('extension:disable', ['extension' => 'proxycli', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"enabled":false');

    expect(app(StatusProxyCliCommand::class)->isHidden())->toBeTrue();
});

it('lists proxycli as an opt-in extension', function (): void {
    expect(app(LocalExtensionState::class)->enabled('proxycli'))->toBeFalse();
    expect(Artisan::call('extension:list', ['--json' => true]))->toBe(0);
    expect(trim(Artisan::output()))->toBe('{"extensions":[{"extension":"herdr","enabled":false},{"extension":"proxycli","enabled":false}]}');
});

it('changes only the local gate and never calls the Gateway', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    app(LocalExtensionState::class)->enable('proxycli');
    $mock = MockClient::global();

    $this->artisan('extension:disable', ['extension' => 'proxycli', '--json' => true, '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"enabled":false');

    expect($mock->getLastPendingRequest())->toBeNull()
        ->and(app(LocalExtensionState::class)->enabled('proxycli'))->toBeFalse();

    $this->artisan('extension:enable', ['extension' => 'proxycli', '--json' => true])
        ->assertExitCode(0);

    expect($mock->getLastPendingRequest())->toBeNull();
});
