<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Process;
use SensitiveParameter;

/**
 * The Gateway's redaction of log text: each stored environment value of the record, then the
 * secret patterns. One-shot log reads and the live log relay both use it (ADR 0153).
 */
final readonly class LogRedactor
{
    /** A shorter value would blank ordinary words, such as `true` or `local`, out of the log. */
    public const int ShortestValue = 8;

    /**
     * Keys whose values are settings, not secrets. Replacing them would hide ordinary words, such as
     * `production` in `production.INFO`, and no secret is lost when they stay.
     */
    private const string NonSecretKey = '/\A(?:APP_ENV|APP_NAME|APP_URL|APP_LOCALE|APP_FALLBACK_LOCALE|LOG_[A-Z0-9_]*|DB_CONNECTION|DB_HOST|DB_PORT|[A-Z0-9_]*_(?:DRIVER|CONNECTION|STORE))\z/D';

    public function __construct(private CommandActivityInputSanitizer $sanitizer) {}

    /**
     * The values to replace in the log of an Instance or a Process, longest first: each stored
     * environment value of eight characters or more, except the values of known setting keys.
     *
     * @return list<string>
     */
    public function valuesFor(#[SensitiveParameter] AppInstance|Process $record): array
    {
        $environment = [];

        if ($record instanceof AppInstance) {
            foreach ($record->environmentValues as $value) {
                /** @var AppInstanceEnvironmentValue $value */
                $environment[] = [$value->env_key, $value->env_value];
            }
        } else {
            $stored = $record->runtime_config['environment'] ?? null;

            foreach (is_array($stored) ? $stored : [] as $key => $value) {
                $environment[] = [(string) $key, $value];
            }
        }

        $values = [];

        foreach ($environment as [$key, $value]) {
            if (is_string($value) && strlen($value) >= self::ShortestValue && preg_match(self::NonSecretKey, strtoupper($key)) !== 1) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $values;
    }

    /** @param list<string> $values */
    public function redact(#[SensitiveParameter] string $text, #[SensitiveParameter] array $values): string
    {
        return $this->sanitizer->redactText($values === [] ? $text : str_replace(search: $values, replace: '[REDACTED]', subject: $text));
    }
}
