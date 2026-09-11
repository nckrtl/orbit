<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\DeploymentStepInput;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentConfigRequest;
use Orbit\Sdk\Requests\Deployments\UpdateAppInstanceDeploymentConfigRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentConfigResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('deployment requests', function (): void {
    it('uses the five exact Gateway methods and paths', function (): void {
        $step = new DeploymentStepInput('migrate', 'before_activation', 'php artisan migrate', null);
        $cases = [
            [new ShowAppInstanceDeploymentConfigRequest(17), Method::GET, '/api/v1/instances/17/deployment-config'],
            [new UpdateAppInstanceDeploymentConfigRequest(17, 'main', [$step]), Method::PUT, '/api/v1/instances/17/deployment-config'],
            [new DeployAppInstanceRequest(17), Method::POST, '/api/v1/instances/17/deploy'],
            [new RollbackAppInstanceRequest(17, 'release-20260911'), Method::POST, '/api/v1/instances/17/rollback'],
            [new ListAppInstanceReleasesRequest(17), Method::GET, '/api/v1/instances/17/releases'],
        ];

        foreach ($cases as [$request, $method, $path]) {
            expect($request->getMethod())->toBe($method)
                ->and($request->resolveEndpoint())->toBe($path);
        }
    });

    it('preserves deployment payload omission and explicit values', function (): void {
        $omitted = new DeploymentStepInput('migrate', 'before_activation', 'secret-command-one');
        $explicit = new DeploymentStepInput('restart', 'after_activation', 'secret-command-two', 0);
        $update = new UpdateAppInstanceDeploymentConfigRequest(17, '', [$omitted, $explicit]);
        $deploy = new DeployAppInstanceRequest(17);
        $rollback = new RollbackAppInstanceRequest(17, '');

        expect($update->body()->all())->toBe([
            'branch' => '',
            'steps' => [
                ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'secret-command-one'],
                [
                    'name' => 'restart',
                    'phase' => 'after_activation',
                    'command' => 'secret-command-two',
                    'timeout_seconds' => 0,
                ],
            ],
        ])->and((string) $deploy->body())->toBe('{}')
            ->and($rollback->body()->all())->toBe(['release' => '']);

        $diagnostics = implode("\n", [
            print_r($omitted, return: true),
            print_r($explicit, return: true),
            print_r($update, return: true),
            (string) json_encode([$omitted, $explicit, $update], JSON_THROW_ON_ERROR),
        ]);

        expect($diagnostics)->not->toContain('secret-command-one', 'secret-command-two');
    });

    it('maps configuration and retained releases through typed correlated responses', function (): void {
        $requestId = deployment_request_id();
        $mock = new MockClient([
            ShowAppInstanceDeploymentConfigRequest::class => MockResponse::make([
                'data' => [
                    'branch' => 'main',
                    'steps' => [[
                        'name' => 'migrate',
                        'phase' => 'before_activation',
                        'command' => 'php artisan migrate',
                        'timeout_seconds' => 300,
                    ]],
                ],
                'meta' => ['request_id' => $requestId],
            ]),
            ListAppInstanceReleasesRequest::class => MockResponse::make([
                'data' => ['releases' => ['release-b', 'release-a'], 'selected_release' => 'release-b'],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = deployment_connector($mock);

        $config = $connector->send(new ShowAppInstanceDeploymentConfigRequest(17))->dto();
        $releases = $connector->send(new ListAppInstanceReleasesRequest(17))->dto();

        expect($config)->toBeInstanceOf(DeploymentConfigResponse::class)
            ->and($config->branch)->toBe('main')
            ->and($config->steps)->toHaveCount(1)
            ->and($config->steps[0]->command)->toBe('php artisan migrate')
            ->and($config->requestId)->toBe($requestId)
            ->and($releases)->toBeInstanceOf(DeploymentReleasesResponse::class)
            ->and($releases->releases)->toBe(['release-b', 'release-a'])
            ->and($releases->selectedRelease)->toBe('release-b')
            ->and($releases->requestId)->toBe($requestId);
    });

    it('rejects invalid typed configuration and retained-release responses', function (): void {
        $requestId = deployment_request_id();

        expect(fn (): DeploymentConfigResponse => DeploymentConfigResponse::fromGatewayData([
            'branch' => 'main',
            'steps' => [[
                'name' => 'migrate',
                'phase' => 'before_activation',
                'command' => 'php artisan migrate',
                'timeout_seconds' => '300',
            ]],
        ], $requestId))->toThrow(
            GatewayApiException::class,
            'Gateway response contains invalid deployment configuration data.',
        )->and(fn (): DeploymentReleasesResponse => DeploymentReleasesResponse::fromGatewayData([
            'releases' => ['release-a', 'release-a'],
            'selected_release' => 'release-a',
        ], $requestId))->toThrow(
            GatewayApiException::class,
            'Gateway response contains invalid retained release data.',
        )->and(fn (): DeploymentReleasesResponse => DeploymentReleasesResponse::fromGatewayData([
            'releases' => ['release-a'],
            'selected_release' => 'release-b',
        ], $requestId))->toThrow(
            GatewayApiException::class,
            'Gateway response contains invalid retained release data.',
        );
    });

    it('returns deployment and rollback as typed streams', function (): void {
        $requestId = deployment_request_id();
        $result = json_encode([
            'type' => 'result',
            'sequence' => 1,
            'request_id' => $requestId,
            'status' => 'succeeded',
            'failed_step' => null,
            'error_code' => null,
            'selected_release' => 'release-a',
        ], JSON_THROW_ON_ERROR)."\n";
        $mock = new MockClient([
            DeployAppInstanceRequest::class => MockResponse::make($result, headers: [
                'Content-Type' => 'application/x-ndjson',
                'X-Orbit-Request-Id' => $requestId,
            ]),
            RollbackAppInstanceRequest::class => MockResponse::make($result, headers: [
                'Content-Type' => 'application/x-ndjson',
                'X-Orbit-Request-Id' => $requestId,
            ]),
        ]);
        $connector = deployment_connector($mock);

        expect($connector->send(new DeployAppInstanceRequest(17))->dto())
            ->toBeInstanceOf(DeploymentStream::class)
            ->and($connector->send(new RollbackAppInstanceRequest(17, 'release-a'))->dto())
            ->toBeInstanceOf(DeploymentStream::class);
    });
});

function deployment_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

function deployment_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
}
