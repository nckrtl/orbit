<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Orbit\Sdk\GatewayConnector;
use Psr\Http\Message\RequestInterface;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

describe('Gateway read retry', function (): void {
    it('retries a read once after a connection failure without a response', function (string $class, int $errno, string $error): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure($class, $errno, $error),
            gateway_read_retry_ok(),
        ]);

        $response = $connector->send(gateway_read_retry_request(Method::GET));

        expect($response->status())->toBe(200)
            ->and($attempts->count())->toBe(2)
            ->and($attempts[1]['delay'])->toBe(250)
            ->and($attempts[1]['request_id'])->toBe($attempts[0]['request_id']);
    })->with([
        'connection reset' => [NetworkException::class, 56, 'Recv failure: Connection reset by peer'],
        'empty reply' => [NetworkException::class, 52, 'Empty reply from server'],
        'connection refused' => [ConnectException::class, 7, 'Failed to connect to 10.70.0.1 port 443: Connection refused'],
        'reset during the TLS handshake' => [ConnectException::class, 35, 'Recv failure: Connection reset by peer'],
    ]);

    it('retries a HEAD request the same way as a GET request', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure(NetworkException::class, 52, 'Empty reply from server'),
            gateway_read_retry_ok(),
        ]);

        expect($connector->send(gateway_read_retry_request(Method::HEAD))->status())->toBe(200)
            ->and($attempts->count())->toBe(2);
    });

    it('never retries a change', function (Method $method): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure(NetworkException::class, 56, 'Recv failure: Connection reset by peer'),
            gateway_read_retry_ok(),
        ]);

        expect(fn (): mixed => $connector->send(gateway_read_retry_request($method)))->toThrow(Exception::class)
            ->and($attempts->count())->toBe(1);
    })->with([Method::POST, Method::PUT, Method::PATCH, Method::DELETE]);

    it('never retries after an HTTP response', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            static fn (): PromiseInterface => Create::promiseFor(new PsrResponse(500, ['Content-Type' => 'application/json'], '{}')),
            gateway_read_retry_ok(),
        ]);

        expect(fn (): mixed => $connector->send(gateway_read_retry_request(Method::GET)))->toThrow(Exception::class)
            ->and($attempts->count())->toBe(1);
    });

    it('never retries a transfer that failed after the response started', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(new ResponseException(
                'cURL error 56: Recv failure: Connection reset by peer',
                $request,
                new PsrResponse(200),
            )),
            gateway_read_retry_ok(),
        ]);

        try {
            $connector->send(gateway_read_retry_request(Method::GET));
        } catch (Exception) {
            // Saloon may surface the partial response or its failure; either way no retry follows.
        }

        expect($attempts->count())->toBe(1);
    });

    it('never retries a timeout or a certificate failure', function (string $class, int $errno, string $error): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure($class, $errno, $error),
            gateway_read_retry_ok(),
        ]);

        expect(fn (): mixed => $connector->send(gateway_read_retry_request(Method::GET)))->toThrow(Exception::class)
            ->and($attempts->count())->toBe(1);
    })->with([
        'timeout' => [NetworkTimeoutException::class, 28, 'Operation timed out after 900001 milliseconds with 0 bytes received'],
        'certificate' => [ConnectException::class, 60, 'SSL certificate problem: unable to get local issuer certificate'],
    ]);

    it('never retries a streaming read', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure(NetworkException::class, 56, 'Recv failure: Connection reset by peer'),
            gateway_read_retry_ok(),
        ]);

        $request = gateway_read_retry_request(Method::GET);
        $request->config()->add('stream', true);

        expect(fn (): mixed => $connector->send($request))->toThrow(Exception::class)
            ->and($attempts->count())->toBe(1);
    });

    it('gives the retry only the time left of the request timeout', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure(NetworkException::class, 56, 'Recv failure: Connection reset by peer'),
            gateway_read_retry_ok(),
        ]);

        $connector->send(gateway_read_retry_request(Method::GET));

        expect($attempts[0]['timeout'])->toBe(900)
            ->and($attempts[1]['timeout'])->toBeFloat()->toBeLessThanOrEqual(899.75)->toBeGreaterThan(899.0);
    });

    it('does not retry when the request timeout has no time left', function (): void {
        $connector = new GatewayConnector('https://10.70.0.1', timeout: 1);
        $attempts = gateway_read_retry_attempts($connector, [
            static function (RequestInterface $request): PromiseInterface {
                usleep(800_000);

                return gateway_read_retry_failure(NetworkException::class, 52, 'Empty reply from server')($request);
            },
            gateway_read_retry_ok(),
        ]);

        expect(fn (): mixed => $connector->send(gateway_read_retry_request(Method::GET)))->toThrow(Exception::class)
            ->and($attempts->count())->toBe(1);
    });

    it('retries a read at most once', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure(NetworkException::class, 52, 'Empty reply from server'),
            gateway_read_retry_failure(NetworkException::class, 52, 'Empty reply from server'),
            gateway_read_retry_ok(),
        ]);

        expect(fn (): mixed => $connector->send(gateway_read_retry_request(Method::GET)))->toThrow(Exception::class)
            ->and($attempts->count())->toBe(2);
    });

    it('retries an asynchronous read once', function (): void {
        $attempts = gateway_read_retry_attempts($connector = gateway_read_retry_connector(), [
            gateway_read_retry_failure(NetworkException::class, 56, 'Recv failure: Connection reset by peer'),
            gateway_read_retry_ok(),
        ]);

        $response = $connector->sendAsync(gateway_read_retry_request(Method::GET))->wait();

        expect($response->status())->toBe(200)
            ->and($attempts->count())->toBe(2);
    });
});

function gateway_read_retry_connector(): GatewayConnector
{
    return new GatewayConnector(
        'https://10.70.0.1',
        requestIdResolver: static fn (): string => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );
}

/**
 * @param  list<Closure(RequestInterface): PromiseInterface>  $results
 * @return ArrayObject<int, array{method: string, delay: mixed, timeout: mixed, request_id: string}>
 */
function gateway_read_retry_attempts(GatewayConnector $connector, array $results): ArrayObject
{
    /** @var ArrayObject<int, array{method: string, delay: mixed, timeout: mixed, request_id: string}> $attempts */
    $attempts = new ArrayObject;

    $connector->sender()->getHandlerStack()->setHandler(
        static function (RequestInterface $request, array $options) use ($attempts, &$results): PromiseInterface {
            $attempts->append([
                'method' => $request->getMethod(),
                'delay' => $options['delay'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'request_id' => $request->getHeaderLine('X-Orbit-Request-Id'),
            ]);

            $result = array_shift($results);

            if ($result === null) {
                throw new LogicException('No queued transport result.');
            }

            return $result($request);
        },
    );

    return $attempts;
}

/** @param class-string<NetworkException> $class */
function gateway_read_retry_failure(string $class, int $errno, string $error): Closure
{
    return static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(new $class(
        sprintf('cURL error %d: %s (see https://curl.se/libcurl/c/libcurl-errors.html) for https://10.70.0.1/api/v1/probe', $errno, $error),
        $request,
    ));
}

function gateway_read_retry_ok(): Closure
{
    return static fn (): PromiseInterface => Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/json'], '{"data":[]}'));
}

function gateway_read_retry_request(Method $method): Request
{
    return new class($method) extends Request implements HasBody
    {
        use HasJsonBody;

        public function __construct(Method $method)
        {
            $this->method = $method;
        }

        public function resolveEndpoint(): string
        {
            return '/api/v1/probe';
        }
    };
}
