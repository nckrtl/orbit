<?php

declare(strict_types=1);

namespace App\E2E\State;

final readonly class SecretRedactor
{
    private const string REDACTED = '[REDACTED]';
    private const array SENSITIVE_KEYS = [
        'authorization',
        'password',
        'passwd',
        'secret',
        'token',
        'private_key',
        'private-key',
        'credential',
        'credentials',
    ];

    /** @param list<string> $secrets */
    public function __construct(
        private array $secrets = [],
    ) {}

    public function redact(string $value): string
    {
        $value =
            preg_replace(
                '~-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----~s',
                self::REDACTED,
                $value,
            ) ?? $value;
        $value = preg_replace('~(https?://)[^/@\s]+@~i', '$1'.self::REDACTED.'@', $value) ?? $value;
        $value = preg_replace('~\b(Bearer\s+)[^\s,;]+~i', '$1'.self::REDACTED, $value) ?? $value;
        $value = preg_replace('~\b(Authorization\s*[:=]\s*)([^\r\n]+)~i', '$1'.self::REDACTED, $value) ?? $value;

        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, self::REDACTED, $value);
            }
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value @return array<array-key, mixed> */
    public function redactArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $value[$key] = self::REDACTED;
            } elseif (is_array($item)) {
                $value[$key] = $this->redactArray($item);
            } elseif (is_string($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return array_any(
            self::SENSITIVE_KEYS,
            fn ($sensitive) => $normalized === $sensitive || str_ends_with($normalized, '_'.$sensitive),
        );
    }
}
