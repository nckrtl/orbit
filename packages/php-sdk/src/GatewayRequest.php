<?php

declare(strict_types=1);

namespace Orbit\Sdk;

use JsonException;
use LogicException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Http\Request;
use Saloon\Http\Response;
use SensitiveParameter;
use Throwable;

abstract class GatewayRequest extends Request
{
    /** @return array{type: class-string<static>} */
    final public function __debugInfo(): array
    {
        return ['type' => static::class];
    }

    /** @return array<never, never> */
    final public function __serialize(): array
    {
        throw new LogicException('Orbit gateway requests cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    final public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit gateway requests cannot be unserialized.');
    }

    final public function debug(bool $die = false): static
    {
        throw new LogicException('Orbit SDK raw transport debugging is disabled.');
    }

    final public function debugRequest(
        #[SensitiveParameter]
        ?callable $onRequest = null,
        bool $die = false,
    ): static {
        throw new LogicException('Orbit SDK raw transport debugging is disabled.');
    }

    final public function debugResponse(
        #[SensitiveParameter]
        ?callable $onResponse = null,
        bool $die = false,
    ): static {
        throw new LogicException('Orbit SDK raw transport debugging is disabled.');
    }

    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        if ($response->clientError() || $response->serverError()) {
            return true;
        }

        $body = $this->decodeBody($response);

        return is_array($body) && array_key_exists('error', $body);
    }

    public function getRequestException(
        #[SensitiveParameter]
        Response $response,
        #[SensitiveParameter]
        ?Throwable $senderException,
    ): ?Throwable {
        $body = $this->decodeBody($response);
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "Gateway request failed with HTTP status {$response->status()}.";
        $errorCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        $redactor = new CredentialRedactor;

        return new GatewayApiException(
            message: $redactor->redactText($message),
            errorCode: $errorCode,
            details: $redactor->redactArray($this->stringKeyedArray($error['details'] ?? [])),
            previous: $redactor->redactThrowable($senderException),
            requestId: $this->requestId($response),
        );
    }

    /** @return array<string, mixed> */
    protected function unwrapData(#[SensitiveParameter] Response $response): array
    {
        $body = $this->decodeBody($response);

        if (! is_array($body)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        return $this->stringKeyedArray($body['data'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function unwrapDataList(
        #[SensitiveParameter]
        Response $response,
        bool $rejectMalformed = false,
    ): array {
        $body = $this->decodeBody($response);

        if (! is_array($body)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        $data = $body['data'] ?? [];

        if ($rejectMalformed) {
            return $this->strictDataList($data);
        }

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

    /**
     * @return list<array<string, mixed>>
     */
    private function strictDataList(#[SensitiveParameter] mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new GatewayApiException('Gateway response contains invalid collection data.');
        }

        $result = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new GatewayApiException('Gateway response contains invalid collection data.');
            }

            $stringKeyed = $this->stringKeyedArray($item);
            if (count($stringKeyed) !== count($item)) {
                throw new GatewayApiException('Gateway response contains invalid collection data.');
            }

            $result[] = $stringKeyed;
        }

        return $result;
    }

    protected function successRequestId(#[SensitiveParameter] Response $response): string
    {
        $body = $this->decodeBody($response);
        $meta = $this->stringKeyedArray($body['meta'] ?? []);

        return GatewayRequestId::fromTransport($meta['request_id'] ?? null) ?? '';
    }

    protected function successHeaderRequestId(#[SensitiveParameter] Response $response): string
    {
        return $this->requestId($response) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function stringKeyedArray(#[SensitiveParameter] mixed $value): array
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
     * @return list<string>
     */
    protected function stringList(#[SensitiveParameter] mixed $value): array
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
     * @return array<string, mixed>|null
     */
    private function decodeBody(#[SensitiveParameter] Response $response): ?array
    {
        $body = $response->body();

        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode(
                json: $body,
                associative: true,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );

            return is_array($decoded) ? $this->stringKeyedArray($decoded) : null;
        } catch (JsonException) {
            return null;
        }
    }

    private function requestId(#[SensitiveParameter] Response $response): ?string
    {
        $requestId = $response->getPsrResponse()->getHeaderLine('X-Orbit-Request-Id');

        return GatewayRequestId::fromTransport($requestId);
    }
}
