<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Tools\ToolManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Orbit\Sdk\Responses\Tools\ToolsResponse;

/** @mago-expect lint:halstead The bounded Tool response contracts stay visible together. */
describe('tool responses', function (): void {
    it('maps every tool field without applying Tool policy', function (): void {
        $response = ToolResponse::fromGatewayData(tool_response_data(), tool_response_request_id());

        expect($response->id)
            ->toBe(41)
            ->and($response->nodeId)
            ->toBe(12)
            ->and($response->manager)
            ->toBe('vp')
            ->and($response->package)
            ->toBe('@openai/codex')
            ->and($response->versionConstraint)
            ->toBe('^0.150')
            ->and($response->protected)
            ->toBeFalse()
            ->and($response->status)
            ->toBe('installed')
            ->and($response->installedVersion)
            ->toBe('0.150.0')
            ->and($response->failedOperation)
            ->toBeNull()
            ->and($response->errorCode)
            ->toBeNull()
            ->and($response->outcome)
            ->toBe('applied')
            ->and($response->requestId)
            ->toBe(tool_response_request_id())
            ->and($response->toArray())
            ->toBe([
                'id' => 41,
                'node_id' => 12,
                'manager' => 'vp',
                'package' => '@openai/codex',
                'version_constraint' => '^0.150',
                'protected' => false,
                'status' => 'installed',
                'installed_version' => '0.150.0',
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'applied',
                'request_id' => tool_response_request_id(),
            ]);
    });

    it('maps every manager field without applying manager policy', function (): void {
        $response = ToolManagerResponse::fromGatewayData([
            'id' => 7,
            'node_id' => 12,
            'name' => 'composer',
            'status' => 'failed',
            'installed_version' => '2.9.2',
            'failed_step' => 'materialize',
            'error_code' => 'tool.manager_unavailable',
        ], tool_response_request_id());

        expect($response->toArray())->toBe([
            'id' => 7,
            'node_id' => 12,
            'name' => 'composer',
            'status' => 'failed',
            'installed_version' => '2.9.2',
            'failed_step' => 'materialize',
            'error_code' => 'tool.manager_unavailable',
            'request_id' => tool_response_request_id(),
        ]);
    });

    it('preserves the full bounded package and version constraint', function (): void {
        $package = str_repeat('p', times: 255);
        $constraint = str_repeat('c', times: 255);
        $data = tool_response_data();
        $data['package'] = $package;
        $data['version_constraint'] = $constraint;

        $response = ToolResponse::fromGatewayData($data, tool_response_request_id());

        expect($response->package)
            ->toBe($package)
            ->and($response->versionConstraint)
            ->toBe($constraint);
    });

    it('removes child request IDs from typed collections', function (): void {
        $tool = ToolResponse::fromGatewayData(tool_response_data(), tool_response_request_id());
        $manager = ToolManagerResponse::fromGatewayData([
            'id' => 7,
            'node_id' => 12,
            'name' => 'vp',
            'status' => 'active',
        ], tool_response_request_id());

        $tools = new ToolsResponse([$tool], tool_response_request_id());
        $managers = new ToolManagersResponse([$manager], tool_response_request_id());

        expect($tools->tools)
            ->toBe([$tool])
            ->and($tools->toArray())
            ->toBe([
                'tools' => [[
                    'id' => 41,
                    'node_id' => 12,
                    'manager' => 'vp',
                    'package' => '@openai/codex',
                    'version_constraint' => '^0.150',
                    'protected' => false,
                    'status' => 'installed',
                    'installed_version' => '0.150.0',
                    'failed_operation' => null,
                    'error_code' => null,
                    'outcome' => 'applied',
                ]],
                'request_id' => tool_response_request_id(),
            ])
            ->and($managers->managers)
            ->toBe([$manager])
            ->and($managers->toArray()['managers'][0])
            ->not->toHaveKey('request_id');
    });

    it('rejects malformed required tool fields', function (array $changes): void {
        $data = array_replace(tool_response_data(), $changes);

        expect(fn (): ToolResponse => ToolResponse::fromGatewayData($data, tool_response_request_id()))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'zero id' => [['id' => 0]],
        'negative node id' => [['node_id' => -1]],
        'string id' => [['id' => '41']],
        'missing manager' => [['manager' => null]],
        'empty package' => [['package' => '']],
        'non-boolean protected' => [['protected' => 1]],
        'oversized manager' => [['manager' => str_repeat('m', times: 33)]],
        'oversized package' => [['package' => str_repeat('p', times: 256)]],
        'invalid status token' => [['status' => "installed\nunsafe"]],
    ]);

    it('rejects malformed nullable tool fields', function (string $field): void {
        $data = tool_response_data();
        $data[$field] = ['malformed'];

        expect(fn (): ToolResponse => ToolResponse::fromGatewayData($data, tool_response_request_id()))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'version constraint' => ['version_constraint'],
        'installed version' => ['installed_version'],
        'failed operation' => ['failed_operation'],
        'outcome' => ['outcome'],
    ]);

    it('rejects malformed manager fields', function (array $data): void {
        expect(fn (): ToolManagerResponse => ToolManagerResponse::fromGatewayData(
            $data,
            tool_response_request_id(),
        ))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'invalid id' => [['id' => 0, 'node_id' => 12, 'name' => 'apt', 'status' => 'active']],
        'invalid node id' => [['id' => 1, 'node_id' => false, 'name' => 'apt', 'status' => 'active']],
        'empty name' => [['id' => 1, 'node_id' => 12, 'name' => '', 'status' => 'active']],
        'invalid status token' => [['id' => 1, 'node_id' => 12, 'name' => 'apt', 'status' => 'not active']],
        'wrong nullable type' => [[
            'id' => 1,
            'node_id' => 12,
            'name' => 'apt',
            'status' => 'active',
            'installed_version' => false,
        ]],
    ]);

    it('redacts credential-shaped values from mapped fields and diagnostics', function (): void {
        $credential = 'tool-cred-9f3a7b';
        $data = tool_response_data();
        $data['manager'] = "token={$credential}";
        $data['package'] = "token={$credential}";
        $data['version_constraint'] = "api_token={$credential}";
        $data['installed_version'] = "Bearer {$credential}";
        $tool = ToolResponse::fromGatewayData($data, tool_response_request_id());
        $manager = ToolManagerResponse::fromGatewayData([
            'id' => 7,
            'node_id' => 12,
            'name' => "token={$credential}",
            'status' => 'active',
            'installed_version' => "token={$credential}",
            'failed_step' => "api_token={$credential}",
        ], tool_response_request_id());
        $diagnostics = implode("\n", [
            print_r($tool, return: true),
            serialize($tool),
            json_encode($tool->toArray(), flags: JSON_THROW_ON_ERROR),
            print_r($manager, return: true),
            serialize($manager),
            json_encode($manager->toArray(), flags: JSON_THROW_ON_ERROR),
        ]);

        expect($diagnostics)
            ->toContain('[REDACTED]')
            ->not->toContain($credential);
    });

    it('redacts credential-shaped values passed to public constructors', function (): void {
        $credential = 'direct-tool-cred-9f3a7b';
        $tool = new ToolResponse(
            41,
            12,
            "token={$credential}",
            "https://operator:{$credential}@packages.test/tool",
            "Bearer {$credential}",
            false,
            'installed',
            "api_token={$credential}",
            null,
            null,
            null,
            tool_response_request_id(),
        );
        $manager = new ToolManagerResponse(
            7,
            12,
            "token={$credential}",
            'active',
            "api_token={$credential}",
            "secret={$credential}",
            null,
            tool_response_request_id(),
        );
        $diagnostics = implode("\n", [
            print_r($tool, return: true),
            serialize($tool),
            json_encode($tool->toArray(), flags: JSON_THROW_ON_ERROR),
            print_r($manager, return: true),
            serialize($manager),
            json_encode($manager->toArray(), flags: JSON_THROW_ON_ERROR),
        ]);

        expect($diagnostics)
            ->toContain('[REDACTED]')
            ->not->toContain($credential);
    });

    it('validates direct constructor ingress and marks sensitive parameters', function (): void {
        $valid = [
            41,
            12,
            'vp',
            '@openai/codex',
            '^0.150',
            false,
            'installed',
            null,
            null,
            null,
            'applied',
            tool_response_request_id(),
        ];
        foreach ([0, -1] as $id) {
            expect(fn (): ToolResponse => new ToolResponse(...array_replace($valid, [0 => $id])))
                ->toThrow(InvalidArgumentException::class);
        }
        foreach ([
            ['',                          2],
            [str_repeat('m', times: 33), 2],
            [str_repeat('p', times: 256), 3],
        ] as [$manager, $index]) {
            $values = $valid;
            $values[$index] = $manager;
            expect(fn (): ToolResponse => new ToolResponse(...$values))->toThrow(InvalidArgumentException::class);
        }
        $values = $valid;
        $values[7] = str_repeat('x', times: 256);
        expect(fn (): ToolResponse => new ToolResponse(...$values))->toThrow(InvalidArgumentException::class);
        $values = $valid;
        $values[9] = 'unsafe code';
        expect(new ToolResponse(...$values)->errorCode)->toBeNull();
        $values[11] = 'not-a-request-id';
        expect(new ToolResponse(...$values)->requestId)->toBeEmpty();

        foreach ([ToolResponse::class, ToolManagerResponse::class] as $responseClass) {
            $constructor = new ReflectionMethod($responseClass, '__construct');

            foreach ($constructor->getParameters() as $parameter) {
                if (! $parameter->getType() instanceof ReflectionNamedType) {
                    continue;
                }

                if ($parameter->getType()->getName() !== 'string') {
                    continue;
                }

                expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
            }
        }
    });

    it('preserves explicit empty nullable text through factories and public constructors', function (): void {
        $toolData = tool_response_data();
        $toolData['version_constraint'] = '';
        $toolData['installed_version'] = '';
        $managerData = [
            'id' => 7,
            'node_id' => 12,
            'name' => 'composer',
            'status' => 'active',
            'installed_version' => '',
            'failed_step' => '',
        ];

        expect(ToolResponse::fromGatewayData($toolData, tool_response_request_id())->versionConstraint)
            ->toBeEmpty()
            ->and(ToolResponse::fromGatewayData($toolData, tool_response_request_id())->installedVersion)
            ->toBeEmpty()
            ->and(ToolManagerResponse::fromGatewayData($managerData, tool_response_request_id())->installedVersion)
            ->toBeEmpty()
            ->and(ToolManagerResponse::fromGatewayData($managerData, tool_response_request_id())->failedStep)
            ->toBeEmpty();
    });

    it('redacts constructor arguments from validation traces', function (): void {
        $credential = 'constructor-trace-cred-41f2a7';
        $oversizedConstraint = str_repeat('v', times: 240)." token={$credential}";

        try {
            new ToolResponse(
                41,
                12,
                'vp',
                '@openai/codex',
                $oversizedConstraint,
                false,
                'installed',
                null,
                null,
                null,
                null,
                tool_response_request_id(),
            );
            $this->fail('Expected direct Tool response validation to fail.');
        } catch (InvalidArgumentException $exception) {
            $trace = tool_response_sdk_trace($exception);

            expect($trace)
                ->toContain('SensitiveParameterValue')
                ->not->toContain($credential);
        }
    });

    it('drops unsafe request IDs from items and collections', function (): void {
        $unsafeRequestId = 'token=unsafe-request-id';
        $tool = ToolResponse::fromGatewayData(tool_response_data(), $unsafeRequestId);
        $manager = ToolManagerResponse::fromGatewayData([
            'id' => 7,
            'node_id' => 12,
            'name' => 'apt',
            'status' => 'active',
        ], $unsafeRequestId);

        expect($tool->requestId)
            ->toBeEmpty()
            ->and($manager->requestId)
            ->toBeEmpty()
            ->and(new ToolsResponse([$tool], $unsafeRequestId)->requestId)
            ->toBeEmpty()
            ->and(new ToolManagersResponse([$manager], $unsafeRequestId)->requestId)
            ->toBeEmpty();
    });
});

/** @return array<string, mixed> */
function tool_response_data(): array
{
    return [
        'id' => 41,
        'node_id' => 12,
        'manager' => 'vp',
        'package' => '@openai/codex',
        'version_constraint' => '^0.150',
        'protected' => false,
        'status' => 'installed',
        'installed_version' => '0.150.0',
        'failed_operation' => null,
        'error_code' => null,
        'outcome' => 'applied',
    ];
}

function tool_response_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function tool_response_sdk_trace(Throwable $exception): string
{
    $sdkFrames = array_filter(
        $exception->getTrace(),
        static fn (array $frame): bool => str_starts_with((string) ($frame['class'] ?? ''), 'Orbit\\Sdk\\'),
    );

    return print_r($sdkFrames, return: true);
}
