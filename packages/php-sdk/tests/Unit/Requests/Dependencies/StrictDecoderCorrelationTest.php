<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ScanInstanceDependenciesRequest;
use Orbit\Sdk\Requests\AppInstances\ShowInstanceDependenciesRequest;
use Orbit\Sdk\Requests\AppInstances\UpdateInstanceDependenciesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;

describe('strict decoder correlation', function (): void {
    it('preserves body correlation with absent matching or invalid headers', function (string $factory, ?string $header): void {
        [$request, $data] = strict_decoder_fixture($factory);
        $id = '11111111-1111-4111-8111-111111111111';
        $response = strict_decoder_response($request, ['data' => $data, 'meta' => ['request_id' => $id]], $header);

        expect($response->dtoOrFail()->toArray())->toBe([...$data, 'request_id' => $id]);
    })->with(['show', 'scan', 'update', 'domain', 'directory'])
        ->with([null, '11111111-1111-4111-8111-111111111111', 'token=strict-decoder-header-secret']);

    it('rejects missing invalid or conflicting body correlation with safe diagnostics', function (
        string $factory,
        array $meta,
        ?string $header,
        ?string $expectedId,
    ): void {
        [$request, $data, $message] = strict_decoder_fixture($factory);

        try {
            strict_decoder_response($request, ['data' => $data, 'meta' => $meta], $header)->dtoOrFail();
            test()->fail('Invalid correlation was accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe($message)
                ->and($exception->requestId())->toBe($expectedId)
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->details())->toBe([])
                ->and(print_r($exception, return: true))->not->toContain('strict-decoder-body-secret', 'strict-decoder-header-secret');
        }
    })->with(['show', 'scan', 'update', 'domain', 'directory'])->with([
        'missing' => [[], null, null],
        'missing with valid header' => [[], '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        'null' => [['request_id' => null], null, null],
        'non-string' => [['request_id' => ['token=strict-decoder-body-secret']], 'token=strict-decoder-header-secret', null],
        'invalid' => [['request_id' => 'token=strict-decoder-body-secret'], null, null],
        'invalid with valid header' => [['request_id' => 'token=strict-decoder-body-secret'], '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        'conflict' => [['request_id' => '11111111-1111-4111-8111-111111111111'], '22222222-2222-4222-8222-222222222222', '22222222-2222-4222-8222-222222222222'],
    ]);

    it('rejects duplicate keys while preserving decoder correlation order', function (string $factory, string $duplicate, ?string $header): void {
        [$request, $data, $message] = strict_decoder_fixture($factory);
        $id = '11111111-1111-4111-8111-111111111111';
        $body = json_encode(['data' => $data, 'meta' => ['request_id' => $id]], JSON_THROW_ON_ERROR);
        $body = match ($duplicate) {
            'ownership' => str_replace('"instance_id":17', '"instance_id":18,"instance_\u0069d":17', $body),
            'correlation' => str_replace('"request_id":', '"request_id":"22222222-2222-4222-8222-222222222222","request_\u0069d":', $body),
        };
        $expectedId = $header === $id || in_array($factory, ['show', 'scan', 'update'], true) ? $id : null;

        try {
            strict_decoder_response($request, $body, $header)->dtoOrFail();
            test()->fail('Duplicate keys were accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe($message)
                ->and($exception->requestId())->toBe($expectedId)
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->details())->toBe([])
                ->and(print_r($exception, return: true))->not->toContain('strict-decoder-header-secret');
        }
    })->with(['show', 'scan', 'update', 'domain', 'directory'])
        ->with(['ownership', 'correlation'])
        ->with([null, '11111111-1111-4111-8111-111111111111', 'token=strict-decoder-header-secret']);

    it('rejects JSON beyond the strict depth limit before trusting body correlation', function (string $factory): void {
        [$request, , $message] = strict_decoder_fixture($factory);
        $body = '{"data":'.str_repeat('[', 16).'"token=strict-decoder-body-secret"'.str_repeat(']', 16).',"meta":{"request_id":"11111111-1111-4111-8111-111111111111"}}';

        try {
            strict_decoder_response($request, $body, null)->dtoOrFail();
            test()->fail('Deep JSON was accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe($message)
                ->and($exception->requestId())->toBeNull()
                ->and(print_r($exception, return: true))->not->toContain('strict-decoder-body-secret');
        }
    })->with(['show', 'scan', 'update', 'domain', 'directory']);
});

/** @return array{GatewayRequest, array<string, mixed>, string} */
function strict_decoder_fixture(string $factory): array
{
    $request = match ($factory) {
        'show' => new ShowInstanceDependenciesRequest(17),
        'scan' => new ScanInstanceDependenciesRequest(17),
        'update' => new UpdateInstanceDependenciesRequest(17),
        'domain' => new ResolveAppInstanceRequest('fixture.example.test'),
        'directory' => new ResolveDirectoryInstanceRequest('/home/orbit/fixture'),
    };
    $failed = ['state' => 'unknown', 'succeeded' => false, 'attempted_at' => '2026-09-16T17:00:00+00:00',
        'error_code' => 'dependencies.unreadable_source', 'snapshot' => null];
    $notRun = ['status' => 'not_run', 'may_have_mutated' => false, 'error_code' => null];
    $ownership = ['instance_id' => 17, 'app_id' => 3, 'node_id' => 9, 'environment' => 'development'];
    $data = match ($factory) {
        'show', 'scan' => ['instance_id' => 17, 'succeeded' => false,
            'composer' => ['ecosystem' => 'composer', ...$failed], 'javascript' => ['ecosystem' => 'npm', ...$failed]],
        'update' => ['instance_id' => 17, 'succeeded' => false, 'error_code' => 'dependencies.production_update_forbidden',
            'may_have_mutated' => false, 'composer' => ['ecosystem' => 'composer', ...$notRun],
            'javascript' => ['ecosystem' => 'npm', ...$notRun], 'inventory' => null],
        'domain' => ['domain' => 'fixture.example.test', ...$ownership],
        'directory' => $ownership,
    };
    $message = match ($factory) {
        'show', 'scan' => 'Gateway response contains invalid dependency inventory data.',
        'update' => 'Gateway response contains invalid dependency update data.',
        'domain', 'directory' => 'Gateway response contains invalid instance resolution data.',
    };

    return [$request, $data, $message];
}

/** @param array<string, mixed>|string $body */
function strict_decoder_response(GatewayRequest $request, array|string $body, ?string $header): Response
{
    $headers = $header === null ? [] : ['X-Orbit-Request-Id' => $header];
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient(new MockClient([$request::class => MockResponse::make($body, headers: $headers)]));

    return $connector->send($request);
}
