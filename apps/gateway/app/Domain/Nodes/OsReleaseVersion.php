<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/**
 * Human-readable operating system text parsed from /etc/os-release contents.
 */
final class OsReleaseVersion
{
    private const int MAX_CONTENTS_BYTES = 8192;

    private const int MAX_VERSION_LENGTH = 200;

    public static function fromContents(string $contents): ?string
    {
        if (strlen($contents) > self::MAX_CONTENTS_BYTES) {
            $contents = substr($contents, 0, self::MAX_CONTENTS_BYTES);
        }

        $fields = self::parse($contents);
        $prettyName = $fields['PRETTY_NAME'] ?? null;

        if (self::isDisplayable($prettyName)) {
            return $prettyName;
        }

        $name = $fields['NAME'] ?? null;
        $version = $fields['VERSION'] ?? $fields['VERSION_ID'] ?? null;

        if (! self::isDisplayable($name) || ! self::isDisplayable($version)) {
            return null;
        }

        $combined = $name.' '.$version;

        return self::isDisplayable($combined) ? $combined : null;
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $contents): array
    {
        $fields = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false || $separator === 0) {
                continue;
            }

            $key = substr($line, 0, $separator);

            if (array_key_exists($key, $fields) || preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $key) !== 1) {
                continue;
            }

            $value = self::unquote(substr($line, $separator + 1));

            if ($value === null) {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    private static function unquote(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $quote = $value[0];

        if ($quote === '"' || $quote === "'") {
            if (strlen($value) < 2 || ! str_ends_with($value, $quote)) {
                return null;
            }

            return substr($value, 1, -1);
        }

        return $value;
    }

    private static function isDisplayable(?string $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_VERSION_LENGTH
            && ! str_contains($value, '$')
            && ! str_contains($value, '`')
            && preg_match('/\A[\x20-\x7E]+\z/D', $value) === 1;
    }
}
