<?php

declare(strict_types=1);

namespace App\Domain\Releases;

use InvalidArgumentException;

/**
 * What a release alert is about: the release target, the repository, the exact commit, and the release record.
 * The fields are code-supplied, so a value outside the bounds is a caller bug and fails before anything is recorded.
 */
final readonly class ReleaseAlertSubject
{
    private const string TargetPattern = '/\A[a-z0-9][a-z0-9._:-]{0,63}\z/';

    private const string RepositoryPattern = '/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9._-]{1,100}\z/';

    private const string ShaPattern = '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';

    private const string ReleaseIdPattern = '/\A[A-Za-z0-9._:-]{1,64}\z/';

    public function __construct(
        public string $target,
        public string $repository,
        public string $sha,
        public ?string $releaseId = null,
    ) {
        if (preg_match(self::TargetPattern, $target) !== 1) {
            throw new InvalidArgumentException('A release alert target is 1 to 64 lowercase letters, digits, dots, colons, underscores, or dashes.');
        }

        if (preg_match(self::RepositoryPattern, $repository) !== 1 || str_ends_with($repository, '/.') || str_ends_with($repository, '/..')) {
            throw new InvalidArgumentException('A release alert repository is a GitHub owner/name.');
        }

        if (preg_match(self::ShaPattern, $sha) !== 1) {
            throw new InvalidArgumentException('A release alert sha is a full lowercase commit SHA.');
        }

        if ($releaseId !== null && preg_match(self::ReleaseIdPattern, $releaseId) !== 1) {
            throw new InvalidArgumentException('A release alert release id is 1 to 64 letters, digits, dots, colons, underscores, or dashes.');
        }
    }

    public function shortSha(): string
    {
        return substr($this->sha, 0, 12);
    }

    /** @return array{target: string, repository: string, sha: string, release_id: string|null} */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'repository' => $this->repository,
            'sha' => $this->sha,
            'release_id' => $this->releaseId,
        ];
    }
}
