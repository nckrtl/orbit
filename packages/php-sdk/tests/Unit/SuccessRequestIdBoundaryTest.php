<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Orbit\Sdk\Responses\Processes\ProcessLogsResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead Request-ID boundary assertions stay visible together. */
describe('success request ID boundary', function (): void {
    it('accepts only UUID success metadata request IDs', function (
        array $meta,
        string $expected,
        ?string $unsafe,
    ): void {
        $response = success_request_id_dto(
            new ShowGatewayStatusRequest,
            [
                'data' => [
                    'name' => 'orbit-gateway',
                    'status' => 'ok',
                    'version' => '0.1.0',
                    'php_version' => '8.5.8',
                    'laravel_version' => '13.26.1',
                ],
                'meta' => $meta,
            ],
        );

        expect($response)->toBeInstanceOf(GatewayStatusResponse::class);

        if (! $response instanceof GatewayStatusResponse) {
            $this->fail('Expected typed gateway status response.');
        }

        $diagnostics = json_encode([
            'array' => $response->toArray(),
            'debug' => print_r($response, return: true),
        ]);

        expect($response->requestId)
            ->toBe($expected)
            ->and($response->toArray()['request_id'])
            ->toBe($expected);

        if ($unsafe !== null) {
            expect($diagnostics)->not->toContain($unsafe);
        }
    })->with([
        'UUID v7' => [
            ['request_id' => '019c6ce3-62c8-77aa-8bb1-dd5cbdc6e263'],
            '019c6ce3-62c8-77aa-8bb1-dd5cbdc6e263',
            null,
        ],
        'UUID v4' => [
            ['request_id' => '550E8400-E29B-41D4-A716-446655440000'],
            '550E8400-E29B-41D4-A716-446655440000',
            null,
        ],
        'missing' => [[], '', null],
        'null' => [['request_id' => null], '', null],
        'non-string' => [
            ['request_id' => ['value' => success_request_id_credential()]],
            '',
            success_request_id_credential(),
        ],
        'malformed' => [['request_id' => 'not-a-uuid'], '', 'not-a-uuid'],
        'control data' => [['request_id' => "safe\nX-Token: credential"], '', 'credential'],
        'credential-shaped' => [['request_id' => 'token=success-secret'], '', 'success-secret'],
        'invalid UUID version' => [['request_id' => '550e8400-e29b-91d4-a716-446655440000'], '', null],
        'invalid UUID variant' => [['request_id' => '550e8400-e29b-41d4-7716-446655440000'], '', null],
    ]);

    it('drops unsafe metadata across gateway, collection, and process responses', function (): void {
        $credential = success_request_id_credential();
        $status = success_request_id_dto(
            new ShowGatewayStatusRequest,
            [
                'data' => [
                    'name' => 'orbit-gateway',
                    'status' => 'ok',
                    'version' => '0.1.0',
                    'php_version' => '8.5.8',
                    'laravel_version' => '13.26.1',
                ],
                'meta' => ['request_id' => "request-token={$credential}"],
            ],
        );
        $apps = success_request_id_dto(
            new ListAppsRequest,
            [
                'data' => [[
                    'id' => 1,
                    'name' => 'Orbit',
                    'slug' => 'orbit',
                    'repository_url' => 'https://example.test/orbit.git',
                    'defaults' => null,
                ]],
                'meta' => ['request_id' => "request-token={$credential}"],
            ],
        );
        $logs = success_request_id_dto(
            new ProcessLogsRequest(12),
            [
                'data' => [
                    'id' => 12,
                    'name' => 'worker',
                    'lines' => 1,
                    'logs' => 'Safe log output.',
                ],
                'meta' => ['request_id' => "request-token={$credential}"],
            ],
        );

        expect($status)
            ->toBeInstanceOf(GatewayStatusResponse::class)
            ->and($apps)
            ->toBeInstanceOf(AppsResponse::class)
            ->and($logs)
            ->toBeInstanceOf(ProcessLogsResponse::class);

        if (
            ! $status instanceof GatewayStatusResponse
            || ! $apps instanceof AppsResponse
            || ! $logs instanceof ProcessLogsResponse
        ) {
            $this->fail('Expected typed gateway responses.');
        }

        $diagnostics = implode('\n', [
            (string) json_encode($status->toArray()),
            print_r($status, return: true),
            (string) json_encode($apps->toArray()),
            print_r($apps, return: true),
            (string) json_encode($logs->toArray()),
            print_r($logs, return: true),
        ]);

        expect($status->requestId)
            ->toBeEmpty()
            ->and($status->toArray()['request_id'])
            ->toBeEmpty()
            ->and($apps->requestId)
            ->toBeEmpty()
            ->and($apps->apps[0]->requestId)
            ->toBeEmpty()
            ->and($apps->toArray()['request_id'])
            ->toBeEmpty()
            ->and($logs->requestId)
            ->toBeEmpty()
            ->and($logs->toArray()['request_id'])
            ->toBeEmpty()
            ->and($diagnostics)
            ->not->toContain($credential);
    });

    it('does not retain unsafe success metadata in SDK-owned exception traces', function (): void {
        $credential = success_request_id_credential();
        $body = '{"meta":{"request_id":"request-token='.$credential.'"},"data":'.str_repeat('[', times: 513);
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make($body),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            $diagnostics = implode('\n', [
                $exception->getMessage(),
                (string) $exception,
                print_r($exception, return: true),
                success_request_id_trace_output($exception),
            ]);

            expect($diagnostics)
                ->toContain('SensitiveParameterValue');
            expect($diagnostics)->not->toContain($credential, $body);
        }
    });

    it('detects request-class guard bypasses', function (string $source): void {
        expect(success_request_id_source_violations($source))->not->toBeEmpty();
    })->with([
        'renamed metadata variable' => <<<'PHP'
            public function createDtoFromResponse(#[\SensitiveParameter] Response $response): object
            {
                $requestId = $this->successRequestId($response);
                $context = $this->unwrapMeta($response);
                $requestId = (string) ($context['request_id'] ?? '');
            }
            PHP,
        'direct response JSON path' => <<<'PHP'
            public function createDtoFromResponse(#[\SensitiveParameter] Response $response): object
            {
                $requestId = $this->successRequestId($response);
                $requestId = (string) $response->json('meta.request_id');
            }
            PHP,
        'destructured metadata' => <<<'PHP'
            public function createDtoFromResponse(#[\SensitiveParameter] Response $response): object
            {
                $requestId = $this->successRequestId($response);
                ['request_id' => $requestId] = $context;
            }
            PHP,
        'missing sensitive response boundary' => <<<'PHP'
            public function createDtoFromResponse(Response $response): object
            {
                return new ResponseDto($this->successRequestId($response));
            }
            PHP,
    ]);

    it('prevents ad hoc success metadata request ID extraction in request classes', function (): void {
        $requestDirectory = dirname(__DIR__, levels: 2).'/src/Requests';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($requestDirectory, FilesystemIterator::SKIP_DOTS),
        );
        $violations = [];

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            $relativePath = str_replace(
                search: $requestDirectory.'/',
                replace: '',
                subject: $file->getPathname(),
            );

            if (! str_contains($contents, 'createDtoFromResponse(')) {
                continue;
            }

            $class = 'Orbit\\Sdk\\Requests\\'
            .str_replace(
                search: ['/', '.php'],
                replace: ['\\', ''],
                subject: $relativePath,
            );
            $method = new ReflectionMethod($class, 'createDtoFromResponse');
            $responseParameter = array_find(
                $method->getParameters(),
                static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'response',
            );

            if (
                ! $responseParameter instanceof ReflectionParameter
                || count($responseParameter->getAttributes(SensitiveParameter::class)) !== 1
            ) {
                $violations[] = "{$relativePath}: missing sensitive response boundary";
            }

            foreach (success_request_id_factory_violations(
                success_request_id_factory_source($method),
            ) as $violation) {
                $violations[] = "{$relativePath}: {$violation}";
            }
        }

        expect($violations)->toBeEmpty();
    });
});

/** @param array<string, mixed> $payload */
function success_request_id_dto(GatewayRequest $request, array $payload): object
{
    $mockClient = new MockClient([
        $request::class => MockResponse::make($payload),
    ]);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient($mockClient);

    /** @mago-expect analysis:mixed-assignment Saloon DTO return types are dynamic. */
    $dto = $connector->send($request)->dto();

    if (! is_object($dto)) {
        throw new RuntimeException('Expected a response DTO.');
    }

    return $dto;
}

function success_request_id_credential(): string
{
    return 'success-request-id-credential';
}

/** @return list<string> */
function success_request_id_source_violations(string $contents): array
{
    $violations = success_request_id_factory_violations($contents);

    if (
        str_contains($contents, 'createDtoFromResponse(')
        && preg_match(
            '/createDtoFromResponse\s*\(\s*#\[\s*\\\\SensitiveParameter\s*\]\s*Response\s+\$response/s',
            $contents,
        ) !== 1
    ) {
        $violations[] = 'missing sensitive response boundary';
    }

    return $violations;
}

/** @return list<string> */
function success_request_id_factory_violations(string $contents): array
{
    $violations = [];

    if (
        str_contains($contents, 'createDtoFromResponse(')
        && preg_match('/->successRequestId\s*\(\s*\$response\s*\)/s', $contents) !== 1
    ) {
        $violations[] = 'missing centralized request ID extraction';
    }

    $requestIdLiteralCount = preg_match_all('/[\'\"](?:meta\.)?request_id[\'\"]/', $contents);

    if (is_int($requestIdLiteralCount) && $requestIdLiteralCount > 0) {
        $violations[] = 'raw metadata request ID extraction';
    }

    return $violations;
}

function success_request_id_factory_source(ReflectionMethod $method): string
{
    $fileName = $method->getFileName();

    if (! is_string($fileName)) {
        throw new RuntimeException('Could not locate the request DTO factory source.');
    }

    $lines = file($fileName);

    if (! is_array($lines)) {
        throw new RuntimeException("Could not read the request DTO factory source [{$fileName}].");
    }

    return implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));
}

function success_request_id_trace_output(Throwable $exception): string
{
    $ownedFrames = array_values(array_filter(
        $exception->getTrace(),
        static fn (array $frame): bool => (
            array_key_exists('class', $frame)
            && is_string($frame['class'])
            && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
        ),
    ));

    return print_r($ownedFrames, return: true);
}
