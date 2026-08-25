<?php

declare(strict_types=1);

namespace Orbit\Sdk;

use JsonException;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
abstract class GatewayRequest extends Request
{
    public function hasRequestFailed(Response $response): ?bool
    {
        if ($response->clientError() || $response->serverError()) {
            return true;
        }

        $body = $this->decodeBody($response);

        return is_array($body) && array_key_exists('error', $body);
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        $body = $this->decodeBody($response);
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "Gateway request failed with HTTP status {$response->status()}.";
        $errorCode = is_string($error['code'] ?? null) ? $error['code'] : null;

        return new GatewayApiException(
            message: $message,
            errorCode: $errorCode,
            details: $this->stringKeyedArray($error['details'] ?? []),
            previous: $senderException,
            requestId: $this->requestId($response),
        );
    }

    /** @return array<string, mixed> */
    protected function unwrapData(Response $response): array
    {
        $body = $this->decodeBody($response);

        if (! is_array($body)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        return $this->stringKeyedArray($body['data'] ?? []);
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway collection items remain mixed until validated.
     *
     * @return list<array<string, mixed>>
     */
    protected function unwrapDataList(Response $response): array
    {
        $body = $this->decodeBody($response);

        if (! is_array($body)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        $data = $body['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result[] = $this->stringKeyedArray($item);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    protected function unwrapMeta(Response $response): array
    {
        $body = $this->decodeBody($response);

        return $this->stringKeyedArray($body['meta'] ?? []);
    }

    /**
     * @mago-expect analysis:mixed-assignment JSON values remain mixed by design.
     *
     * @return array<string, mixed>
     */
    protected function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @mago-expect analysis:mixed-assignment JSON values remain mixed until validated.
     *
     * @return list<string>
     */
    protected function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @mago-expect analysis:mixed-assignment JSON decoding starts at an untyped boundary.
     *
     * @return array<string, mixed>|null
     */
    private function decodeBody(Response $response): ?array
    {
        if ($response->body() === '') {
            return null;
        }

        try {
            $decoded = json_decode(
                json: $response->body(),
                associative: true,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );

            return is_array($decoded) ? $this->stringKeyedArray($decoded) : null;
        } catch (JsonException) {
            return null;
        }
    }

    private function requestId(Response $response): ?string
    {
        $requestId = $response->getPsrResponse()->getHeaderLine('X-Orbit-Request-Id');

        return $requestId !== '' ? $requestId : null;
    }
}
