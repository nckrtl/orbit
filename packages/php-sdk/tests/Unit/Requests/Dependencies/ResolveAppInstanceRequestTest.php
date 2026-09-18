<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\ResolvedAppInstanceResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function resolution_response(): array
{
    return ['data' => ['domain' => 'fixture.example.test', 'instance_id' => 17, 'app_id' => 3, 'node_id' => 9, 'environment' => 'development'],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']];
}

function resolution_send(MockResponse $response): mixed
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient(new MockClient([ResolveAppInstanceRequest::class => $response]));

    return $connector->send(new ResolveAppInstanceRequest('fixture.example.test'))->dtoOrFail();
}

describe('full-domain SDK resolution', function (): void {
    it('preserves the query and returns only correlated typed ownership', function (): void {
        $mock = new MockClient([ResolveAppInstanceRequest::class => MockResponse::make(resolution_response())]);
        $connector = new GatewayConnector('https://gateway.test', caPemPath: '/tmp/fixture-ca.pem');
        $connector->withMockClient($mock);
        $dto = $connector->send(new ResolveAppInstanceRequest(' FIXTURE.EXAMPLE.TEST '))->dtoOrFail();
        expect($dto)->toBeInstanceOf(ResolvedAppInstanceResponse::class)
            ->and($dto->toArray())->toBe([...resolution_response()['data'], 'request_id' => resolution_response()['meta']['request_id']]);
        $request = $mock->getLastPendingRequest();
        expect($request?->getMethod())->toBe(Method::GET)
            ->and($request?->query()->all())->toBe(['domain' => ' FIXTURE.EXAMPLE.TEST '])
            ->and($request?->getUrl())->toBe('https://gateway.test/api/v1/instances/resolve')
            ->and((string) $request?->createPsrRequest()->getBody())->toBe('')
            ->and($request?->config()->get('verify'))->toBe('/tmp/fixture-ca.pem')
            ->and($request?->config()->get('allow_redirects'))->toBeFalse();
    });

    it('rejects malformed ownership and unsafe or uncorrelated domains', function (string $key, mixed $value): void {
        $response = resolution_response();
        $response['data'][$key] = $value;
        try {
            resolution_send(MockResponse::make($response));
            test()->fail('Invalid response accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe('Gateway response contains invalid instance resolution data.')
                ->not->toContain('sentinel-secret');
        }
    })->with([['instance_id', '17'], ['instance_id', 0], ['node_id', null], ['app_id', -1], ['environment', 'other'],
        ['domain', 'other.example.test'], ['domain', 'https://user:sentinel-secret@fixture.example.test'], ['extra', 'sentinel-secret']]);

    it('rejects missing fields, oversized data and invalid JSON', function (string $kind): void {
        $response = resolution_response();
        if ($kind === 'missing') {
            unset($response['data']['instance_id']);
        } elseif ($kind === 'correlation') {
            $response['meta']['request_id'] = 'sentinel-secret';
        }
        $body = match ($kind) {
            'oversized' => str_repeat(' ', 4097),
            'invalid' => '{',
            default => json_encode($response, JSON_THROW_ON_ERROR),
        };
        expect(fn () => resolution_send(MockResponse::make($body)))->toThrow(GatewayApiException::class);
    })->with(['missing', 'correlation', 'oversized', 'invalid']);

    it('rejects conflicting raw ownership and malformed envelopes safely', function (string $kind): void {
        $body = json_encode(resolution_response(), JSON_THROW_ON_ERROR);
        $body = match ($kind) {
            'duplicate ownership' => str_replace('"instance_id":17', '"instance_id":17,"instance_id":18', $body),
            'escaped duplicate ownership' => str_replace('"instance_id":17', '"instance_id":17,"instance_\u0069d":18', $body),
            'duplicate data' => str_replace('{"data":', '{"data":{"secret":"sentinel-secret"},"data":', $body),
            'duplicate correlation' => str_replace('"request_id":', '"request_id":"22222222-2222-4222-8222-222222222222","request_id":', $body),
            'extra envelope field' => str_replace('{"data":', '{"secret":"sentinel-secret","data":', $body),
            'extra meta field' => str_replace('"meta":{', '"meta":{"secret":"sentinel-secret",', $body),
            'non-object data' => str_replace(json_encode(resolution_response()['data'], JSON_THROW_ON_ERROR), '[]', $body),
            default => $body,
        };
        $headerId = '22222222-2222-4222-8222-222222222222';
        if ($kind !== 'conflicting correlation') {
            $headerId = resolution_response()['meta']['request_id'];
        }
        try {
            resolution_send(MockResponse::make($body, headers: ['X-Orbit-Request-Id' => $headerId]));
            test()->fail('Invalid raw response accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe('Gateway response contains invalid instance resolution data.')
                ->and($exception->requestId())->toBe($headerId)
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->details())->toBe([]);
        }
    })->with(['duplicate ownership', 'escaped duplicate ownership', 'duplicate data', 'duplicate correlation',
        'conflicting correlation', 'extra envelope field', 'extra meta field', 'non-object data']);

    it('accepts bounded raw responses and preserves valid body correlation', function (string $headerId): void {
        $body = json_encode(resolution_response(), JSON_THROW_ON_ERROR);
        $dto = resolution_send(MockResponse::make(str_pad($body, 4096), headers: ['X-Orbit-Request-Id' => $headerId]));
        expect($dto->toArray())->toBe([...resolution_response()['data'], 'request_id' => resolution_response()['meta']['request_id']]);
    })->with(['11111111-1111-4111-8111-111111111111', 'sentinel-secret']);

    it('preserves structured refusals and correlation', function (int $status, string $code): void {
        try {
            resolution_send(MockResponse::make(['error' => ['code' => $code, 'message' => 'Resolution refused.', 'details' => []], 'meta' => resolution_response()['meta']], $status, ['X-Orbit-Request-Id' => resolution_response()['meta']['request_id']]));
            test()->fail('Refusal accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code)->and($exception->requestId())->toBe(resolution_response()['meta']['request_id']);
        }
    })->with([[404, 'dependencies.target_not_found'], [409, 'dependencies.target_ambiguous'], [403, 'node_access.required'], [422, 'dependencies.domain_invalid']]);
});
