<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatewayFailureRenderer;
use App\Support\OrbitHome;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Responses\Extensions\ExtensionsResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

final class GatewayExtensionState
{
    private const int DISCOVERY_TIMEOUT_SECONDS = 2;

    /** @var list<string> */
    public const array EXTENSIONS = ['tasks', 'proxycli'];

    private static ?ExtensionDiscoveryResult $result = null;

    public function __construct(private readonly GatewayConnectorFactory $connectors) {}

    public function discover(): ExtensionDiscoveryResult
    {
        if (self::$result !== null) {
            return self::$result;
        }

        self::$result = $this->resolve();

        return self::$result;
    }

    public function isEnabled(string $extension): ?bool
    {
        return $this->discover()->isEnabled($extension);
    }

    public static function reset(): void
    {
        self::$result = null;
    }

    private function resolve(): ExtensionDiscoveryResult
    {
        try {
            $profile = new GatewayConfigRepository(OrbitHome::path().'/config.json')->active();
        } catch (GatewayConfigException $exception) {
            $code = $exception->errorCode ?? 'gateway.config_invalid';

            return self::unknown($code, $exception->isPrivacyFailure()
                ? $exception->getMessage()
                : 'Orbit gateway configuration is invalid.');
        } catch (Throwable) {
            return self::unknown('gateway.config_invalid', 'Orbit gateway configuration is invalid.');
        }

        if ($profile === null) {
            return self::unknown(
                'gateway.profile_missing',
                'No active gateway profile. Run "orbit gateway:add" to add one or "orbit gateway:use" to select one.',
            );
        }

        try {
            $response = $this->connectors
                ->make($profile, timeout: self::DISCOVERY_TIMEOUT_SECONDS)
                ->send(new ListExtensionsRequest);
            $dto = $response->dto();

            if (! $dto instanceof ExtensionsResponse) {
                return self::unknown('gateway.invalid_response', 'Gateway returned invalid extension state.');
            }

            return new ExtensionDiscoveryResult($dto->extensions);
        } catch (GatewayApiException $exception) {
            $code = $exception->errorCode() ?? 'gateway.request_failed';

            return new ExtensionDiscoveryResult(
                enabled: null,
                errorCode: $code,
                message: $exception->getMessage(),
                requestId: $exception->requestId(),
                details: GatewayFailureRenderer::safeDetails($code, $exception->details()),
            );
        } catch (FatalRequestException $exception) {
            return self::transportFailure($exception);
        } catch (Throwable) {
            return self::unknown('gateway.invalid_response', 'Gateway returned invalid extension state.');
        }
    }

    private static function transportFailure(FatalRequestException $exception): ExtensionDiscoveryResult
    {
        if (self::isCertificateFailure($exception)) {
            return self::unknown(
                'gateway.ca_untrusted',
                'The Gateway certificate is not trusted. Run "orbit gateway:trust" and try again.',
            );
        }

        if (self::isTimeout($exception)) {
            return self::unknown(
                'gateway.unreachable',
                'Extension state discovery timed out after 2 seconds. Check the active Gateway and try again.',
            );
        }

        return self::unknown(
            'gateway.unreachable',
            'Could not reach the Gateway. Check the active profile and network, then try again.',
        );
    }

    private static function isCertificateFailure(Throwable $exception): bool
    {
        for ($failure = $exception; $failure !== null; $failure = $failure->getPrevious()) {
            if (preg_match('/(?:cURL error 60:|certificate verify failed|SSL certificate problem|self[- ]signed certificate)/i', $failure->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isTimeout(Throwable $exception): bool
    {
        for ($failure = $exception; $failure !== null; $failure = $failure->getPrevious()) {
            if (preg_match('/(?:cURL error 28:|operation timed out|timed out)/i', $failure->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function unknown(string $code, string $message): ExtensionDiscoveryResult
    {
        return new ExtensionDiscoveryResult(enabled: null, errorCode: $code, message: $message);
    }
}
