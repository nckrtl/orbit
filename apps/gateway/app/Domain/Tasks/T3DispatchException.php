<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;
use SensitiveParameter;

final class T3DispatchException extends RuntimeException
{
    public function __construct(
        string $message = 'T3 dispatch failed.',
        public readonly ?string $existingProjectId = null,
    ) {
        parent::__construct($message);
    }

    public static function existingProjectId(#[SensitiveParameter] string $body): ?string
    {
        if (preg_match(
            '/Active project ([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}) already exists for that workspace root/',
            $body,
            $matches,
        ) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
