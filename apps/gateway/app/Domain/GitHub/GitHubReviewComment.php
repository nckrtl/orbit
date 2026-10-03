<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use DateTimeImmutable;

/** Source locations stay nullable, including outdated and file-level findings. */
final readonly class GitHubReviewComment
{
    public function __construct(
        public int $id,
        public int $reviewId,
        public int $authorId,
        public string $authorLogin,
        public string $url,
        public string $body,
        public ?string $path,
        public ?string $diffHunk,
        public ?int $line,
        public ?int $startLine,
        public ?int $originalLine,
        public ?int $originalStartLine,
        public ?string $side,
        public ?string $startSide,
        public ?int $position,
        public ?int $originalPosition,
        public ?string $commitId,
        public ?string $originalCommitId,
        public ?int $inReplyToId,
        public ?string $subjectType,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
