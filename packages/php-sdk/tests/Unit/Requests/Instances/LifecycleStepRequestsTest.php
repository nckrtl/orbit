<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\CreateProjectLifecycleStepRequest;
use Orbit\Sdk\Requests\Instances\DestroyProjectLifecycleStepRequest;
use Orbit\Sdk\Requests\Instances\ListProjectLifecycleStepsRequest;
use Orbit\Sdk\Requests\Instances\SetupAppInstanceRequest;
use Orbit\Sdk\Requests\Instances\UpdateProjectLifecycleStepRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('project lifecycle transport', function (): void {
    it('preserves methods paths omission and explicit input for both lists', function (string $collection): void {
        $create = new CreateProjectLifecycleStepRequest(4, $collection, 'install', 'command-secret', 0, '', 'other');
        $update = new UpdateProjectLifecycleStepRequest(4, $collection, 'install', '', 0);
        $list = new ListProjectLifecycleStepsRequest(4, $collection);
        $destroy = new DestroyProjectLifecycleStepRequest(4, $collection, 'install');
        expect($create->getMethod())->toBe(Method::POST)
            ->and($create->resolveEndpoint())->toBe('/api/v1/projects/4/'.$collection)
            ->and($create->body()->all())->toBe(['name' => 'install', 'command' => 'command-secret', 'timeout_seconds' => 0, 'before' => '', 'after' => 'other'])
            ->and($update->getMethod())->toBe(Method::PATCH)
            ->and($update->resolveEndpoint())->toBe('/api/v1/projects/4/'.$collection.'/install')
            ->and($update->body()->all())->toBe(['command' => '', 'timeout_seconds' => 0])
            ->and((new UpdateProjectLifecycleStepRequest(4, $collection, 'install'))->body()->all())->toBe([])
            ->and($list->getMethod())->toBe(Method::GET)
            ->and($list->resolveEndpoint())->toBe('/api/v1/projects/4/'.$collection)
            ->and($destroy->getMethod())->toBe(Method::DELETE)
            ->and($destroy->resolveEndpoint())->toBe('/api/v1/projects/4/'.$collection.'/install');
    })->with(['setup-steps', 'teardown-steps']);

    it('maps ordered command responses while keeping generic diagnostics redacted', function (): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
        $connector = new GatewayConnector('https://gateway.test');
        $mock = new MockClient([
            ListProjectLifecycleStepsRequest::class => MockResponse::make([
                'data' => [['name' => 'install', 'command' => 'private-lifecycle-command', 'timeout_seconds' => 20]],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector->withMockClient($mock);
        $response = $connector->send(new ListProjectLifecycleStepsRequest(4, 'setup-steps'))->dto();
        expect($response->requestId)->toBe($requestId)
            ->and($response->steps[0]->toArray())->toBe(['name' => 'install', 'command' => 'private-lifecycle-command', 'timeout_seconds' => 20])
            ->and(json_encode($response))->not->toContain('private-lifecycle-command')
            ->and(print_r($response, true))->not->toContain('private-lifecycle-command')
            ->and(fn () => serialize($response))->toThrow(LogicException::class);
    });

    it('bounds malformed response fields and transports the setup operation without a body', function (): void {
        $step = LifecycleStepResponse::fromData(['name' => [], 'command' => false, 'timeout_seconds' => '10']);
        $setup = new SetupAppInstanceRequest(8);
        expect($step->toArray())->toBe(['name' => '', 'command' => '', 'timeout_seconds' => 0])
            ->and($setup->getMethod())->toBe(Method::POST)
            ->and($setup->resolveEndpoint())->toBe('/api/v1/instances/8/setup');
    });
});
