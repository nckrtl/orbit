<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\SubmitAppDevSetupResultRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(SubmitAppDevSetupResultRequest::class, function (): void {
    it('posts only the local result and maps the active role response', function (): void {
        expect(class_exists(SubmitAppDevSetupResultRequest::class))->toBeTrue();

        $mockClient = new MockClient([
            SubmitAppDevSetupResultRequest::class => MockResponse::make([
                'data' => submit_app_dev_role_data(),
                'meta' => ['request_id' => submit_app_dev_request_id()],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $request = new SubmitAppDevSetupResultRequest(
            exitCode: 0,
            diagnostics: 'Setup completed.',
        );

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/node-role-setups/app-dev/result')
            ->and($request->body()->all())
            ->toBe([
                'exit_code' => 0,
                'diagnostics' => 'Setup completed.',
            ])
            ->and($response)
            ->toBeInstanceOf(NodeRoleResponse::class)
            ->and($response->assignment->status)
            ->toBe('active')
            ->and($response->requestId)
            ->toBe(submit_app_dev_request_id());
    });

    it('preserves direct result values for Gateway validation', function (): void {
        $request = new SubmitAppDevSetupResultRequest(-1, '');

        expect($request->body()->all())->toBe([
            'exit_code' => -1,
            'diagnostics' => '',
        ]);
    });
});

/** @return array<string, mixed> */
function submit_app_dev_role_data(): array
{
    return [
        'node_id' => 12,
        'node_name' => 'mini',
        'assignment' => [
            'role' => 'app-dev',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
            'local_action_required' => false,
            'local_command' => null,
        ],
    ];
}

function submit_app_dev_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
