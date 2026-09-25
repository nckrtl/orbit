<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Retries a Gateway read once when the connection failed before any HTTP response.
 *
 * A Caddy reload on the Gateway can refuse, reset, or close an HTTP/1.1 connection without a
 * reply (ADR 0144). A GET or HEAD request is safe to send again. Writes, streaming reads,
 * timeouts, certificate failures, and failures after any HTTP response are never retried.
 *
 * The retry waits DELAY_MILLISECONDS and receives only the time left of the request timeout,
 * so both attempts together never exceed the configured timeout.
 */
final class GatewayReadRetry
{
    public const string NAME = 'orbit_read_retry';

    public const int DELAY_MILLISECONDS = 250;

    /** @var list<string> */
    private const array READ_METHODS = ['GET', 'HEAD'];

    /**
     * cURL errors that mean the connection failed before any response:
     * 7 refused, 35 TLS handshake reset, 52 empty reply, 55 send reset, 56 receive reset.
     *
     * @var list<int>
     */
    private const array RETRYABLE_CURL_ERRORS = [7, 35, 52, 55, 56];

    /** @return callable(callable): callable */
    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static function (
            #[SensitiveParameter]
            RequestInterface $request,
            #[SensitiveParameter]
            array $options,
        ) use ($handler): PromiseInterface {
            $startedAt = microtime(true);

            /** @var PromiseInterface $first */
            $first = $handler($request, $options);

            if (! self::isRetryableRead($request, $options)) {
                return $first;
            }

            return $first->then(null, static function (#[SensitiveParameter] mixed $reason) use ($handler, $request, $options, $startedAt): PromiseInterface {
                if (! self::isConnectionFailure($reason)) {
                    return Create::rejectionFor($reason);
                }

                $retryOptions = self::retryOptions($options, microtime(true) - $startedAt);

                if ($retryOptions === null) {
                    return Create::rejectionFor($reason);
                }

                /** @var PromiseInterface $retry */
                $retry = $handler($request, $retryOptions);

                return $retry;
            });
        };
    }

    public static function isConnectionFailure(#[SensitiveParameter] mixed $reason): bool
    {
        if (! $reason instanceof TransferException) {
            return false;
        }

        if (method_exists($reason, 'getResponse') && $reason->getResponse() instanceof ResponseInterface) {
            return false;
        }

        if (preg_match('/\AcURL error (\d+):/', $reason->getMessage(), $matches) !== 1) {
            return false;
        }

        return in_array((int) $matches[1], self::RETRYABLE_CURL_ERRORS, true);
    }

    /** @param array<array-key, mixed> $options */
    private static function isRetryableRead(RequestInterface $request, array $options): bool
    {
        return in_array(strtoupper($request->getMethod()), self::READ_METHODS, true)
            && ($options['stream'] ?? false) !== true;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<array-key, mixed>|null
     */
    private static function retryOptions(array $options, float $elapsedSeconds): ?array
    {
        $options['delay'] = self::DELAY_MILLISECONDS;
        $timeout = $options['timeout'] ?? 0;

        if (! is_int($timeout) && ! is_float($timeout)) {
            return $options;
        }

        if ($timeout <= 0) {
            return $options;
        }

        $remaining = $timeout - $elapsedSeconds - self::DELAY_MILLISECONDS / 1000;

        if ($remaining <= 0) {
            return null;
        }

        $options['timeout'] = $remaining;

        return $options;
    }
}
