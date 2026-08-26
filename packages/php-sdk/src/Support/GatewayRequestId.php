<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use Closure;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

/** @internal */
final class GatewayRequestId
{
    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';

    public static function fromTransport(#[SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, offset: 0, length: 8),
            substr($hex, offset: 8, length: 4),
            substr($hex, offset: 12, length: 4),
            substr($hex, offset: 16, length: 4),
            substr($hex, offset: 20),
        );
    }

    /** @param Closure(): string $resolver */
    public static function resolve(#[SensitiveParameter] Closure $resolver): string
    {
        try {
            $resolvedRequestId = $resolver();
        } catch (Throwable) {
            throw new UnexpectedValueException('Gateway request ID resolver failed.');
        }

        $requestId = self::fromTransport($resolvedRequestId);

        if ($requestId === null) {
            throw new UnexpectedValueException('Gateway request ID resolver returned an invalid UUID.');
        }

        return $requestId;
    }
}
