<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskReviewRequestLogins;

it('parses comma-separated review request logins and drops empties', function (): void {
    expect(TaskReviewRequestLogins::parseEnv(null))->toBe([])
        ->and(TaskReviewRequestLogins::parseEnv(''))->toBe([])
        ->and(TaskReviewRequestLogins::parseEnv('  '))->toBe([])
        ->and(TaskReviewRequestLogins::parseEnv('reviewbot'))->toBe(['reviewbot'])
        ->and(TaskReviewRequestLogins::parseEnv(' reviewbot , Fleet-Reviewer ,reviewbot, '))->toBe(['reviewbot', 'Fleet-Reviewer']);
});

it('omits the pull request author from requested reviewers', function (): void {
    expect(TaskReviewRequestLogins::withoutAuthor(['reviewbot', 'orbit-bot'], 'Orbit-Bot'))->toBe(['reviewbot'])
        ->and(TaskReviewRequestLogins::withoutAuthor(['orbit-bot'], 'orbit-bot'))->toBe([])
        ->and(TaskReviewRequestLogins::withoutAuthor(['reviewbot'], null))->toBe(['reviewbot']);
});
