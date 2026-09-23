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

    /** GitHub answered the token request with a client error, such as permissions the installation has not accepted. */
    public static function tokenRefused(int $status, string $message): self
    {
        return new self('GitHub refused the App token request ('.$status.'): '.($message !== '' ? $message : 'no message').'.');
    }

    public static function refused(): self
    {
        return new self('GitHub refused the request.');
    }
}
