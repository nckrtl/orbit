<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Projects\CreateProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Requests\Projects\DestroyProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Requests\Projects\ListProjectDevelopmentDeployStepsRequest;
use Orbit\Sdk\Requests\Projects\UpdateProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('Project development deploy transport', function (): void {
    it('preserves methods paths omission and explicit input for both lists', function (): void {
        $create = new CreateProjectDevelopmentDeployStepRequest(4, 'install', 'command-secret', 0, '', 'other', false);
        $update = new UpdateProjectDevelopmentDeployStepRequest(4, 'install', '', 0, required: false);
        $list = new ListProjectDevelopmentDeployStepsRequest(4);
        $destroy = new DestroyProjectDevelopmentDeployStepRequest(4, 'install');
        expect($create->getMethod())->toBe(Method::POST)
            ->and($create->resolveEndpoint())->toBe('/api/v1/projects/4/dev-deploy-steps')
            ->and($create->body()->all())->toBe(['name' => 'install', 'command' => 'command-secret', 'timeout_seconds' => 0, 'before' => '', 'after' => 'other', 'required' => false])
            ->and($update->getMethod())->toBe(Method::PATCH)
            ->and($update->resolveEndpoint())->toBe('/api/v1/projects/4/dev-deploy-steps/install')
            ->and($update->body()->all())->toBe(['command' => '', 'timeout_seconds' => 0, 'required' => false])
            ->and((new UpdateProjectDevelopmentDeployStepRequest(4, 'install'))->body()->all())->toBe([])
            ->and($list->getMethod())->toBe(Method::GET)
            ->and($list->resolveEndpoint())->toBe('/api/v1/projects/4/dev-deploy-steps')
            ->and($destroy->getMethod())->toBe(Method::DELETE)
            ->and($destroy->resolveEndpoint())->toBe('/api/v1/projects/4/dev-deploy-steps/install');
    });

    it('maps ordered command responses while keeping generic diagnostics redacted', function (): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
        $connector = new GatewayConnector('https://gateway.test');
        $mock = new MockClient([
            ListProjectDevelopmentDeployStepsRequest::class => MockResponse::make([
                'data' => [['name' => 'install', 'command' => 'private-lifecycle-command', 'timeout_seconds' => 20, 'required' => false]],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector->withMockClient($mock);
        $response = $connector->send(new ListProjectDevelopmentDeployStepsRequest(4))->dto();
        expect($response->requestId)->toBe($requestId)
            ->and($response->steps[0]->toArray())->toBe(['name' => 'install', 'command' => 'private-lifecycle-command', 'timeout_seconds' => 20, 'required' => false])
            ->and(json_encode($response))->not->toContain('private-lifecycle-command')
            ->and(print_r($response, true))->not->toContain('private-lifecycle-command')
            ->and(fn () => serialize($response))->toThrow(LogicException::class);
    });

    it('maps create update and delete responses with request correlation', function (): void {
        $connector = new GatewayConnector('https://gateway.test');
        $payload = ['name' => 'cache', 'command' => 'private-command', 'timeout_seconds' => 300, 'required' => false];
        $response = MockResponse::make(['data' => $payload, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']]);
        $connector->withMockClient(new MockClient([
            CreateProjectDevelopmentDeployStepRequest::class => $response,
            UpdateProjectDevelopmentDeployStepRequest::class => $response,
            DestroyProjectDevelopmentDeployStepRequest::class => $response,
        ]));
        foreach ([new CreateProjectDevelopmentDeployStepRequest(4, 'cache', 'private-command'), new UpdateProjectDevelopmentDeployStepRequest(4, 'cache', required: false), new DestroyProjectDevelopmentDeployStepRequest(4, 'cache')] as $request) {
            $step = $connector->send($request)->dto();
            expect($step)->toBeInstanceOf(DevelopmentDeployStepResponse::class)
                ->and($step->toArray())->toBe($payload)
                ->and($step->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a846');
        }
    });

    it('bounds malformed fields and defaults required when absent', function (): void {
        $step = DevelopmentDeployStepResponse::fromData(['name' => [], 'command' => false, 'timeout_seconds' => '10', 'required' => 'false']);
        expect($step->toArray())->toBe(['name' => '', 'command' => '', 'timeout_seconds' => 0, 'required' => true]);
        expect((new CreateProjectDevelopmentDeployStepRequest(4, 'install', 'true'))->body()->all())->toBe(['name' => 'install', 'command' => 'true']);
    });
});
