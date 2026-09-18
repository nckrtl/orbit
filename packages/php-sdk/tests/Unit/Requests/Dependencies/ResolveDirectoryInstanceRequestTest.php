<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function directory_response_body(): string
{
    return '{"data":{"instance_id":17,"app_id":3,"node_id":9,"environment":"development"},"meta":{"request_id":"11111111-1111-4111-8111-111111111111"}}';
}

describe('directory resolution transport', function (): void {
    it('preserves the exact directory query and decodes correlated ownership', function (): void {
        $mock = new MockClient([ResolveDirectoryInstanceRequest::class => MockResponse::make(directory_response_body())]);
        $connector = new GatewayConnector('https://gateway.test', caPemPath: '/tmp/fixture-ca.pem');
        $connector->withMockClient($mock);
        $target = $connector->send(new ResolveDirectoryInstanceRequest('/home/orbit/child directory'))->dtoOrFail();
        expect($target->toArray())->toBe(['instance_id' => 17, 'app_id' => 3, 'node_id' => 9, 'environment' => 'development', 'request_id' => '11111111-1111-4111-8111-111111111111']);
        $pending = $mock->getLastPendingRequest();
        expect($pending?->getMethod())->toBe(Method::GET)->and($pending?->getUrl())->toBe('https://gateway.test/api/v1/instances/resolve-directory')
            ->and($pending?->query()->all())->toBe(['directory' => '/home/orbit/child directory'])->and($pending?->body())->toBeNull()
            ->and($pending?->config()->get('verify'))->toBe('/tmp/fixture-ca.pem')->and($pending?->config()->get('allow_redirects'))->toBeFalse();
    });

    it('rejects malformed raw ownership without returning a target', function (string $body, ?string $header): void {
        $mock = new MockClient([ResolveDirectoryInstanceRequest::class => MockResponse::make($body, 200, $header === null ? [] : ['X-Orbit-Request-Id' => $header])]);
        $connector = new GatewayConnector('https://gateway.test', caPemPath: '/tmp/fixture-ca.pem');
        $connector->withMockClient($mock);
        try {
            $connector->send(new ResolveDirectoryInstanceRequest('/private/sentinel'))->dtoOrFail();
            test()->fail('Invalid ownership accepted.');
        } catch (GatewayApiException $error) {
            expect($error->getMessage())->toBe('Gateway response contains invalid instance resolution data.')
                ->and($error->getPrevious())->toBeNull()->and($error->details())->toBe([]);
        }
    })->with([
        [str_replace('"instance_id":17', '"instance_id":17,"instance_id":18', directory_response_body()), null],
        [str_replace('"instance_id":17', '"instance_id":17,"instance_\\u0069d":18', directory_response_body()), null],
        [str_replace('"instance_id":17', '"instance_id":"17"', directory_response_body()), null],
        [str_replace('"node_id":9', '"node_id":0', directory_response_body()), null],
        [str_replace('"app_id":3', '"app_id":false', directory_response_body()), null],
        [str_replace('development', 'unknown', directory_response_body()), null],
        [str_replace('"data":{', '"data":{"extra":"sentinel",', directory_response_body()), null],
        [directory_response_body(), '22222222-2222-4222-8222-222222222222'],
        [str_repeat(' ', 4097), null],
        ['{"data":', null],
    ]);

    it('preserves stable refusal and correlation', function (int $status, string $code): void {
        $id = '11111111-1111-4111-8111-111111111111';
        $mock = new MockClient([ResolveDirectoryInstanceRequest::class => MockResponse::make(['error' => ['code' => $code, 'message' => 'Directory resolution refused.', 'details' => []]], $status, ['X-Orbit-Request-Id' => $id])]);
        $connector = new GatewayConnector('https://gateway.test', caPemPath: '/tmp/fixture-ca.pem');
        $connector->withMockClient($mock);
        try {
            $connector->send(new ResolveDirectoryInstanceRequest('/private/sentinel'))->dtoOrFail();
            test()->fail('Refusal accepted.');
        } catch (GatewayApiException $error) {
            expect($error->errorCode())->toBe($code)->and($error->requestId())->toBe($id);
        }
    })->with([[403, 'node_access.required'], [404, 'dependencies.target_not_found'], [409, 'dependencies.target_ambiguous'], [422, 'dependencies.directory_invalid']]);
});
