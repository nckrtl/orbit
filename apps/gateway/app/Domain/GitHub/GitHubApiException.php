<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use RuntimeException;

final class GitHubApiException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('GitHub could not be reached or refused the App credential.');
    }

    public static function refused(): self
    {
        return new self('GitHub refused the request.');
    }
}
