<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ScanInstanceDependenciesRequest;
use Orbit\Sdk\Requests\AppInstances\ShowInstanceDependenciesRequest;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyInventoryResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('dependency transport', function (): void {
    it('reads unknown inventory and sends a bodyless numeric request', function (): void {
        $unknown = ['state' => 'unknown', 'succeeded' => null, 'attempted_at' => null, 'error_code' => null, 'snapshot' => null];
        $data = ['instance_id' => 17, 'succeeded' => null,
            'composer' => ['ecosystem' => 'composer', ...$unknown],
            'javascript' => ['ecosystem' => 'npm', ...$unknown]];
        $mock = new MockClient([ShowInstanceDependenciesRequest::class => MockResponse::make([
            'data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mock);
        $dto = $connector->send(new ShowInstanceDependenciesRequest(17))->dto();
        expect($dto)->toBeInstanceOf(InstanceDependencyInventoryResponse::class)
            ->and($dto->toArray())->toBe([...$data, 'request_id' => '11111111-1111-4111-8111-111111111111'])
            ->and((string) $mock->getLastPendingRequest()?->createPsrRequest()->getBody())->toBe('');
    });
});

/** @return array<string, mixed> */
function dependency_envelope(): array
{
    $snapshot = ['observed_at' => '2026-09-15T19:28:20+00:00', 'source' => [
        'project_root' => '/home/orbit/apps/demo', 'reference' => null,
        'file_hashes' => ['package.json' => str_repeat('a', 64), 'yarn.lock' => null], 'format' => null,
    ], 'graph' => ['resolutions' => [], 'requirements' => []]];
    $result = ['state' => 'present', 'succeeded' => true, 'attempted_at' => '2026-09-15T19:28:20+00:00', 'error_code' => null, 'snapshot' => $snapshot];
    $data = ['instance_id' => 17, 'succeeded' => true,
        'composer' => ['ecosystem' => 'composer', ...$result],
        'javascript' => ['ecosystem' => 'npm', ...$result]];
    $resolution = ['id' => 'node_modules/widget', 'ecosystem' => 'npm', 'name' => 'widget', 'version' => '1.0.0',
        'regular' => true, 'development' => true, 'source_reference' => null, 'integrity' => null];
    $other = [...$resolution, 'id' => 'node_modules/tool/node_modules/widget', 'version' => '2.0.0', 'regular' => false];
    $edge = ['from' => null, 'to' => $resolution['id'], 'name' => 'alias', 'constraint' => 'npm:widget@^1',
        'kind' => 'dependency', 'scope' => 'regular', 'optional' => false];
    $data['javascript']['snapshot']['graph'] = ['resolutions' => [$resolution, $other], 'requirements' => [
        $edge, [...$edge, 'scope' => 'development'],
        [...$edge, 'from' => $resolution['id'], 'to' => $other['id'], 'constraint' => '^2'],
        [...$edge, 'to' => null, 'name' => 'host', 'constraint' => '^3', 'kind' => 'peer', 'optional' => true],
    ]];

    return ['data' => $data, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']];
}

function dependency_send(MockResponse $response, bool $scan = true): mixed
{
    $request = $scan ? new ScanInstanceDependenciesRequest(17) : new ShowInstanceDependenciesRequest(17);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient(new MockClient([$request::class => $response]));

    return $connector->send($request)->dto();
}

describe('dependency inventory payloads', function (): void {
    it('preserves complete graph data and exact transport configuration', function (): void {
        $envelope = dependency_envelope();
        $mock = new MockClient([ScanInstanceDependenciesRequest::class => MockResponse::make($envelope)]);
        $connector = new GatewayConnector('https://gateway.test', caPemPath: '/tmp/orbit-ca.pem',
            requestIdResolver: static fn (): string => $envelope['meta']['request_id']);
        $connector->withMockClient($mock);
        $request = new ScanInstanceDependenciesRequest(17);
        $dto = $connector->send($request)->dto();
        $pending = $mock->getLastPendingRequest();
        expect($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']])
            ->and($dto->javascript->snapshot->graph->resolutions[1]->version)->toBe('2.0.0')
            ->and($dto->javascript->snapshot->graph->requirements[3]->to)->toBeNull()
            ->and($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/instances/17/dependencies/scan')
            ->and($pending?->query()->all())->toBe([])
            ->and((string) $pending?->createPsrRequest()->getBody())->toBe('{}')
            ->and($pending?->headers()->get('X-Orbit-Request-Id'))->toBe($envelope['meta']['request_id'])
            ->and($pending?->config()->get('verify'))->toBe('/tmp/orbit-ca.pem')
            ->and($pending?->config()->get('allow_redirects'))->toBeFalse();
    });

    it('preserves absent partial stale and unknown outcomes', function (string $state): void {
        $envelope = dependency_envelope();
        $js = &$envelope['data']['javascript'];
        if ($state === 'absent' || $state === 'stale-absent') {
            $js['snapshot']['graph'] = null;
            $js['state'] = 'absent';
        }
        if ($state === 'stale' || $state === 'stale-absent' || $state === 'first-failure') {
            $js['state'] = $state === 'first-failure' ? 'unknown' : 'stale';
            if ($state === 'first-failure') {
                $js['snapshot'] = null;
            }
            $js['succeeded'] = false;
            $js['error_code'] = 'dependencies.unsupported_format';
            $js['attempted_at'] = '2026-09-15T20:28:20+00:00';
            $envelope['data']['succeeded'] = false;
        }
        if ($state === 'never-scanned') {
            $js = ['ecosystem' => 'npm', 'state' => 'unknown', 'succeeded' => null, 'attempted_at' => null, 'error_code' => null, 'snapshot' => null];
            $envelope['data']['succeeded'] = null;
        }
        $dto = dependency_send(MockResponse::make($envelope), scan: $state !== 'never-scanned');
        expect($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    })->with(['absent', 'stale', 'stale-absent', 'first-failure', 'never-scanned']);

    it('rejects malformed data as a whole with safe correlation', function (Closure $mutate): void {
        $envelope = dependency_envelope();
        $mutate($envelope);
        try {
            dependency_send(MockResponse::make($envelope));
            $this->fail('Malformed inventory was accepted.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())->toBe('Gateway response contains invalid dependency inventory data.')
                ->and($exception->requestId())->toBe('11111111-1111-4111-8111-111111111111')
                ->and($exception->getPrevious())->toBeNull()
                ->and((string) $exception)->not->toContain('sdk-secret-13');
        }
    })->with([
        'wrong instance' => [static function (array &$e): void {
            $e['data']['instance_id'] = 18;
        }],
        'string instance' => [static function (array &$e): void {
            $e['data']['instance_id'] = '17';
        }],
        'missing ecosystem' => [static function (array &$e): void {
            unset($e['data']['javascript']);
        }],
        'extra field' => [static function (array &$e): void {
            $e['data']['raw'] = 'sdk-secret-13';
        }],
        'wrong ecosystem' => [static function (array &$e): void {
            $e['data']['javascript']['ecosystem'] = 'yarn';
        }],
        'overall disagreement' => [static function (array &$e): void {
            $e['data']['succeeded'] = false;
        }],
        'success without snapshot' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot'] = null;
        }],
        'false absence' => [static function (array &$e): void {
            $e['data']['javascript']['state'] = 'absent';
        }],
        'missing attempt' => [static function (array &$e): void {
            $e['data']['javascript']['attempted_at'] = null;
        }],
        'bad date' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['observed_at'] = '2026-02-30T00:00:00+00:00';
        }],
        'failure without code' => [static function (array &$e): void {
            $e['data']['javascript']['succeeded'] = false;
        }],
        'bad code' => [static function (array &$e): void {
            $e['data']['javascript']['error_code'] = 'password=sdk-secret-13';
        }],
        'invalid row' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['graph']['resolutions'][] = null;
        }],
        'duplicate id' => [static function (array &$e): void {
            $g = &$e['data']['javascript']['snapshot']['graph'];
            $g['resolutions'][] = $g['resolutions'][0];
        }],
        'dangling endpoint' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['graph']['requirements'][0]['to'] = 'missing';
        }],
        'string boolean' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['graph']['resolutions'][0]['regular'] = 'false';
        }],
        'cross ecosystem' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['graph']['resolutions'][0]['ecosystem'] = 'composer';
        }],
        'bad scope' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['graph']['requirements'][0]['scope'] = 'dev';
        }],
        'list disguised as object' => [static function (array &$e): void {
            $e['data']['composer']['snapshot']['graph']['resolutions'] = (object) [];
        }],
        'unsafe field' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['source']['reference'] = 'https://u:sdk-secret-13@example.test';
        }],
        'bad hash' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['source']['file_hashes']['package.json'] = 'wrong';
        }],
        'oversized text' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['graph']['resolutions'][0]['version'] = str_repeat('a', 16385);
        }],
        'oversized hashes' => [static function (array &$e): void {
            $e['data']['javascript']['snapshot']['source']['file_hashes'] = array_fill_keys(array_map(static fn (int $n): string => "file$n", range(1, 65)), null);
        }],
        'oversized resolutions' => [static function (array &$e): void {
            $g = &$e['data']['javascript']['snapshot']['graph'];
            $g['resolutions'] = array_map(static fn (int $id): array => [...$g['resolutions'][0], 'id' => (string) $id], range(1, 50001));
        }],
        'oversized requirements' => [static function (array &$e): void {
            $g = &$e['data']['javascript']['snapshot']['graph'];
            $g['requirements'] = array_fill(0, 200001, ['from' => null, 'to' => null, 'name' => 'a', 'constraint' => '*', 'kind' => 'peer', 'scope' => 'regular', 'optional' => true]);
        }],
    ]);

    it('rejects duplicate escaped keys and truncated JSON', function (string $kind): void {
        $body = json_encode(dependency_envelope(), JSON_THROW_ON_ERROR);
        $body = match ($kind) {
            'duplicate' => str_replace('"instance_id":17', '"instance_id":18,"instance_\\u0069d":17', $body),
            'truncated' => substr($body, 0, -1),
            'oversized' => str_repeat(' ', 33554433),
        };
        expect(fn () => dependency_send(MockResponse::make($body, 200, ['X-Orbit-Request-Id' => '11111111-1111-4111-8111-111111111111'])))
            ->toThrow(GatewayApiException::class, 'invalid dependency inventory');
    })->with(['duplicate', 'truncated', 'oversized']);

    it('retains structured refusals and redacts credential-bearing error details', function (int $status, string $code): void {
        $id = '11111111-1111-4111-8111-111111111111';
        try {
            dependency_send(MockResponse::make(['error' => ['code' => $code, 'message' => 'Operation refused.',
                'details' => ['url' => 'https://user:sdk-secret-13@example.test?token=sdk-secret-13']]], $status, ['X-Orbit-Request-Id' => $id]));
            $this->fail('Expected API refusal.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code)->and($exception->requestId())->toBe($id)
                ->and(json_encode($exception->details()))->not->toContain('sdk-secret-13');
        }
    })->with([[403, 'node_access.required'], [404, 'http.404'], [409, 'dependencies.operation_busy'], [422, 'validation.failed'], [500, 'http.500']]);
});

describe('dependency correlation and bounds', function (): void {
    it('rejects invalid missing or conflicting correlation', function (string $case): void {
        $envelope = dependency_envelope();
        $headers = [];
        if ($case === 'conflict') {
            $headers['X-Orbit-Request-Id'] = '22222222-2222-4222-8222-222222222222';
        } elseif ($case === 'missing') {
            unset($envelope['meta']['request_id']);
        } else {
            $envelope['meta']['request_id'] = 'not-a-uuid';
        }
        expect(fn () => dependency_send(MockResponse::make($envelope, 200, $headers)))
            ->toThrow(GatewayApiException::class);
    })->with(['conflict', 'missing', 'invalid']);

    it('accepts the text boundary without losing opaque versions or same-version contexts', function (): void {
        $envelope = dependency_envelope();
        $g = &$envelope['data']['javascript']['snapshot']['graph'];
        $g['resolutions'][0]['version'] = str_repeat('a', 16384);
        $g['resolutions'][1]['version'] = $g['resolutions'][0]['version'];
        $dto = dependency_send(MockResponse::make($envelope));
        expect($dto->javascript->snapshot->graph->resolutions)->toHaveCount(2)
            ->and($dto->javascript->snapshot->graph->resolutions[0]->version)->toBe(str_repeat('a', 16384))
            ->and($dto->javascript->snapshot->graph->resolutions[1]->version)->toBe(str_repeat('a', 16384));
    });

    it('does not accept never-scanned data as a completed scan', function (): void {
        $envelope = dependency_envelope();
        $envelope['data']['javascript'] = ['ecosystem' => 'npm', 'state' => 'unknown', 'succeeded' => null,
            'attempted_at' => null, 'error_code' => null, 'snapshot' => null];
        $envelope['data']['succeeded'] = null;
        expect(fn () => dependency_send(MockResponse::make($envelope)))
            ->toThrow(GatewayApiException::class);
    });

    it('accepts an empty provenance map and preserves numeric-looking opaque identities', function (): void {
        $envelope = dependency_envelope();
        $js = &$envelope['data']['javascript']['snapshot'];
        $js['source']['file_hashes'] = [];
        $js['graph']['resolutions'][0]['name'] = '123';
        $dto = dependency_send(MockResponse::make($envelope));
        expect($dto->javascript->snapshot->source->fileHashes)->toBe([])
            ->and($dto->javascript->snapshot->graph->resolutions[0]->name)->toBe('123');
    });
});

describe('dependency response byte boundary', function (): void {
    it('accepts exactly 32 MiB without truncating the result', function (): void {
        $envelope = dependency_envelope();
        $body = str_pad(json_encode($envelope, JSON_THROW_ON_ERROR), 33554432);
        $dto = dependency_send(MockResponse::make($body));
        expect($dto->toArray())->toBe([...$envelope['data'], 'request_id' => $envelope['meta']['request_id']]);
    });
});
