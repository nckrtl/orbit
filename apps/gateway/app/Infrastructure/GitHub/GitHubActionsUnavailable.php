<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use RuntimeException;

/**
 * GitHub Actions could not be read: no App, no Actions read permission, an unexpected answer, or an
 * unreachable API. It carries no detail, so no token or signed URL can reach an error message.
 */
final class GitHubActionsUnavailable extends RuntimeException {}
