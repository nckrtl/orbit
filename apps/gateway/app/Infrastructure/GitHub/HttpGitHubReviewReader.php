<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;
use App\Domain\GitHub\GitHubReviewState;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/** Read-only, fail-closed review transport. Links are validated, never used as request URLs. */
final readonly class HttpGitHubReviewReader
{
    private const string BASE_URL = 'https://api.github.com';

    /** @return list<GitHubReview> */
    public function reviews(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): array
    {
        return array_map($this->reviewRecord(...), $this->pages($token, $this->path($repository, $number), 10, $repository));
    }

    public function review(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): GitHubReview
    {
        $this->positiveId($reviewId);
        $record = $this->reviewRecord($this->get($token, $this->path($repository, $number).'/'.$reviewId)->json());
        if ($record->id !== $reviewId) {
            throw GitHubApiException::unavailable();
        }

        return $record;
    }

    /** @return list<GitHubReviewComment> */
    public function comments(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): array
    {
        $this->positiveId($reviewId);

        return array_map($this->commentRecord(...), $this->pages($token, $this->path($repository, $number).'/'.$reviewId.'/comments', 5, $repository));
    }

    private function path(GitHubRepository $repository, int $number): string
    {
        $this->positiveId($number);

        return $this->repositoryPath($repository).'/pulls/'.$number.'/reviews';
    }

    private function repositoryPath(GitHubRepository $repository): string
    {
        return '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);
    }

    private function get(#[SensitiveParameter] string $token, string $path): Response
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->timeout(10)
                ->withoutRedirecting()
                ->withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'orbit-gateway',
                ])->get($path);
        } catch (Throwable) {
            throw GitHubApiException::unavailable();
        }
        if ($response->status() !== 200) {
            throw GitHubApiException::unavailable();
        }

        return $response;
    }

    /** @return list<array<array-key, mixed>> */
    private function pages(#[SensitiveParameter] string $token, string $path, int $limit, GitHubRepository $repository): array
    {
        $records = [];
        $seen = [];
        $last = null;
        $repositoryId = null;
        for ($page = 1; $page <= $limit; $page++) {
            $response = $this->get($token, $path.'?per_page=100&page='.$page);
            $rows = $response->json();
            if (! str_starts_with(ltrim($response->body()), '[')
                || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 100
                || ($page > 1 && $rows === [])) {
                throw GitHubApiException::unavailable();
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw GitHubApiException::unavailable();
                }
                $id = $this->positiveId($row['id'] ?? null);
                if (isset($seen[$id])) {
                    throw GitHubApiException::unavailable();
                }
                $seen[$id] = true;
                $records[] = $row;
            }
            $links = $this->links($response->header('Link'), $path, $token, $repository, $repositoryId);
            if (isset($links['last'])) {
                if ($last !== null && $last !== $links['last']) {
                    throw GitHubApiException::unavailable();
                }
                $last = $links['last'];
            }
            if (
                (isset($links['prev']) && $links['prev'] !== $page - 1)
                || (isset($links['first']) && $links['first'] !== 1)
                || ($last !== null && $last < $page)
                || ($last !== null && $last > $page && ! isset($links['next']))
                || ($last === $page && isset($links['next']))
            ) {
                throw GitHubApiException::unavailable();
            }
            if (! isset($links['next'])) {
                return $records;
            }
            if ($links['next'] !== $page + 1 || $page === $limit || $rows === []) {
                throw GitHubApiException::unavailable();
            }
        }

        throw GitHubApiException::unavailable();
    }

    /** @return array<string, int> */
    private function links(string $header, string $path, #[SensitiveParameter] string $token, GitHubRepository $repository, ?int &$repositoryId): array
    {
        if ($header === '') {
            return [];
        }
        $links = [];
        foreach (explode(',', $header) as $link) {
            if (preg_match('/\A\s*<([^<>\s]+)>;\s*rel="(next|prev|first|last)"\s*\z/D', $link, $match) !== 1) {
                throw GitHubApiException::unavailable();
            }
            $url = parse_url($match[1]);
            if (
                ! is_array($url)
                || ($url['scheme'] ?? null) !== 'https'
                || ($url['host'] ?? null) !== 'api.github.com'
                || isset($url['user']) || isset($url['pass'])
                || isset($url['port']) || isset($url['fragment'])
                || isset($links[$match[2]])
            ) {
                throw GitHubApiException::unavailable();
            }
            $query = $url['query'] ?? '';
            if (preg_match('/\A(?:per_page=100&page=([1-9][0-9]*)|page=([1-9][0-9]*)&per_page=100)\z/D', $query, $values) !== 1) {
                throw GitHubApiException::unavailable();
            }
            if (! $this->paginationPath($url['path'] ?? '', $path, $token, $repository, $repositoryId)) {
                throw GitHubApiException::unavailable();
            }
            $number = $values[1] !== '' ? $values[1] : $values[2];
            $integer = filter_var($number, FILTER_VALIDATE_INT);
            $links[$match[2]] = $this->positiveId($integer);
        }

        return $links;
    }

    /**
     * GitHub emits /repositories/{id} links even for requests to /repos/{owner}/{name}.
     * Confirm that ID through the fixed named-repository endpoint, never from the Link itself.
     */
    private function paginationPath(string $linkPath, string $path, #[SensitiveParameter] string $token, GitHubRepository $repository, ?int &$repositoryId): bool
    {
        if ($linkPath === $path) {
            return true;
        }
        $repositoryPath = $this->repositoryPath($repository);
        $suffix = substr($path, strlen($repositoryPath));
        if (preg_match('#\A/repositories/[1-9][0-9]*'.preg_quote($suffix, '#').'\z#D', $linkPath) !== 1) {
            return false;
        }
        if ($repositoryId === null) {
            $record = $this->record($this->get($token, $repositoryPath)->json());
            if (strcasecmp($this->requiredText($record['full_name'] ?? null), $repository->owner.'/'.$repository->name) !== 0) {
                throw GitHubApiException::unavailable();
            }
            $repositoryId = $this->positiveId($record['id'] ?? null);
        }

        return $linkPath === '/repositories/'.$repositoryId.$suffix;
    }

    private function reviewRecord(mixed $row): GitHubReview
    {
        $row = $this->record($row);
        $user = $this->record($row['user'] ?? null);
        $state = is_string($row['state'] ?? null) ? GitHubReviewState::tryFrom($row['state']) : null;
        if ($state === null || ! is_string($row['body'] ?? null)) {
            throw GitHubApiException::unavailable();
        }

        return new GitHubReview(
            id: $this->positiveId($row['id'] ?? null),
            reviewerId: $this->positiveId($user['id'] ?? null),
            reviewerLogin: $this->requiredText($user['login'] ?? null),
            state: $state,
            commitId: $this->sha($row['commit_id'] ?? null),
            submittedAt: $this->time($row['submitted_at'] ?? null, $state === GitHubReviewState::Pending),
            url: $this->sourceUrl($row['html_url'] ?? null),
            body: $row['body'],
        );
    }

    private function commentRecord(mixed $row): GitHubReviewComment
    {
        $row = $this->record($row);
        $user = $this->record($row['user'] ?? null);
        if (! is_string($row['body'] ?? null)) {
            throw GitHubApiException::unavailable();
        }

        return new GitHubReviewComment(
            id: $this->positiveId($row['id'] ?? null),
            reviewId: $this->positiveId($row['pull_request_review_id'] ?? null),
            authorId: $this->positiveId($user['id'] ?? null),
            authorLogin: $this->requiredText($user['login'] ?? null),
            url: $this->sourceUrl($row['html_url'] ?? null),
            body: $row['body'],
            path: $this->optionalText($row['path'] ?? null),
            diffHunk: $this->optionalText($row['diff_hunk'] ?? null),
            line: $this->optionalId($row['line'] ?? null),
            startLine: $this->optionalId($row['start_line'] ?? null),
            originalLine: $this->optionalId($row['original_line'] ?? null),
            originalStartLine: $this->optionalId($row['original_start_line'] ?? null),
            side: $this->optionalChoice($row['side'] ?? null, ['LEFT', 'RIGHT']),
            startSide: $this->optionalChoice($row['start_side'] ?? null, ['LEFT', 'RIGHT']),
            position: $this->optionalId($row['position'] ?? null),
            originalPosition: $this->optionalId($row['original_position'] ?? null),
            commitId: isset($row['commit_id']) ? $this->sha($row['commit_id']) : null,
            originalCommitId: isset($row['original_commit_id']) ? $this->sha($row['original_commit_id']) : null,
            inReplyToId: $this->optionalId($row['in_reply_to_id'] ?? null),
            subjectType: $this->optionalChoice($row['subject_type'] ?? null, ['line', 'file']),
            createdAt: $this->time($row['created_at'] ?? null, true),
            updatedAt: $this->time($row['updated_at'] ?? null, true),
        );
    }

    /** @return array<array-key, mixed> */
    private function record(mixed $row): array
    {
        if (! is_array($row) || array_is_list($row)) {
            throw GitHubApiException::unavailable();
        }

        return $row;
    }

    private function positiveId(mixed $id): int
    {
        if (! is_int($id) || $id <= 0) {
            throw GitHubApiException::unavailable();
        }

        return $id;
    }

    private function optionalId(mixed $id): ?int
    {
        return $id === null ? null : $this->positiveId($id);
    }

    private function requiredText(mixed $text): string
    {
        if (! is_string($text) || trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')) {
            throw GitHubApiException::unavailable();
        }

        return $text;
    }

    private function optionalText(mixed $text): ?string
    {
        if ($text === null) {
            return null;
        }
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            throw GitHubApiException::unavailable();
        }

        return $text;
    }

    /** @param list<string> $choices */
    private function optionalChoice(mixed $value, array $choices): ?string
    {
        if ($value !== null && (! is_string($value) || ! in_array($value, $choices, true))) {
            throw GitHubApiException::unavailable();
        }

        return $value;
    }

    private function sha(mixed $sha): string
    {
        if (! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw GitHubApiException::unavailable();
        }

        return $sha;
    }

    private function sourceUrl(mixed $url): string
    {
        $url = $this->requiredText($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            throw GitHubApiException::unavailable();
        }

        return $url;
    }

    private function time(mixed $time, bool $nullable): ?DateTimeImmutable
    {
        if ($time === null && $nullable) {
            return null;
        }
        if (! is_string($time) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $time) !== 1) {
            throw GitHubApiException::unavailable();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $time, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $time) {
            throw GitHubApiException::unavailable();
        }

        return $date;
    }
}
