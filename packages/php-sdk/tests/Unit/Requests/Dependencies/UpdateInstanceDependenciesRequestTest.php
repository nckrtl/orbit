<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\UpdateInstanceDependenciesRequest;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyUpdateResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function dependency_update_inventory(bool $succeeded = true): array
{
    $snapshot = ['observed_at' => '2026-09-16T17:00:00+00:00', 'source' => [
        'project_root' => '/home/orbit/apps/demo', 'reference' => null,
        'file_hashes' => ['composer.json' => str_repeat('a', 64), 'composer.lock' => null], 'format' => null,
    ], 'graph' => ['resolutions' => [], 'requirements' => []]];
    $result = ['state' => 'present', 'succeeded' => true, 'attempted_at' => '2026-09-16T17:00:00+00:00',
        'error_code' => null, 'snapshot' => $snapshot];
    $javascript = $result;
    if (! $succeeded) {
        $javascript['state'] = 'stale';
        $javascript['succeeded'] = false;
        $javascript['error_code'] = 'dependencies.unreadable_source';
        $javascript['attempted_at'] = '2026-09-16T17:05:00+00:00';
    }
    $resolution = ['id' => 'node_modules/widget', 'ecosystem' => 'npm', 'name' => 'widget', 'version' => '1.1.0',
        'regular' => true, 'development' => true, 'source_reference' => null, 'integrity' => null];
    $javascript['snapshot']['graph'] = ['resolutions' => [$resolution], 'requirements' => [
        ['from' => null, 'to' => $resolution['id'], 'name' => 'widget', 'constraint' => '^1.0.0',
            'kind' => 'dependency', 'scope' => 'regular', 'optional' => false],
    ]];

    return [
        'instance_id' => 17,
        'succeeded' => $succeeded,
        'composer' => ['ecosystem' => 'composer', ...$result],
        'javascript' => ['ecosystem' => 'npm', ...$javascript],
    ];
}

/** @return array{data: array<string, mixed>, meta: array{request_id: string}} */
function dependency_update_envelope(): array
{
    $step = ['status' => 'succeeded', 'may_have_mutated' => true, 'error_code' => null];

    return [
        'data' => [
            'instance_id' => 17,
            'succeeded' => true,
            'error_code' => null,
            'may_have_mutated' => true,
            'composer' => ['ecosystem' => 'composer', ...$step],
            'javascript' => ['ecosystem' => 'npm', ...$step],
            'inventory' => dependency_update_inventory(),
        ],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ];
}

function dependency_update_send(MockResponse $response): mixed
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient(new MockClient([UpdateInstanceDependenciesRequest::class => $response]));

    return $connector->send(new UpdateInstanceDependenciesRequest(17))->dto();
}

describe('dependency update transport', function (): void {
    it('sends an empty JSON object to the numeric update endpoint', function (): void {
        $envelope = dependency_update_envelope();
        $mock = new MockClient([UpdateInstanceDependenciesRequest::class => MockResponse::make($envelope)]);
        $connector = new GatewayConnector('https://gateway.test', caPemPath: '/tmp/orbit-ca.pem',
            requestIdResolver: static fn (): string => $envelope['meta']['request_id']);
        $connector->withMockClient($mock);
        $request = new UpdateInstanceDependenciesRequest(17);
        $dto = $connector->send($request)->dto();
        $pending = $mock->getLastPendingRequest();
        expect($dto)->toBeInstanceOf(InstanceDependencyUpdateResponse::class)
            ->and($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']])
            ->and($dto->inventory?->requestId)->toBe($envelope['meta']['request_id'])
            ->and($dto->javascript->status)->toBe('succeeded')
            ->and($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/instances/17/dependencies/update')
            ->and($pending?->query()->all())->toBe([])
            ->and((string) $pending?->createPsrRequest()->getBody())->toBe('{}')
            ->and($pending?->headers()->get('X-Orbit-Request-Id'))->toBe($envelope['meta']['request_id'])
            ->and($pending?->config()->get('verify'))->toBe('/tmp/orbit-ca.pem')
            ->and($pending?->config()->get('allow_redirects'))->toBeFalse();
    });

    it('preserves skipped absent steps and retained inventory correlation', function (): void {
        $envelope = dependency_update_envelope();
        $absent = ['status' => 'absent', 'may_have_mutated' => false, 'error_code' => null];
        $envelope['data']['composer'] = ['ecosystem' => 'composer', ...$absent];
        $envelope['data']['javascript'] = ['ecosystem' => 'npm', ...$absent];
        $envelope['data']['may_have_mutated'] = false;
        $dto = dependency_update_send(MockResponse::make($envelope));
        expect($dto->succeeded)->toBeTrue()
            ->and($dto->composer->status)->toBe('absent')
            ->and($dto->javascript->status)->toBe('absent')
            ->and($dto->mayHaveMutated)->toBeFalse()
            ->and($dto->inventory?->succeeded)->toBeTrue()
            ->and($dto->requestId)->toBe($envelope['meta']['request_id'])
            ->and($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    });

    it('preserves rejected preflight outcomes without inventory', function (): void {
        $envelope = dependency_update_envelope();
        $notRun = ['status' => 'not_run', 'may_have_mutated' => false, 'error_code' => null];
        $envelope['data'] = [
            'instance_id' => 17,
            'succeeded' => false,
            'error_code' => 'dependencies.production_update_forbidden',
            'may_have_mutated' => false,
            'composer' => ['ecosystem' => 'composer', ...$notRun],
            'javascript' => ['ecosystem' => 'npm', ...$notRun],
            'inventory' => null,
        ];
        $dto = dependency_update_send(MockResponse::make($envelope));
        expect($dto->succeeded)->toBeFalse()
            ->and($dto->errorCode)->toBe('dependencies.production_update_forbidden')
            ->and($dto->composer->status)->toBe('not_run')
            ->and($dto->javascript->status)->toBe('not_run')
            ->and($dto->inventory)->toBeNull()
            ->and($dto->requestId)->toBe($envelope['meta']['request_id'])
            ->and($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    });

    it('preserves a failed second step with completed Composer and scan status', function (): void {
        $envelope = dependency_update_envelope();
        $envelope['data']['succeeded'] = false;
        $envelope['data']['javascript'] = [
            'ecosystem' => 'npm', 'status' => 'failed', 'may_have_mutated' => true,
            'error_code' => 'dependencies.update_failed',
        ];
        $dto = dependency_update_send(MockResponse::make($envelope));
        expect($dto->succeeded)->toBeFalse()
            ->and($dto->errorCode)->toBeNull()
            ->and($dto->composer->status)->toBe('succeeded')
            ->and($dto->javascript->status)->toBe('failed')
            ->and($dto->javascript->errorCode)->toBe('dependencies.update_failed')
            ->and($dto->mayHaveMutated)->toBeTrue()
            ->and($dto->inventory?->succeeded)->toBeTrue()
            ->and($dto->inventory?->javascript->snapshot?->graph?->resolutions[0]->version)->toBe('1.1.0')
            ->and($dto->requestId)->toBe($envelope['meta']['request_id']);
    });

    it('preserves successful mutation followed by a failed post-update scan', function (): void {
        $envelope = dependency_update_envelope();
        $envelope['data']['succeeded'] = false;
        $envelope['data']['inventory'] = dependency_update_inventory(false);
        $dto = dependency_update_send(MockResponse::make($envelope));
        expect($dto->succeeded)->toBeFalse()
            ->and($dto->composer->status)->toBe('succeeded')
            ->and($dto->javascript->status)->toBe('succeeded')
            ->and($dto->inventory?->succeeded)->toBeFalse()
            ->and($dto->inventory?->javascript->state)->toBe('stale')
            ->and($dto->inventory?->javascript->errorCode)->toBe('dependencies.unreadable_source')
            ->and($dto->inventory?->composer->succeeded)->toBeTrue()
            ->and($dto->requestId)->toBe($envelope['meta']['request_id']);
    });

    it('preserves an operation failure after completed package work with inventory', function (): void {
        $envelope = dependency_update_envelope();
        $envelope['data']['succeeded'] = false;
        $envelope['data']['error_code'] = 'dependencies.constraints_changed';
        $dto = dependency_update_send(MockResponse::make($envelope));
        expect($dto->succeeded)->toBeFalse()
            ->and($dto->errorCode)->toBe('dependencies.constraints_changed')
            ->and($dto->composer->status)->toBe('succeeded')
            ->and($dto->javascript->status)->toBe('succeeded')
            ->and($dto->mayHaveMutated)->toBeTrue()
            ->and($dto->inventory?->succeeded)->toBeTrue()
            ->and($dto->inventory?->javascript->snapshot?->graph?->resolutions[0]->version)->toBe('1.1.0')
            ->and($dto->requestId)->toBe($envelope['meta']['request_id'])
            ->and($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    });

    it('preserves completed package steps when post-update inventory is missing', function (): void {
        $envelope = dependency_update_envelope();
        $envelope['data']['succeeded'] = false;
        $envelope['data']['inventory'] = null;
        $dto = dependency_update_send(MockResponse::make($envelope));
        expect($dto->succeeded)->toBeFalse()
            ->and($dto->errorCode)->toBeNull()
            ->and($dto->composer->status)->toBe('succeeded')
            ->and($dto->javascript->status)->toBe('succeeded')
            ->and($dto->mayHaveMutated)->toBeTrue()
            ->and($dto->inventory)->toBeNull()
            ->and($dto->requestId)->toBe($envelope['meta']['request_id'])
            ->and($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    });
});

describe('dependency update payload validation', function (): void {
    it('rejects malformed update data as a whole with safe correlation', function (Closure $mutate): void {
        $envelope = dependency_update_envelope();
        $mutate($envelope);
        try {
            dependency_update_send(MockResponse::make($envelope));
            $this->fail('Malformed update result was accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe('Gateway response contains invalid dependency update data.')
                ->and($exception->requestId())->toBe('11111111-1111-4111-8111-111111111111')
                ->and($exception->getPrevious())->toBeNull()
                ->and((string) $exception)->not->toContain('sdk-secret-24');
        }
    })->with([
        'wrong instance' => [static function (array &$e): void {
            $e['data']['instance_id'] = 18;
        }],
        'string instance' => [static function (array &$e): void {
            $e['data']['instance_id'] = '17';
        }],
        'missing step' => [static function (array &$e): void {
            unset($e['data']['javascript']);
        }],
        'extra field' => [static function (array &$e): void {
            $e['data']['raw'] = 'sdk-secret-24';
        }],
        'wrong ecosystem' => [static function (array &$e): void {
            $e['data']['javascript']['ecosystem'] = 'yarn';
        }],
        'overall disagreement' => [static function (array &$e): void {
            $e['data']['succeeded'] = false;
        }],
        'mutation disagreement' => [static function (array &$e): void {
            $e['data']['may_have_mutated'] = false;
        }],
        'success without inventory' => [static function (array &$e): void {
            $e['data']['inventory'] = null;
        }],
        'operation error claiming success' => [static function (array &$e): void {
            $e['data']['error_code'] = 'dependencies.constraints_changed';
        }],
        'failed step without code' => [static function (array &$e): void {
            $e['data']['succeeded'] = false;
            $e['data']['javascript']['status'] = 'failed';
        }],
        'bad step code' => [static function (array &$e): void {
            $e['data']['succeeded'] = false;
            $e['data']['javascript'] = ['ecosystem' => 'npm', 'status' => 'failed', 'may_have_mutated' => true, 'error_code' => 'password=sdk-secret-24'];
        }],
        'succeeded without mutation' => [static function (array &$e): void {
            $e['data']['composer']['may_have_mutated'] = false;
            $e['data']['may_have_mutated'] = true;
        }],
        'not_run with mutation' => [static function (array &$e): void {
            $e['data']['succeeded'] = false;
            $e['data']['javascript'] = ['ecosystem' => 'npm', 'status' => 'not_run', 'may_have_mutated' => true, 'error_code' => null];
        }],
        'inventory instance mismatch' => [static function (array &$e): void {
            $e['data']['inventory']['instance_id'] = 18;
        }],
        'nested extra field' => [static function (array &$e): void {
            $e['data']['inventory']['raw'] = 'sdk-secret-24';
        }],
        'unsafe nested field' => [static function (array &$e): void {
            $e['data']['inventory']['javascript']['snapshot']['source']['reference'] = 'https://u:sdk-secret-24@example.test';
        }],
    ]);

    it('rejects duplicate escaped keys and truncated JSON', function (string $kind): void {
        $body = json_encode(dependency_update_envelope(), JSON_THROW_ON_ERROR);
        $body = match ($kind) {
            'duplicate' => str_replace('"instance_id":17', '"instance_id":18,"instance_\\u0069d":17', $body),
            'truncated' => substr($body, 0, -1),
            'oversized' => str_repeat(' ', 33554433),
        };
        expect(fn () => dependency_update_send(MockResponse::make($body, 200, ['X-Orbit-Request-Id' => '11111111-1111-4111-8111-111111111111'])))
            ->toThrow(GatewayApiException::class, 'invalid dependency update');
    })->with(['duplicate', 'truncated', 'oversized']);

    it('retains structured refusals and redacts credential-bearing error details', function (int $status, string $code): void {
        $id = '11111111-1111-4111-8111-111111111111';
        try {
            dependency_update_send(MockResponse::make(['error' => ['code' => $code, 'message' => 'Operation refused.',
                'details' => ['url' => 'https://user:sdk-secret-24@example.test?token=sdk-secret-24']]], $status, ['X-Orbit-Request-Id' => $id]));
            $this->fail('Expected API refusal.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code)->and($exception->requestId())->toBe($id)
                ->and(json_encode($exception->details()))->not->toContain('sdk-secret-24');
        }
    })->with([[403, 'node_access.required'], [404, 'http.404'], [409, 'dependencies.operation_busy'], [422, 'validation.failed'], [500, 'http.500']]);

    it('rejects invalid missing or conflicting correlation', function (string $case): void {
        $envelope = dependency_update_envelope();
        $headers = [];
        if ($case === 'conflict') {
            $headers['X-Orbit-Request-Id'] = '22222222-2222-4222-8222-222222222222';
        } elseif ($case === 'missing') {
            unset($envelope['meta']['request_id']);
        } else {
            $envelope['meta']['request_id'] = 'not-a-uuid';
        }
        expect(fn () => dependency_update_send(MockResponse::make($envelope, 200, $headers)))
            ->toThrow(GatewayApiException::class);
    })->with(['conflict', 'missing', 'invalid']);

    it('accepts exactly 32 MiB without truncating the result', function (): void {
        $envelope = dependency_update_envelope();
        $body = str_pad(json_encode($envelope, JSON_THROW_ON_ERROR), 33554432);
        $dto = dependency_update_send(MockResponse::make($body));
        expect($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    });
});
