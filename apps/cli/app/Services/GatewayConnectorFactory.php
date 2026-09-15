<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\GatewayProfile;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Utils;
use Illuminate\Support\Str;
use Orbit\Sdk\GatewayConnector;
use Saloon\Http\Senders\GuzzleSender;

final readonly class GatewayConnectorFactory
{
    public function make(GatewayProfile $profile): GatewayConnector
    {
        $connector = new GatewayConnector(
            baseUrl: $profile->url,
            caPemPath: $profile->caPath,
            requestIdResolver: static fn (): string => (string) Str::uuid(),
        );

        $sender = $connector->sender();

        if ($sender instanceof GuzzleSender && function_exists('curl_multi_exec')) {
            // Return to PHP between network waits so pending terminal signals are handled.
            // Keep the existing handler for streaming requests and TLS fallbacks.
            $fallback = Utils::chooseHandler();
            $sender->getHandlerStack()->setHandler(Proxy::wrapTlsFallback(
                Proxy::wrapStreaming(new CurlMultiHandler(['select_timeout' => 0.1]), $fallback),
                $fallback,
            ));
        }

        return $connector;
    }
}
