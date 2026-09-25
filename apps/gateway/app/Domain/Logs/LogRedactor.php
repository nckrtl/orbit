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
    /** A shorter Instance value would blank ordinary words, such as `true` or `local`, out of the log. */
    public const int ShortestInstanceValue = 8;

    public function __construct(private CommandActivityInputSanitizer $sanitizer) {}

    /**
     * The values to replace in the log of an Instance or a Process, longest first.
     *
     * @return list<string>
     */
    public function valuesFor(#[SensitiveParameter] AppInstance|Process $record): array
    {
        $values = $record instanceof AppInstance
            ? $record->environmentValues
                ->map(static fn (AppInstanceEnvironmentValue $value): string => $value->env_value)
                ->filter(static fn (string $value): bool => strlen($value) >= self::ShortestInstanceValue)
                ->all()
            : array_filter(
                is_array($record->runtime_config['environment'] ?? null) ? $record->runtime_config['environment'] : [],
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            );

        /** @var list<string> $values */
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
