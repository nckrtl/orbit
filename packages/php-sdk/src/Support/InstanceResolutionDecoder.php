<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use JsonException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\AppInstances\ResolvedAppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\ResolvedDirectoryInstanceResponse;
use Saloon\Http\Response;
use SensitiveParameter;
use stdClass;

/** @internal */
final class InstanceResolutionDecoder
{
    private const int MAX_BYTES = 4096;

    private function __construct(private ?string $requestId) {}

    public static function guardBody(#[SensitiveParameter] string $body, #[SensitiveParameter] mixed $requestId): void
    {
        if (strlen($body) > self::MAX_BYTES) {
            new self(GatewayRequestId::fromTransport($requestId))->invalid();
        }
    }

    public static function decode(
        #[SensitiveParameter] string $body,
        #[SensitiveParameter] string $domain,
        #[SensitiveParameter] mixed $headerRequestId,
    ): ResolvedAppInstanceResponse {
        [$data, $requestId] = self::envelope($body, $headerRequestId, ['domain', 'instance_id', 'app_id', 'node_id', 'environment']);

        return ResolvedAppInstanceResponse::fromGatewayData($data, $domain, $requestId);
    }

    public static function decodeDirectory(
        #[SensitiveParameter] string $body,
        #[SensitiveParameter] mixed $headerRequestId,
    ): ResolvedDirectoryInstanceResponse {
        [$data, $requestId] = self::envelope($body, $headerRequestId, ['instance_id', 'app_id', 'node_id', 'environment']);

        return ResolvedDirectoryInstanceResponse::fromGatewayData($data, $requestId);
    }

    public static function decodeFromResponse(
        #[SensitiveParameter] Response $response,
        #[SensitiveParameter] string $domain,
        string $requestId,
    ): ResolvedAppInstanceResponse {
        $result = self::decode($response->body(), $domain, $response->header('X-Orbit-Request-Id'));
        if ($requestId !== '' && $result->requestId !== $requestId) {
            throw new GatewayApiException('Gateway response contains invalid instance resolution data.', requestId: $result->requestId);
        }

        return $result;
    }

    public static function decodeDirectoryFromResponse(
        #[SensitiveParameter] Response $response,
        string $requestId,
    ): ResolvedDirectoryInstanceResponse {
        $result = self::decodeDirectory($response->body(), $response->header('X-Orbit-Request-Id'));
        if ($requestId !== '' && $result->requestId !== $requestId) {
            throw new GatewayApiException('Gateway response contains invalid instance resolution data.', requestId: $result->requestId);
        }

        return $result;
    }

    /** @param list<string> $fields
     * @return array{array<string, mixed>, string}
     */
    private static function envelope(#[SensitiveParameter] string $body, #[SensitiveParameter] mixed $headerRequestId, array $fields): array
    {
        self::guardBody($body, $headerRequestId);
        $decoder = new self(GatewayRequestId::fromTransport($headerRequestId));

        try {
            $envelope = json_decode($body, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoder->invalid();
        }

        // Check the raw tokens before trusting any overwritten ownership or correlation.
        $decoder->uniqueKeys($body);
        $envelope = $decoder->object($envelope, ['data', 'meta']);
        $meta = $decoder->object($envelope->meta, ['request_id']);
        $requestId = GatewayRequestId::fromTransport($meta->request_id);
        if ($requestId === null || ($decoder->requestId !== null && $decoder->requestId !== $requestId)) {
            $decoder->invalid();
        }
        $decoder->requestId = $requestId;
        $data = $decoder->object($envelope->data, $fields);

        return [get_object_vars($data), $requestId];
    }

    /** @param list<string> $fields */
    private function object(#[SensitiveParameter] mixed $value, array $fields): stdClass
    {
        if (! $value instanceof stdClass || count(get_object_vars($value)) !== count($fields)) {
            $this->invalid();
        }
        foreach ($fields as $field) {
            if (! property_exists($value, $field)) {
                $this->invalid();
            }
        }

        return $value;
    }

    /** Reject duplicate JSON keys before a decoded overwrite can become a target. */
    private function uniqueKeys(#[SensitiveParameter] string $body): void
    {
        $stack = [];
        $offset = 0;
        $length = strlen($body);
        while ($offset < $length) {
            $matched = preg_match('/"(?:[^"\\\\]++|\\\\.)*+"|[{}\[\]]/s', $body, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) {
                $this->invalid();
            }
            if ($matched === 0) {
                break;
            }
            [$token, $position] = $match[0];
            $offset = $position + strlen($token);
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (($body[$offset + strspn($body, " \t\r\n", $offset)] ?? null) === ':') {
                $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                $level = count($stack) - 1;
                if (isset($stack[$level][$key])) {
                    $this->invalid();
                }
                $stack[$level][$key] = true;
            }
        }
    }

    private function invalid(): never
    {
        throw new GatewayApiException('Gateway response contains invalid instance resolution data.', requestId: $this->requestId);
    }
}
