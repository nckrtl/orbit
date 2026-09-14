<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\UpdateAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\CreateInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\UpdateInstanceDeployStepRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('deployment requests', function (): void {
    it('uses the exact Gateway methods and paths', function (): void {
        $cases = [
            [new DeployAppInstanceRequest(17), Method::POST, '/api/v1/instances/17/deploy'],
            [new RollbackAppInstanceRequest(17, 'release-20260911'), Method::POST, '/api/v1/instances/17/rollback'],
            [new ListAppInstanceReleasesRequest(17), Method::GET, '/api/v1/instances/17/releases'],
            [new ListInstanceDeployStepsRequest(17), Method::GET, '/api/v1/instances/17/deploy-steps'],
            [new CreateInstanceDeployStepRequest(17, 'migrate', 'php artisan migrate'), Method::POST, '/api/v1/instances/17/deploy-steps'],
            [new UpdateInstanceDeployStepRequest(17, 'migrate', hasCommand: true, command: 'php artisan migrate --force'), Method::PATCH, '/api/v1/instances/17/deploy-steps/migrate'],
            [new DestroyInstanceDeployStepRequest(17, 'migrate'), Method::DELETE, '/api/v1/instances/17/deploy-steps/migrate'],
            [new UpdateAppInstanceRequest(17, 'release'), Method::PATCH, '/api/v1/instances/17'],
        ];

        foreach ($cases as [$request, $method, $path]) {
            expect($request->getMethod())->toBe($method)
                ->and($request->resolveEndpoint())->toBe($path);
        }
    });

    it('carries no deployment-config or deployment-layout request', function (): void {
        $requestFiles = collect(new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src/Requests'),
        ))->filter(
            static fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php',
        )->map(
            static fn (SplFileInfo $file): string => $file->getPathname(),
        );

        expect($requestFiles->implode("\n"))
            ->not->toContain('DeploymentConfig')
            ->not->toContain('DeploymentLayout')
            ->and($requestFiles->values()->all())
            ->not->toContain(dirname(__DIR__, 3).'/src/Requests/Deployments/ShowAppInstanceDeploymentConfigRequest.php')
            ->not->toContain(dirname(__DIR__, 3).'/src/Requests/Deployments/UpdateAppInstanceDeploymentConfigRequest.php')
            ->not->toContain(dirname(__DIR__, 3).'/src/Requests/AppInstances/AppInstanceDeploymentLayoutRequest.php');
    });

    it('preserves deployment payload omission and explicit values', function (): void {
        $omitted = new CreateInstanceDeployStepRequest(17, 'migrate', 'secret-command-one');
        $explicit = new CreateInstanceDeployStepRequest(17, 'restart', 'secret-command-two', 'after_activation', 0);
        $deploy = new DeployAppInstanceRequest(17);
        $rollback = new RollbackAppInstanceRequest(17, '');

        expect($omitted->body()->all())->toBe([
            'name' => 'migrate',
            'command' => 'secret-command-one',
        ])->and($explicit->body()->all())->toBe([
            'name' => 'restart',
            'command' => 'secret-command-two',
            'phase' => 'after_activation',
            'timeout_seconds' => 0,
        ])->and((string) $deploy->body())->toBe('{}')
            ->and($rollback->body()->all())->toBe(['release' => '']);
    });

    it('maps retained releases through typed correlated responses', function (): void {
        $requestId = deployment_request_id();
        $mock = new MockClient([
            ListAppInstanceReleasesRequest::class => MockResponse::make([
                'data' => ['releases' => ['release-b', 'release-a'], 'selected_release' => 'release-b'],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = deployment_connector($mock);

        $releases = $connector->send(new ListAppInstanceReleasesRequest(17))->dto();

        expect($releases)->toBeInstanceOf(DeploymentReleasesResponse::class)
            ->and($releases->releases)->toBe(['release-b', 'release-a'])
            ->and($releases->selectedRelease)->toBe('release-b')
            ->and($releases->requestId)->toBe($requestId);
    });

    it('rejects invalid typed retained-release responses', function (): void {
        $requestId = deployment_request_id();

        expect(fn (): DeploymentReleasesResponse => DeploymentReleasesResponse::fromGatewayData([
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
