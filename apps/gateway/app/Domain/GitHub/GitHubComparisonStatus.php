<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** How the head of a comparison relates to its base, as the compare API reports it. */
enum GitHubComparisonStatus: string
{
    case Ahead = 'ahead';
    case Behind = 'behind';
    case Identical = 'identical';
    case Diverged = 'diverged';
}
