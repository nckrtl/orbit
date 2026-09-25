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
        'authorization',
        'cookie',
        'cookies',
    ];

    /**
     * Audited fields whose names contain a secret word but whose values are public.
     *
     * @var list<string>
     */
    private const array NON_SECRET_KEYS = [
        'public_key',
        'wireguard_public_key',
        'ssh_public_key',
        'public_pem',
        'host_key_fingerprint',
        'runtime_key',
        'env_key',
        'idempotency_key',
        'token_expires_at',
    ];

    /** Secret-named fields whose numeric values are usage counts, such as `tokens` or `token_count`. */
    private const array NUMERIC_METRIC_SUFFIXES = ['tokens', 'count'];

    private const string SECRET_KEY_PREFIX = '(?:[A-Za-z][A-Za-z0-9]*[_-])';

    private const string SECRET_KEY_SUFFIX = '(?:[_-](?:HASH|BASE))?';

    /** A bare `key` in text is prose such as `Missing key: name`, so a key word needs a prefix such as `API_KEY`. */
    private const string SECRET_KEY_IDENTIFIER =
        '(?:'.self::SECRET_KEY_PREFIX.'*'
        .'(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)'
        .'|'.self::SECRET_KEY_PREFIX.'+KEYS?)'.self::SECRET_KEY_SUFFIX;

    private const string PEM_BLOCK_PATTERN = '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----/';

    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if (is_string($key) && $this->isSensitiveKey($key) && ! $this->isNumericMetric($key, $value)) {
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
     * Booleans and null carry no secret. Numbers stay only under usage-count names.
     */
    private function isNumericMetric(string $key, mixed $value): bool
    {
        if (is_bool($value) || $value === null) {
            return true;
        }

        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        $segments = explode('_', $this->normalizeKey($key));

        return in_array(end($segments), self::NUMERIC_METRIC_SUFFIXES, strict: true);
    }

    private function normalizeKey(string $key): string
    {
        $separated = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $key) ?? $key;

        return trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $separated) ?? $separated), '_');
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

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
