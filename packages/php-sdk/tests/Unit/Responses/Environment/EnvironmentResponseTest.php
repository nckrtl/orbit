<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Environment\ImportAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\SynchronizeAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\UpdateAppInstanceEnvironmentRequest;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead Response safety assertions stay visible together. */
describe('AppInstance environment response', function (): void {
    it('exposes one immutable bounded correlated operation result', function (): void {
        $mock = new MockClient([
            UpdateAppInstanceEnvironmentRequest::class => MockResponse::make(environment_response_success_envelope(
                'update',
            )),
        ]);
        $response = environment_response_connector($mock)
            ->send(new UpdateAppInstanceEnvironmentRequest(17, 'KEY', 'value'))
            ->dto();

        expect($response)
            ->toBeInstanceOf(EnvironmentOperationResponse::class)
            ->and($response->appInstanceId)
            ->toBe(17)
            ->and($response->operation)
            ->toBe('update')
            ->and($response->changed)
            ->toBeTrue()
            ->and($response->keyCount)
            ->toBe(3)
            ->and($response->requestId)
            ->toBe('11111111-1111-4111-8111-111111111111')
            ->and($response->toArray())
            ->toBe([
                'app_instance_id' => 17,
                'operation' => 'update',
                'changed' => true,
                'key_count' => 3,
                'request_id' => '11111111-1111-4111-8111-111111111111',
            ])
            ->and(unserialize(serialize($response)))
            ->toEqual($response);
    });

    it('rejects missing wrongly typed contradictory extra and out-of-bound data safely', function (array $data): void {
        $sentinel = 'malformed-environment-value-8c2d';
        $mock = new MockClient([
            ImportAppInstanceEnvironmentRequest::class => MockResponse::make([
                'data' => $data,
                'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
            ]),
        ]);

        try {
            environment_response_connector($mock)->send(new ImportAppInstanceEnvironmentRequest(17))->dto();
            $this->fail('Expected malformed environment response rejection.');
        } catch (GatewayApiException $exception) {
            $diagnostics = implode("\n", [
                $exception->getMessage(),
                (string) $exception,
                print_r($exception, return: true),
                environment_sdk_trace($exception),
            ]);

            expect($exception->getMessage())
                ->toBe('Gateway response contains invalid environment operation data.')
                ->and($exception->requestId())
                ->toBe('11111111-1111-4111-8111-111111111111')
                ->and($exception->details())
                ->toBeEmpty()
                ->and($exception->getPrevious())
                ->toBeNull()
                ->and($diagnostics)
                ->not->toContain($sentinel);
        }
    })->with([
        'missing id' => [[
            'operation' => 'import',
            'changed' => true,
            'key_count' => 1,
        ]],
        'non-positive id' => [[
            'app_instance_id' => 0,
            'operation' => 'import',
            'changed' => true,
            'key_count' => 1,
        ]],
        'wrong changed type' => [[
            'app_instance_id' => 17,
            'operation' => 'import',
            'changed' => 1,
            'key_count' => 1,
        ]],
        'negative key count' => [[
            'app_instance_id' => 17,
            'operation' => 'import',
            'changed' => true,
            'key_count' => -1,
        ]],
        'excessive key count' => [[
            'app_instance_id' => 17,
            'operation' => 'import',
            'changed' => true,
            'key_count' => 1_025,
        ]],
        'contradictory operation' => [[
            'app_instance_id' => 17,
            'operation' => 'sync',
            'changed' => true,
            'key_count' => 1,
        ]],
        'extra value' => [[
            'app_instance_id' => 17,
            'operation' => 'import',
            'changed' => true,
            'key_count' => 1,
            'value' => 'malformed-environment-value-8c2d',
        ]],
        'value-bearing collection' => [[
            'app_instance_id' => 17,
            'operation' => 'import',
            'changed' => true,
            'key_count' => 1,
            'environment' => ['VISIBLE_NAME' => 'malformed-environment-value-8c2d'],
        ]],
    ]);

    it('rejects missing or invalid request correlation safely', function (array $meta): void {
        $mock = new MockClient([
            SynchronizeAppInstanceEnvironmentRequest::class => MockResponse::make([
                'data' => [
                    'app_instance_id' => 17,
                    'operation' => 'sync',
                    'changed' => false,
                    'key_count' => 0,
                ],
                'meta' => $meta,
            ]),
        ]);

        expect(
            fn (): mixed => environment_response_connector($mock)
                ->send(new SynchronizeAppInstanceEnvironmentRequest(17))
                ->dto(),
        )
            ->toThrow(GatewayApiException::class, 'Gateway response contains invalid environment operation data.');
    })->with([
        'missing' => [[]],
        'invalid' => [['request_id' => 'remote-content-should-not-survive']],
    ]);

    it('retains named Gateway failures and request IDs without remote content', function (string $errorCode): void {
        $sentinel = 'ordinary-remote-content-6a9e';
        $requestId = '22222222-2222-4222-8222-222222222222';
        $request = new SynchronizeAppInstanceEnvironmentRequest(17);
        $mock = new MockClient([
            SynchronizeAppInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => $errorCode,
                        'message' => "Failure contains {$sentinel}",
                        'details' => ['VISIBLE_NAME' => $sentinel],
                    ],
                ],
                409,
                ['X-Orbit-Request-Id' => $requestId],
            ),
        ]);
        $connector = environment_response_connector($mock);

        try {
            $connector->send($request)->dto();
            $this->fail('Expected an environment Gateway failure.');
        } catch (GatewayApiException $exception) {
            $response = $mock->getLastResponse();

            if ($response === null) {
                $this->fail('Expected a recorded environment response.');
            }

            $translated = $request->getRequestException(
                $response,
                new RuntimeException("Transport contained {$sentinel}"),
            );

            expect($translated)->toBeInstanceOf(GatewayApiException::class);

            if (! $translated instanceof GatewayApiException) {
                $this->fail('Expected a translated environment exception.');
            }

            $diagnostics = implode("\n", [
                $exception->getMessage(),
                (string) $exception,
                print_r($exception, return: true),
                environment_sdk_trace($exception),
                $translated->getMessage(),
                (string) $translated,
                print_r($translated, return: true),
                environment_sdk_trace($translated),
            ]);

            foreach ([$exception, $translated] as $failure) {
                expect($failure->errorCode())
                    ->toBe($errorCode)
                    ->and($failure->requestId())
                    ->toBe($requestId)
                    ->and($failure->details())
                    ->toBeEmpty()
                    ->and($failure->getPrevious())
                    ->toBeNull();
            }

            expect($diagnostics)->not->toContain($sentinel);
        }
    })->with([
        'import conflict' => ['env.import_conflict'],
        'unavailable target' => ['env.owner_unavailable'],
        'failed preflight' => ['env.write_preflight_failed'],
        'unresolved reference' => ['env.reference_unavailable'],
        'failed synchronization' => ['env.write_failed'],
        'unconfirmed synchronization' => ['env.sync_unconfirmed'],
    ]);

    it('rejects a malformed value-bearing response without retaining its body', function (): void {
        $sentinel = 'malformed-body-environment-value-b3e1';
        $mock = new MockClient([
            ImportAppInstanceEnvironmentRequest::class => MockResponse::make("not-json {$sentinel}"),
        ]);

        try {
            environment_response_connector($mock)->send(new ImportAppInstanceEnvironmentRequest(17))->dto();
            $this->fail('Expected malformed response rejection.');
        } catch (GatewayApiException $exception) {
            $diagnostics = implode("\n", [
                $exception->getMessage(),
                (string) $exception,
                print_r($exception, return: true),
                environment_sdk_trace($exception),
            ]);

            expect($diagnostics)->not->toContain($sentinel);
        }
    });
});

function environment_response_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array{data: array{app_instance_id: int, operation: string, changed: bool, key_count: int}, meta: array{request_id: string}} */
function environment_response_success_envelope(string $operation): array
{
    return [
        'data' => [
            'app_instance_id' => 17,
            'operation' => $operation,
            'changed' => true,
            'key_count' => 3,
        ],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ];
}

function environment_sdk_trace(Throwable $exception): string
{
    $frames = array_values(array_filter(
        $exception->getTrace(),
        static fn (array $frame): bool => (
            array_key_exists('class', $frame)
            && is_string($frame['class'])
            && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
        ),
    ));

    return print_r($frames, return: true);
}
