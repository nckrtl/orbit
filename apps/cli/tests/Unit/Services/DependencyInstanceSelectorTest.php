<?php

declare(strict_types=1);

use App\Services\DependencyInstanceSelector;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('dependency domain selector hook', function (): void {
    it('delegates the exact domain to Gateway and preserves owning instance data', function (): void {
        $mock = new MockClient([ResolveAppInstanceRequest::class => MockResponse::make([
            'data' => ['domain' => 'app.example.test', 'instance_id' => 17, 'app_id' => 2, 'node_id' => 9, 'environment' => 'production'],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        $target = new DependencyInstanceSelector()->resolveDomain($connector, ' APP.EXAMPLE.TEST ');
        expect($target->instanceId)->toBe(17)->and($target->nodeId)->toBe(9)->and($target->appId)->toBe(2)
            ->and($target->environment)->toBe('production')->and($target->requestId)->toBe('11111111-1111-4111-8111-111111111111')
            ->and($mock->getLastPendingRequest()?->query()->all())->toBe(['domain' => ' APP.EXAMPLE.TEST ']);
        $mock->assertSentCount(1);
    });

    it('propagates an ambiguous target without fallback or a second request', function (): void {
        $mock = new MockClient([ResolveAppInstanceRequest::class => MockResponse::make([
            'error' => ['code' => 'dependencies.target_ambiguous', 'message' => 'The domain does not select one instance.', 'details' => []],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ], 409, ['X-Orbit-Request-Id' => '11111111-1111-4111-8111-111111111111'])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        try {
            new DependencyInstanceSelector()->resolveDomain($connector, 'app.example.test');
            test()->fail('Ambiguous target accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('dependencies.target_ambiguous')
                ->and($exception->requestId())->toBe('11111111-1111-4111-8111-111111111111');
        }
        $mock->assertSentCount(1);
    });
});

it('canonicalizes the current directory through symlinked ancestors', function (): void {
    // The temporary directory can itself sit behind a symbolic link, such as /var on macOS.
    $root = realpath(sys_get_temp_dir()).'/orbit-directory-'.bin2hex(random_bytes(8));
    mkdir($root.'/physical/child', 0700, true);
    symlink($root.'/physical', $root.'/alias');
    $previous = getcwd();
    $mock = new MockClient([ResolveDirectoryInstanceRequest::class => MockResponse::make([
        'data' => ['instance_id' => 17, 'app_id' => 2, 'node_id' => 9, 'environment' => 'development'],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ])]);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);
    try {
        foreach (['physical', 'alias', 'alias/child'] as $path) {
            chdir($root.'/'.$path);
            $target = new DependencyInstanceSelector()->select($connector);
            expect($target?->instanceId)->toBe(17)->and($mock->getLastPendingRequest()?->query()->all())
                ->toBe(['directory' => $root.'/physical'.(str_ends_with($path, '/child') ? '/child' : '')]);
        }
    } finally {
        chdir($previous);
        unlink($root.'/alias');
        rmdir($root.'/physical/child');
        rmdir($root.'/physical');
        rmdir($root);
    }
});

it('honors explicit domain and all selection even when the working directory disappears', function (): void {
    $root = sys_get_temp_dir().'/orbit-directory-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $previous = getcwd();
    $mock = new MockClient([ResolveAppInstanceRequest::class => MockResponse::make([
        'data' => ['domain' => 'app.example.test', 'instance_id' => 17, 'app_id' => 2, 'node_id' => 9, 'environment' => 'production'],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ])]);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);
    try {
        chdir($root);
        rmdir($root);
        $selector = new DependencyInstanceSelector;
        expect($selector->select($connector, app: 'app.example.test')?->instanceId)->toBe(17)
            ->and($selector->select($connector, all: true))->toBeNull();
        expect(fn () => $selector->select($connector))->toThrow(GatewayApiException::class, 'The current directory is unavailable.')
            ->and(fn () => $selector->select($connector, app: 'app.example.test', all: true))->toThrow(GatewayApiException::class, 'App and all-instance selectors cannot be combined.');
        $mock->assertSentCount(1);
    } finally {
        chdir($previous);
    }
});

it('does not fall back after unmanaged directory refusal', function (): void {
    $mock = new MockClient([ResolveDirectoryInstanceRequest::class => MockResponse::make([
        'error' => ['code' => 'dependencies.target_not_found', 'message' => 'No accessible instance matches the directory.', 'details' => []],
    ], 404)]);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);
    expect(fn () => new DependencyInstanceSelector()->select($connector))->toThrow(GatewayApiException::class);
    $mock->assertSentCount(1);
});
