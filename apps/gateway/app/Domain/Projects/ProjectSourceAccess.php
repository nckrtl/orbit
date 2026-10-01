<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * How Orbit reads a Project's private `github.com` repository
 * ([GitHub App](/reference/github-app#read-through-the-github-cli)).
 */
enum ProjectSourceAccess: string
{
    case GitHubApp = 'github_app';
    case GhCli = 'gh_cli';
}
