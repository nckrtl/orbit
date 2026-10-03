<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Tasks\TaskReviewCandidate;
use DateTimeImmutable;
use Tests\Feature\GitHub\GitHubTestSupport;

/** Mutations of the sanitized GitHub read fixtures for boundary tests. */
final class ReviewFindings
{
    /** @param list<GitHubReviewComment> $comments */
    public static function candidate(?string $body = null, array $comments = [], int $reviewId = 101): TaskReviewCandidate
    {
        $source = GitHubTestSupport::review();
        $review = new GitHubReview($reviewId, $source['user']['id'], $source['user']['login'],
            GitHubReviewState::ChangesRequested, $source['commit_id'], new DateTimeImmutable($source['submitted_at']),
            $source['html_url'], $body ?? $source['body']);

        return new TaskReviewCandidate(GitHubRepository::fromOrigin('https://github.com/acme/widgets.git'),
            7, $review->commitId, 'trust-revision', $review, $comments);
    }

    /** @param array<string, int|string|null> $overrides */
    public static function comment(array $overrides = []): GitHubReviewComment
    {
        $source = GitHubTestSupport::comment();
        $comment = new GitHubReviewComment(
            id: $source['id'], reviewId: $source['pull_request_review_id'], authorId: $source['user']['id'],
            authorLogin: $source['user']['login'], url: $source['html_url'], body: $source['body'],
            path: $source['path'], diffHunk: $source['diff_hunk'], line: null, startLine: null,
            originalLine: null, originalStartLine: null, side: null, startSide: null,
            position: $source['position'], originalPosition: $source['original_position'],
            commitId: $source['commit_id'], originalCommitId: $source['original_commit_id'],
            inReplyToId: null, subjectType: null, createdAt: new DateTimeImmutable($source['created_at']),
            updatedAt: new DateTimeImmutable($source['updated_at']),
        );

        return new GitHubReviewComment(...array_replace(get_object_vars($comment), $overrides));
    }

    /** @return array<string, mixed> */
    public static function source(string $brief): array
    {
        $lines = array_filter(explode("\n", $brief), static fn (string $line): bool => str_starts_with($line, '> '));

        return json_decode(implode("\n", array_map(static fn (string $line): string => substr($line, 2), $lines)), true, flags: JSON_THROW_ON_ERROR);
    }
}
