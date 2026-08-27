<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * @internal
 * @mago-expect lint:cyclomatic-complexity Credential redaction chains several independent safety checks.
 */
final readonly class CredentialRedactor
{
    private const string REDACTED = '[REDACTED]';

    private const string SECRET_KEY_CORE =
        '(?:APP[_-]?KEY|APPLICATION[_-]?KEY|APPKEY|API[_-]?KEY|API[_-]?TOKEN|ACCESS[_-]?TOKEN|'
            .'REFRESH[_-]?TOKEN|OPERATION[_-]?TOKEN|EXECUTOR[_-]?SECRET|PRIVATE[_-]?KEY|'
            .'PRE[_-]?SHARED[_-]?KEY|PASSWORD[_-]?HASH|PASSWORD[_-]?CONFIRMATION|PASSWORD|'
            .'SECRET|TOKEN|BEARER[_-]?TOKEN|BEARER)';

    private const string SECRET_KEY_IDENTIFIER = '(?:[A-Za-z][A-Za-z0-9]*[_-])*'.self::SECRET_KEY_CORE;

    private const string PEM_BLOCK_PATTERN = '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|\z)/';

    /**
     * @mago-expect analysis:mixed-assignment Gateway error details contain recursive JSON values.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function redactArray(#[SensitiveParameter] array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redactedKey = $this->redactText($key);

            if (array_key_exists($redactedKey, $redacted)) {
                continue;
            }

            $redacted[$redactedKey] = $this->redactValue($value, $key);
        }

        return $redacted;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway payloads contain recursive JSON values.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public function redactTransportArray(#[SensitiveParameter] array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redactedKey = is_string($key) ? $this->redactText($key) : $key;

            if (array_key_exists($redactedKey, $redacted)) {
                continue;
            }

            $redacted[$redactedKey] = $this->redactValue($value, $key);
        }

        return $redacted;
    }

    public function redactText(#[SensitiveParameter] string $value): string
    {
        $redacted =
            preg_replace(
                pattern: self::PEM_BLOCK_PATTERN,
                replacement: self::REDACTED,
                subject: $value,
            ) ?? $value;
        $redacted =
            preg_replace(
                pattern: '/\b(SSL CA bundle not found:\s*)[\s\S]*/i',
                replacement: '$1'.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/\b([a-z][a-z0-9+.-]*:\/\/)[^\/@\s]+@/i',
                replacement: '$1'.self::REDACTED.'@',
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/([?&]'.self::SECRET_KEY_IDENTIFIER.'=)[^&#\s]*/i',
                replacement: '$1'.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/\b((?:Proxy-)?Authorization)\s*:\s*[^\r\n]*/i',
                replacement: '$1: '.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/\bBearer\s+(?:"[^"]*"|\'[^\']*\'|[^\s,;}]+)/i',
                replacement: 'Bearer '.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/\b('.self::SECRET_KEY_IDENTIFIER.')\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s&,}]+)/i',
                replacement: '$1='.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted;
        $redacted =
            preg_replace(
                pattern: '/("(?:'
                .self::SECRET_KEY_IDENTIFIER
                .')"\s*:\s*)(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^,}\s]+)/i',
                replacement: '$1"'.self::REDACTED.'"',
                subject: $redacted,
            ) ?? $redacted;

        return (
            preg_replace(
                pattern: '/\b('.self::SECRET_KEY_IDENTIFIER.')\s*:\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
                replacement: '$1: '.self::REDACTED,
                subject: $redacted,
            ) ?? $redacted
        );
    }

    public function redactThrowable(#[SensitiveParameter] ?Throwable $throwable): ?Throwable
    {
        if (! $throwable instanceof Throwable) {
            return null;
        }

        return new RuntimeException(
            message: $this->redactText($throwable->getMessage()),
            code: is_int($throwable->getCode()) ? $throwable->getCode() : 0,
        );
    }

    private function redactValue(
        #[SensitiveParameter]
        mixed $value,
        #[SensitiveParameter]
        string|int $key,
    ): mixed {
        if (is_string($key) && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (! is_array($value)) {
            return is_string($value) ? $this->redactText($value) : $value;
        }

        if (is_string($key) && $this->normalizeKey($key) === 'environment') {
            return array_map(static fn (): string => self::REDACTED, $value);
        }

        return $this->redactTransportArray($value);
    }

    private function isSensitiveKey(#[SensitiveParameter] string $key): bool
    {
        return (
            preg_match(
                '/(?:^|_)(app_?key|application_?key|password(?:_hash|_confirmation)?|secret|token|api_?key|api_?token|access_?token|refresh_?token|operation_?token|executor_?secret|private_?key|pre_?shared_?key|bearer(?:_?token)?)$/',
                $this->normalizeKey($key),
            ) === 1
        );
    }

    private function normalizeKey(#[SensitiveParameter] string $key): string
    {
        $underscored = str_replace(search: '-', replace: '_', subject: $key);
        $underscored =
            preg_replace(
                pattern: '/(?<=[a-z0-9])([A-Z])/',
                replacement: '_$1',
                subject: $underscored,
            ) ?? $underscored;

        return strtolower($underscored);
    }
}
