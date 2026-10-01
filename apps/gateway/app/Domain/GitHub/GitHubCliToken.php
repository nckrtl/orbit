<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Shared\ResourceOperationException;

/**
 * The `github.com` token of the GitHub CLI login of the Gateway's `orbit` user
 * ([GitHub App](/reference/github-app#read-through-the-github-cli)).
 * Orbit reads it for each repository read and never stores it.
 */
interface GitHubCliToken
{
    /**
     * @throws ResourceOperationException with `github.cli_unauthenticated` when `gh` is missing or has no login.
     */
    public function token(): string;
}
