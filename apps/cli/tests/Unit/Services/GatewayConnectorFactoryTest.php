<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Services\GatewayConnectorFactory;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Support\GatewayReadRetry;
use Psr\Http\Message\RequestInterface;
use Saloon\Http\Senders\GuzzleSender;

describe(GatewayConnectorFactory::class, function (): void {
    it('keeps the SDK read retry around the CLI network handler', function (): void {
        $connector = new GatewayConnectorFactory()->make(new GatewayProfile('gateway', 'https://10.70.0.1'));
        $sender = $connector->sender();

        expect($sender)->toBeInstanceOf(GuzzleSender::class)
            ->and((string) $sender->getHandlerStack())->toContain(GatewayReadRetry::NAME);
    });

    it('retries a CLI read once after an empty reply', function (): void {
        $connector = new GatewayConnectorFactory()->make(new GatewayProfile('gateway', 'https://10.70.0.1'));
        $attempts = 0;
        $connector->sender()->getHandlerStack()->setHandler(
            static function (RequestInterface $request) use (&$attempts): PromiseInterface {
                $attempts++;

                if ($attempts === 1) {
                    return Create::rejectionFor(new RequestException('cURL error 52: Empty reply from server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', $request));
                }

                return Create::promiseFor(new PsrResponse(200, [
                    'Content-Type' => 'application/json',
                    'X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ], '{"data":[],"meta":{"request_id":"0198e15c-bf97-7c23-8f1f-61b8fe67a844"}}'));
            },
        );

        $response = $connector->send(new ListNodesRequest);

        expect($response->status())->toBe(200)
            ->and($attempts)->toBe(2);
    });
});
