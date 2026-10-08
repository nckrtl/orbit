<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\GatewayProfile;
use App\Services\SelfUpdate\CliReleaseNotice;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Utils;
use Illuminate\Support\Str;
use Orbit\Sdk\GatewayConnector;
use Saloon\Http\Response;
use Saloon\Http\Senders\GuzzleSender;

final readonly class GatewayConnectorFactory
{
    public function __construct(private ?CliReleaseNotice $releaseNotice = null) {}

    public function make(GatewayProfile $profile, int $timeout = 900): GatewayConnector
    {
        $version = app()->bound('config') ? config('app.version') : null;
        $connector = new GatewayConnector(
            baseUrl: $profile->url,
            caPemPath: $profile->caPath,
            timeout: $timeout,
            requestIdResolver: static fn (): string => (string) Str::uuid(),
            clientVersion: is_string($version) ? $version : null,
        );

        $notice = $this->releaseNotice;

        if ($notice instanceof CliReleaseNotice) {
            // The Gateway names the CLI release its fleet runs; the notice prints after the command.
            $connector->middleware()->onResponse(static function (Response $response) use ($notice): Response {
                $desired = $response->header('X-Orbit-Cli-Version');
                $notice->observe(is_string($desired) ? $desired : null);

                return $response;
            });
        }

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
