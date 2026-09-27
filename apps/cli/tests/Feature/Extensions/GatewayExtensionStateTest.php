<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    GatewayExtensionState::reset();
    $this->callerOrbitHome = config('orbit.home');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-extension-state-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    GatewayExtensionState::reset();
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

it('reports unknown rows and direct-operation guidance when no profile is active', function (): void {
    $mock = MockClient::global();

    $exit = Artisan::call('extension:list', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(0)
        ->and($output)->toContain('"enabled":null')
        ->and($output)->toContain('gateway.profile_missing');

    $exit = Artisan::call('tasks:list', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(1)
        ->and($output)->toContain('extension.state_unknown')
        ->and($output)->toContain('gateway.profile_missing')
        ->and($output)->toContain('gateway:add');

    $exit = Artisan::call('tasks:status', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(1)
        ->and($output)->toContain('gateway.profile_missing')
        ->and($output)->not->toContain('extension.state_unknown');

    $mock->assertSentCount(0, ListExtensionsRequest::class);
    $mock->assertSentCount(0, ListTaskGroupsRequest::class);
});

it('retains gateway trust guidance when discovery cannot verify the certificate', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $discoveryCalls = 0;
    $mock = MockClient::global([
        ListExtensionsRequest::class => static function (PendingRequest $request) use (&$discoveryCalls): never {
            $discoveryCalls++;
            throw new FatalRequestException(
                new RuntimeException('cURL error 60: SSL certificate problem: self-signed certificate'),
                $request,
            );
        },
    ]);

    $exit = Artisan::call('extension:list', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(0)
        ->and($output)->toContain('gateway.ca_untrusted')
        ->and($output)->toContain('orbit gateway:trust');

    $exit = Artisan::call('proxycli:status', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(1)
        ->and($output)->toContain('extension.state_unknown')
        ->and($output)->toContain('orbit gateway:trust');

    expect($discoveryCalls)->toBe(1);
    $mock->assertSentCount(0, ListExtensionsRequest::class);
});

it('reports a bounded unknown state when discovery times out', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $discoveryCalls = 0;
    $mock = MockClient::global([
        ListExtensionsRequest::class => static function (PendingRequest $request) use (&$discoveryCalls): never {
            $discoveryCalls++;
            throw new FatalRequestException(
                new RuntimeException('cURL error 28: Operation timed out after 2000 milliseconds'),
                $request,
            );
        },
    ]);

    $exit = Artisan::call('extension:list', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(0)
        ->and($output)->toContain('gateway.unreachable')
        ->and($output)->toContain('timed out after 2 seconds');

    $exit = Artisan::call('tasks:list', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(1)
        ->and($output)->toContain('extension.state_unknown')
        ->and($output)->toContain('timed out after 2 seconds')
        ->and($discoveryCalls)->toBe(1);

    $mock->assertSentCount(0, ListExtensionsRequest::class);
});
