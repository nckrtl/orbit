<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use RuntimeException;

final class GitHubApiException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('GitHub could not be reached or refused the Project credential.');
    }

    /** GitHub answered the token request with a client error, such as permissions the installation has not accepted. */
    public static function tokenRefused(int $status, string $message): self
    {
        return new self('GitHub refused the Project token request ('.$status.'): '.($message !== '' ? $message : 'no message').'.', $status);
    }

    public function isTokenRefusal(): bool
    {
        return $this->getCode() >= 400 && $this->getCode() < 500;
    }

    public static function refused(): self
    {
        return new self('GitHub refused the request.');
    }

    public static function reviewersRefused(int $status, string $message): self
    {
        return new self(
            'GitHub refused the reviewer request ('.$status.'): '.($message !== '' ? $message : 'no message').'.',
            $status,
        );
    }
}
