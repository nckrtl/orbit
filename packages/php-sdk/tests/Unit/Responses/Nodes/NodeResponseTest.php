<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

describe(NodeResponse::class, function (): void {
    it('preserves the original positional constructor contract', function (): void {
        $response = new NodeResponse(
            4,
            'app-dev',
            'active',
            '94.237.40.75',
            22,
            'orbit',
            '10.44.0.3',
            ['app-dev'],
            '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );

        expect($response->publicSshHost)
            ->toBe('94.237.40.75')
            ->and($response->publicSshPort)
            ->toBe(22)
            ->and($response->roles)
            ->toBe(['app-dev'])
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->and($response->platform)
            ->toBeNull()
            ->and($response->architecture)
            ->toBeNull()
            ->and($response->tld)
            ->toBeNull()
            ->and($response->wireguardPublicKey)
            ->toBeNull()
            ->and($response->sshHostFingerprint)
            ->toBeNull()
            ->and($response->failedStep)
            ->toBeNull()
            ->and($response->errorCode)
            ->toBeNull()
            ->and($response->roleAssignments)
            ->toBeEmpty();
    });

    it('preserves the original named constructor contract', function (): void {
        $response = new NodeResponse(
            id: 7,
            name: 'app-prod',
            status: 'active',
            publicSshHost: '85.9.211.193',
            publicSshPort: 22,
            sshUser: 'orbit',
            wireguardAddress: '10.44.0.4',
            roles: ['app-prod'],
            requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        );

        expect($response->name)
            ->toBe('app-prod')
            ->and($response->platform)
            ->toBeNull()
            ->and($response->errorCode)
            ->toBeNull()
            ->and($response->roleAssignments)
            ->toBeEmpty();
    });

    it('does not invent an SSH port for malformed gateway data', function (): void {
        $response = NodeResponse::fromGatewayData(
            ['public_ssh_port' => '22'],
            '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );

        expect($response->publicSshPort)
            ->toBe(0)
            ->and($response->roleAssignments)
            ->toBeEmpty();
    });

    it('fails a present malformed role assignment container closed', function (): void {
        expect(fn (): NodeResponse => NodeResponse::fromGatewayData(
            ['role_assignments' => 'malformed-assignment-container'],
            '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid node role assignment.');
    });

    it('fails a malformed role assignment element closed', function (): void {
        expect(fn (): NodeResponse => NodeResponse::fromGatewayData(
            ['role_assignments' => ['malformed-assignment-element']],
            '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid node role assignment.');
    });
});
