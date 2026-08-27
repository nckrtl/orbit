<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\FetchRootCaCertificateRequest;
use Orbit\Sdk\Responses\Gateway\RootCaCertificateResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('maps the versioned root CA endpoint to a typed response', function (): void {
    $certificate = "-----BEGIN CERTIFICATE-----\nPUBLIC ROOT\n-----END CERTIFICATE-----\n";
    $mockClient = new MockClient([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => $certificate,
                'sha256' => '8a7c80e2',
            ],
            'meta' => [
                'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            ],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    /** @var RootCaCertificateResponse $response */
    $response = $connector->send(new FetchRootCaCertificateRequest)->dto();
    $request = $mockClient->getLastRequest();

    expect($request?->resolveEndpoint())
        ->toBe('/api/v1/ca/root')
        ->and($request?->getMethod())
        ->toBe(Method::GET)
        ->and($response)
        ->toBeInstanceOf(RootCaCertificateResponse::class)
        ->and($response->certificate)
        ->toBe($certificate)
        ->and($response->sha256)
        ->toBe('8a7c80e2')
        ->and($response->requestId)
        ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
        ->and($response->toArray())
        ->toBe([
            'root_ca' => $certificate,
            'sha256' => '8a7c80e2',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
});

it('inherits pinned verification and redirect policy from a normal connector', function (): void {
    $caPemPath = '/home/orbit/.orbit/ca/pinned-root.pem';
    $mockClient = new MockClient([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => "-----BEGIN CERTIFICATE-----\nPUBLIC ROOT\n-----END CERTIFICATE-----\n",
                'sha256' => '8a7c80e2',
            ],
            'meta' => [
                'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            ],
        ]),
    ]);
    $connector = new GatewayConnector(
        baseUrl: 'https://10.44.0.1',
        caPemPath: $caPemPath,
    );
    $connector->withMockClient($mockClient);

    $connector->send(new FetchRootCaCertificateRequest);

    expect($mockClient->getLastPendingRequest()?->config()->all())
        ->toMatchArray([
            'allow_redirects' => false,
            'verify' => $caPemPath,
        ]);
});

it('rejects malformed root CA response fields at the DTO boundary', function (): void {
    $mockClient = new MockClient([
        FetchRootCaCertificateRequest::class => MockResponse::make([
            'data' => [
                'root_ca' => ['not-a-certificate'],
                'sha256' => 123,
            ],
            'meta' => ['request_id' => 456],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    /** @var RootCaCertificateResponse $response */
    $response = $connector->send(new FetchRootCaCertificateRequest)->dto();

    expect($response->certificate)
        ->toBeEmpty()
        ->and($response->sha256)
        ->toBeEmpty()
        ->and($response->requestId)
        ->toBeEmpty();
});
