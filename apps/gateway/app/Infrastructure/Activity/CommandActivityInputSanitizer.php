<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use Illuminate\Support\Str;

final readonly class CommandActivityInputSanitizer
{
    private const string REDACTED = '[REDACTED]';

    private const string INVALID_ENVIRONMENT_NAME = '[INVALID_ENVIRONMENT_NAME]';

    private const string INVALID_PROPERTY_NAME = '[INVALID_PROPERTY_NAME]';

    /**
     * A field is secret when one of its name segments is one of these words.
     *
     * @var list<string>
     */
    private const array SECRET_WORDS = [
        'key',
        'keys',
        'token',
        'tokens',
        'secret',
        'secrets',
        'password',
        'passwords',
        'passwd',
        'passphrase',
        'credential',
        'credentials',
        'bearer',
        'pem',
        'apikey',
        'appkey',
        'authtoken',
        'accesstoken',
        'privatekey',
    ];

    /**
     * Audited fields whose names contain a secret word but whose values are public.
     *
     * @var list<string>
     */
    private const array NON_SECRET_KEYS = [
        'public_key',
        'wireguard_public_key',
        'host_key_fingerprint',
    ];

    private const string SECRET_KEY_CORE =
        '(?:KEYS?|TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)';

    private const string SECRET_KEY_IDENTIFIER = '(?:[A-Za-z][A-Za-z0-9]*[_-])*'.self::SECRET_KEY_CORE;

    private const string PEM_BLOCK_PATTERN = '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----/';

    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_string($key) && $this->canCarrySecret($value) && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (! is_array($value)) {
            return is_string($value) ? $this->redactText($value) : $value;
        }

        if (is_string($key) && Str::snake($key) === 'environment') {
            return $this->sanitizeEnvironment($value);
        }

        return $this->sanitizeProperties($value);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public function sanitizeProperties(array $properties): array
    {
        $sanitized = [];

        foreach ($properties as $nestedKey => $nestedValue) {
            if (is_string($nestedKey) && ! $this->isValidPropertyName($nestedKey)) {
                $sanitized[self::INVALID_PROPERTY_NAME] = self::REDACTED;

                continue;
            }

            $sanitized[$nestedKey] = $this->sanitize(
                $nestedValue,
                is_string($nestedKey) ? $nestedKey : null,
            );
        }

        return $sanitized;
    }

    private function isValidPropertyName(string $name): bool
    {
        return preg_match('//u', $name) === 1 && preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $name) === 0;
    }

    public function redactText(string $value): string
    {
        $redacted =
            preg_replace(
                pattern: self::PEM_BLOCK_PATTERN,
                replacement: self::REDACTED,
                subject: $value,
            ) ?? $value;
        $redacted =
            preg_replace(
                pattern: '/\b([a-z][a-z0-9+.-]*:\/\/)[^\/@\s]+@/i',
                replacement: '$1'.self::REDACTED.'@',
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/\b((?:Proxy-)?Authorization)\s*:\s*(?!\[REDACTED\])[^\s\'\"]+(?:\s+[^\s\'\"]+)?/i',
                replacement: '$1: '.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/\bBearer\s+(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9][A-Za-z0-9._\-+\/=]{7,})/i',
                replacement: 'Bearer '.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $keys = self::SECRET_KEY_IDENTIFIER;
        $redacted =
            preg_replace(
                pattern: '/\b('.$keys.')\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s&#]+)/i',
                replacement: '$1='.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/("(?:'.$keys.')"\s*:\s*)(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^,}\s]+)/i',
                replacement: '$1"'.self::REDACTED.'"',
                subject: $redacted,
            ) ?? $redacted;

        return
            preg_replace(
                pattern: '/\b('.$keys.')\s*:\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
                replacement: '$1: '.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
    }

    /**
     * Numbers, booleans, and null carry counts and flags such as token usage, never a credential.
     */
    private function canCarrySecret(mixed $value): bool
    {
        return ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null;
    }

    private function isSensitiveKey(string $key): bool
    {
        $underscored = str_replace(search: '-', replace: '_', subject: $key);
        $normalized = preg_match('/\A[A-Z0-9_]+\z/D', $underscored) === 1
            ? strtolower($underscored)
            : Str::snake($underscored);

        if (in_array($normalized, self::NON_SECRET_KEYS, strict: true)) {
            return false;
        }

        return array_intersect(explode('_', $normalized), self::SECRET_WORDS) !== [];
    }

    /**
     * @param  array<array-key, mixed>  $environment
     * @return array<string, string>
     */
    private function sanitizeEnvironment(array $environment): array
    {
        $sanitized = [];

        foreach (array_keys($environment) as $name) {
            $safeName = is_string($name) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1
                ? $name
                : self::INVALID_ENVIRONMENT_NAME;
            $sanitized[$safeName] = self::REDACTED;
        }

        return $sanitized;
    }
}
