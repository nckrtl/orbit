<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;
use SensitiveParameter;

final class T3DispatchException extends RuntimeException
{
    private const string PROJECT_ID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        string $message = 'T3 dispatch failed.',
        public readonly ?string $existingProjectId = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $httpBody = null,
    ) {
        parent::__construct($message);
    }

    public static function existingProjectId(#[SensitiveParameter] string $body): ?string
    {
        $haystack = self::searchableText($body);

        if (preg_match(
            '/Active project [\'"]?('.self::PROJECT_ID.')[\'"]? already exists for(?: that)? workspace root/',
            $haystack,
            $matches,
        ) !== 1) {
            return null;
        }

        return self::projectId($matches[1]);
    }

    public static function projectId(#[SensitiveParameter] string $value): ?string
    {
        if (preg_match('/^'.self::PROJECT_ID.'$/', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }

    private static function searchableText(#[SensitiveParameter] string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        $parts = [$body];

        array_walk_recursive($decoded, function (mixed $item) use (&$parts): void {
            if (is_string($item) && $item !== '') {
                $parts[] = $item;
            }
        });

        return implode("\n", $parts);
    }
}
