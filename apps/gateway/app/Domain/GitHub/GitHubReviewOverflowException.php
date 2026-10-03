<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use RuntimeException;

/** A validated next-page link exceeds the bounded review source, not a transient read failure. */
final class GitHubReviewOverflowException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The complete GitHub review source exceeds the pagination limit.');
    }
}
