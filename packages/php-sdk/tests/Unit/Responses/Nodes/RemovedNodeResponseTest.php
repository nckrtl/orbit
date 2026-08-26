<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;

describe(RemovedNodeResponse::class, function (): void {
    it('maps the gateway removal payload and request metadata', function (): void {
        $response = RemovedNodeResponse::fromGatewayData([
            'id' => 12,
            'name' => 'operator',
            'removed' => true,
        ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

        expect($response->toArray())->toBe([
            'id' => 12,
            'name' => 'operator',
            'removed' => true,
            'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ]);
    });

    it('preserves the constructor contract', function (): void {
        $response = new RemovedNodeResponse(
            id: 7,
            name: 'app-dev',
            removed: true,
            requestId: '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );

        expect($response->id)
            ->toBe(7)
            ->and($response->name)
            ->toBe('app-dev')
            ->and($response->removed)
            ->toBeTrue()
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });
});
