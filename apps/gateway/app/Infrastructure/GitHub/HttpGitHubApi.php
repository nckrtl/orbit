<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubBranchPullRequest;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubInstallation;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubOpenedPullRequest;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\Tasks\TaskBranchUpdate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class HttpGitHubApi implements GitHubApi
{
    private const string BASE_URL = 'https://api.github.com';

    private const float TIMEOUT = 10.0;

    private const int PAGE_SIZE = 100;

    private const int CHECK_RUN_PAGES = 10;

    private const int OPEN_PULL_REQUEST_PAGES = 3;

    public function __construct(private HttpGitHubReviewReader $reviewReader) {}

    /**
     * Laravel's `post($url)` JSON-encodes an omitted payload as `[]`. GitHub's
     * convert schema rejects that array (`[] is not a null or object`).
     */
    public function convertManifest(#[SensitiveParameter] string $code): GitHubAppCredentials
    {
        $response = $this->send(
            fn (): Response => $this->request()
                ->withBody('', 'application/json')
                ->post('/app-manifests/'.rawurlencode($code).'/conversions'),
        );

        if (! $response->successful()) {
            throw GitHubApiException::refused();
        }

        $appId = $response->json('id');
        $slug = $response->json('slug');
        $name = $response->json('name');
        $owner = $response->json('owner.login');
        $ownerType = $response->json('owner.type');
        $url = $response->json('html_url');
        $privateKey = $response->json('pem');

        if (
            ! is_int($appId)
            || ! is_string($slug)
            || ! is_string($name)
            || ! is_string($owner)
            || ! is_string($ownerType)
            || ! is_string($url)
            || ! is_string($privateKey)
            || $privateKey === ''
        ) {
            throw GitHubApiException::refused();
        }

        return new GitHubAppCredentials(
            appId: $appId,
            slug: $slug,
            name: $name,
            owner: $owner,
            ownerType: strtolower($ownerType) === 'organization' ? 'organization' : 'user',
            url: $url,
            privateKey: $privateKey,
        );
    }

    public function installations(GitHubAppCredentials $credentials): array
    {
        $installations = [];

        for ($page = 1; $page <= 10; $page++) {
            $response = $this->send(fn (): Response => $this->asApp($credentials)->get('/app/installations', [
                'per_page' => 100,
                'page' => $page,
            ]));

            if (! $response->successful()) {
                throw GitHubApiException::unavailable();
            }

            $rows = $response->json();

            if (! is_array($rows)) {
                throw GitHubApiException::unavailable();
            }

            foreach ($rows as $row) {
                $installation = $this->installation($row);

                if ($installation instanceof GitHubInstallation) {
                    $installations[] = $installation;
                }
            }

            if (count($rows) < 100) {
                break;
            }
        }

        return $installations;
    }

    public function repositoryInstallation(GitHubAppCredentials $credentials, GitHubRepository $repository): ?int
    {
        $response = $this->send(
            fn (): Response => $this->asApp($credentials)
                ->get($this->repositoryPath($repository).'/installation'),
        );

        if ($response->status() === 404) {
            return null;
        }

        $id = $response->json('id');

        if (! $response->successful() || ! is_int($id)) {
            throw GitHubApiException::unavailable();
        }

        return $id;
    }

    public function repositoryReadToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string {
        return $this->repositoryToken($credentials, $installationId, $repository, ['contents' => 'read']);
    }

    public function repositorySandboxToken(GitHubAppCredentials $credentials, int $installationId, GitHubRepository $repository): string
    {
        return $this->repositoryToken($credentials, $installationId, $repository, ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write', 'actions' => 'read']);
    }

    public function repositoryPullRequestToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string {
        return $this->repositoryToken($credentials, $installationId, $repository, ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']);
    }

    public function repositoryPullRequestReadToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string {
        return $this->repositoryToken($credentials, $installationId, $repository, ['pull_requests' => 'read']);
    }

    public function pullRequestsByHead(#[SensitiveParameter] string $token, GitHubRepository $repository, string $head): array
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)
            ->get($this->repositoryPath($repository).'/pulls', [
                'head' => $repository->owner.':'.$head,
                'state' => 'all',
            ]));
        $rows = $response->json();
        if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows)) {
            throw GitHubApiException::unavailable();
        }
        $pullRequests = [];
        foreach ($rows as $row) {
            $url = is_array($row) ? $this->text($row['html_url'] ?? null) : null;
            $number = is_array($row) ? ($row['number'] ?? null) : null;
            if ($url === null || ! is_int($number) || $repository->pullRequestNumber($url) !== $number
                || ! in_array($row['state'] ?? null, ['open', 'closed'], true)) {
                throw GitHubApiException::unavailable();
            }
            $state = match (true) {
                $this->text($row['merged_at'] ?? null) !== null => GitHubPullRequestState::Merged,
                $row['state'] === 'closed' => GitHubPullRequestState::Closed,
                default => GitHubPullRequestState::Open,
            };
            $pullRequests[] = new GitHubBranchPullRequest($url, $number, $state);
        }

        return $pullRequests;
    }

    public function repositoryChecksToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string {
        return $this->repositoryToken($credentials, $installationId, $repository, ['checks' => 'read']);
    }

    public function repositoryReviewsToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string {
        return $this->repositoryToken($credentials, $installationId, $repository, ['pull_requests' => 'read']);
    }

    public function reviews(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): array
    {
        return $this->reviewReader->reviews($token, $repository, $number);
    }

    public function review(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): GitHubReview
    {
        return $this->reviewReader->review($token, $repository, $number, $reviewId);
    }

    public function reviewComments(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): array
    {
        return $this->reviewReader->comments($token, $repository, $number, $reviewId);
    }

    public function openPullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubPullRequestDraft $draft): GitHubOpenedPullRequest
    {
        $path = $this->repositoryPath($repository).'/pulls';
        $response = $this->send(fn (): Response => $this->request()->withToken($token)->post($path, [
            'title' => $draft->title,
            'head' => $draft->head,
            'base' => $draft->base,
            'body' => $draft->body,
        ]));
        $opened = $this->openedPullRequest($repository, $response);
        if ($opened instanceof GitHubOpenedPullRequest) {
            return $opened;
        }
        if ($response->status() !== 422) {
            throw GitHubApiException::refused();
        }

        $existing = $this->send(fn (): Response => $this->request()->withToken($token)->get($path, [
            'state' => 'open',
            'head' => $repository->owner.':'.$draft->head,
            'base' => $draft->base,
        ]));
        $opened = $this->openedPullRequest($repository, $existing, prefix: '0.');
        if (! $existing->successful() || ! $opened instanceof GitHubOpenedPullRequest) {
            throw GitHubApiException::refused();
        }

        return $opened;
    }

    public function requestPullRequestReviewers(
        #[SensitiveParameter] string $token,
        GitHubRepository $repository,
        int $number,
        array $reviewers,
    ): void {
        if ($reviewers === []) {
            return;
        }

        $response = $this->send(fn (): Response => $this->request()->withToken($token)->post(
            $this->repositoryPath($repository).'/pulls/'.$number.'/requested_reviewers',
            ['reviewers' => $reviewers],
        ));
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message');

        throw GitHubApiException::reviewersRefused($response->status(), is_string($message) ? rtrim($message, '.') : '');
    }

    public function updatePullRequestBranch(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $headSha): TaskBranchUpdate
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)->put(
            $this->repositoryPath($repository).'/pulls/'.$number.'/update-branch',
            ['expected_head_sha' => $headSha],
        ));
        if ($response->status() === 202) {
            return TaskBranchUpdate::Accepted;
        }
        $message = $response->json('message');
        if ($response->status() === 422 && is_string($message) && str_contains(strtolower($message), 'merge conflict')) {
            return TaskBranchUpdate::Conflict;
        }

        return TaskBranchUpdate::Unavailable;
    }

    public function pullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): GitHubPullRequest
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)
            ->get($this->repositoryPath($repository).'/pulls/'.$number));
        if (! $response->successful()) {
            throw GitHubApiException::unavailable();
        }
        $state = match (true) {
            $response->json('merged') === true => GitHubPullRequestState::Merged,
            $response->json('state') === 'closed' => GitHubPullRequestState::Closed,
            default => GitHubPullRequestState::Open,
        };
        $mergeable = $response->json('mergeable');

        return new GitHubPullRequest(
            state: $state,
            mergeable: is_bool($mergeable) ? $mergeable : null,
            mergeableState: $this->text($response->json('mergeable_state')),
            headSha: $this->text($response->json('head.sha')),
            baseRef: $this->text($response->json('base.ref')),
            body: is_string($response->json('body')) ? $response->json('body') : null,
            mergeCommitSha: $this->text($response->json('merge_commit_sha')),
            mergedAt: $this->text($response->json('merged_at')),
        );
    }

    /**
     * Pages follow `total_count` from the first page. A count that changes between pages, or a page
     * that ends before the count is reached, means the list moved during the read, so it fails.
     */
    public function checkRuns(#[SensitiveParameter] string $token, GitHubRepository $repository, string $sha, ?string $checkName = null): array
    {
        $query = ['per_page' => self::PAGE_SIZE] + ($checkName !== null ? ['check_name' => $checkName] : []);
        $path = $this->repositoryPath($repository).'/commits/'.rawurlencode($sha).'/check-runs';
        $runs = [];
        $read = 0;
        $total = null;
        for ($page = 1; $page <= self::CHECK_RUN_PAGES; $page++) {
            $response = $this->send(fn (): Response => $this->request()->withToken($token)->get($path, $query + ['page' => $page]));
            $rows = $response->json('check_runs');
            $count = $response->json('total_count');
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || ! is_int($count) || $count < 0
                || ($total !== null && $count !== $total)) {
                throw GitHubApiException::unavailable();
            }
            $total = $count;
            $read += count($rows);
            foreach ($rows as $row) {
                $run = is_array($row) ? $this->checkRun($row) : null;
                if ($run instanceof GitHubCheckRun && ($checkName === null || $run->name === $checkName)) {
                    $runs[] = $run;
                }
            }
            if ($read > $total) {
                throw GitHubApiException::unavailable();
            }
            if ($read === $total) {
                return $runs;
            }
            if ($rows === []) {
                throw GitHubApiException::unavailable();
            }
        }

        throw GitHubApiException::unavailable();
    }

    /**
     * Lists from `refs/heads/<branch>`, so a tag or a SHA-like name cannot stand in for the branch.
     * Any malformed commit fails the whole list.
     */
    public function branchCommits(#[SensitiveParameter] string $token, GitHubRepository $repository, string $branch): array
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)
            ->get($this->repositoryPath($repository).'/commits', ['sha' => 'refs/heads/'.$branch, 'per_page' => self::PAGE_SIZE]));
        $rows = $response->json();
        if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) > self::PAGE_SIZE) {
            throw GitHubApiException::unavailable();
        }

        $commits = [];
        foreach ($rows as $row) {
            $sha = is_array($row) ? $this->sha($row['sha'] ?? null) : null;
            $parents = is_array($row) ? ($row['parents'] ?? null) : null;
            if ($sha === null || ! is_array($parents) || ! array_is_list($parents)) {
                throw GitHubApiException::unavailable();
            }
            $parentShas = [];
            foreach ($parents as $parent) {
                $parentSha = is_array($parent) ? $this->sha($parent['sha'] ?? null) : null;
                if ($parentSha === null) {
                    throw GitHubApiException::unavailable();
                }
                $parentShas[] = $parentSha;
            }
            $commits[] = new GitHubCommit($sha, $parentShas);
        }

        return $commits;
    }

    /** One commit per page keeps the response small; the relation fields do not depend on paging. */
    public function compareCommits(#[SensitiveParameter] string $token, GitHubRepository $repository, string $baseSha, string $headSha): GitHubCommitComparison
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)->get(
            $this->repositoryPath($repository).'/compare/'.rawurlencode($baseSha).'...'.rawurlencode($headSha),
            ['per_page' => 1],
        ));
        $status = $response->json('status');
        $status = is_string($status) ? GitHubComparisonStatus::tryFrom($status) : null;
        $base = $this->sha($response->json('base_commit.sha'));
        $mergeBase = $this->sha($response->json('merge_base_commit.sha'));
        if (! $response->successful() || ! $status instanceof GitHubComparisonStatus || $base !== $baseSha || $mergeBase === null) {
            throw GitHubApiException::unavailable();
        }

        return new GitHubCommitComparison($status, $base, $mergeBase);
    }

    public function openPullRequests(#[SensitiveParameter] string $token, GitHubRepository $repository): array
    {
        $pullRequests = [];
        for ($page = 1; $page <= self::OPEN_PULL_REQUEST_PAGES; $page++) {
            $response = $this->send(fn (): Response => $this->request()->withToken($token)->get($this->repositoryPath($repository).'/pulls', [
                'state' => 'open', 'sort' => 'created', 'direction' => 'asc', 'per_page' => self::PAGE_SIZE, 'page' => $page,
            ]));
            $rows = $response->json();
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) > self::PAGE_SIZE) {
                throw GitHubApiException::unavailable();
            }
            foreach ($rows as $row) {
                $pullRequests[] = is_array($row) ? $this->listedPullRequest($repository, $row) : throw GitHubApiException::unavailable();
            }
            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        return $pullRequests;
    }

    public function submitReview(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $commitId, GitHubReviewEvent $event, string $body): int
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)->post(
            $this->repositoryPath($repository).'/pulls/'.$number.'/reviews',
            ['commit_id' => $commitId, 'event' => $event->value, 'body' => $body],
        ));
        $id = $response->json('id');
        if (! $response->successful() || ! is_int($id) || $id < 1) {
            $message = $response->json('message');

            throw GitHubApiException::reviewRefused($response->status(), is_string($message) ? rtrim($message, '.') : '');
        }

        return $id;
    }

    public function mergePullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $sha): GitHubMergeResult
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)->put(
            $this->repositoryPath($repository).'/pulls/'.$number.'/merge',
            ['sha' => $sha, 'merge_method' => 'merge'],
        ));
        $message = $response->json('message');
        $message = is_string($message) ? rtrim($message, '.') : '';
        if ($response->successful() && $response->json('merged') === true) {
            return new GitHubMergeResult(true, $this->sha($response->json('sha')), $response->status(), $message);
        }
        if ($response->serverError()) {
            throw GitHubApiException::unavailable();
        }

        return new GitHubMergeResult(false, null, $response->status(), $message);
    }

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws GitHubApiException
     */
    private function listedPullRequest(GitHubRepository $repository, array $row): GitHubListedPullRequest
    {
        $number = $row['number'] ?? null;
        $url = $this->text($row['html_url'] ?? null);
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $head = is_array($row['head'] ?? null) ? $row['head'] : [];
        $base = is_array($row['base'] ?? null) ? $row['base'] : [];
        $headRepository = is_array($head['repo'] ?? null) ? $this->text($head['repo']['full_name'] ?? null) : null;
        $authorId = $user['id'] ?? null;
        $authorLogin = $this->text($user['login'] ?? null);
        $headRef = $this->text($head['ref'] ?? null);
        $headSha = $this->sha($head['sha'] ?? null);
        $baseRef = $this->text($base['ref'] ?? null);
        $title = is_string($row['title'] ?? null) ? $row['title'] : null;
        if (! is_int($number) || $url === null || $repository->pullRequestNumber($url) !== $number || ! is_int($authorId) || $authorId < 1
            || $authorLogin === null || $headRef === null || $headSha === null || $baseRef === null || $title === null) {
            throw GitHubApiException::unavailable();
        }

        return new GitHubListedPullRequest(
            number: $number,
            url: $url,
            title: $title,
            body: is_string($row['body'] ?? null) ? $row['body'] : null,
            authorId: $authorId,
            authorLogin: $authorLogin,
            headRef: $headRef,
            headSha: $headSha,
            headRepository: $headRepository,
            baseRef: $baseRef,
            draft: ($row['draft'] ?? false) === true,
        );
    }

    /** @param array<array-key, mixed> $row */
    private function checkRun(array $row): ?GitHubCheckRun
    {
        $name = $this->text($row['name'] ?? null);
        if ($name === null) {
            return null;
        }
        $id = $row['id'] ?? null;

        return new GitHubCheckRun(
            name: $name,
            conclusion: $this->text($row['conclusion'] ?? null),
            url: $this->text($row['html_url'] ?? null) ?? $this->text($row['details_url'] ?? null),
            id: is_int($id) ? $id : null,
            startedAt: $this->text($row['started_at'] ?? null),
            status: $this->text($row['status'] ?? null),
            headSha: $this->text($row['head_sha'] ?? null),
            appSlug: is_array($row['app'] ?? null) ? $this->text($row['app']['slug'] ?? null) : null,
        );
    }

    private function sha(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/D', $value) === 1 ? $value : null;
    }

    /** @param array<string, string> $permissions */
    private function repositoryToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
        array $permissions,
    ): string {
        $response = $this->send(
            fn (): Response => $this->asApp($credentials)->withoutRedirecting()->post("/app/installations/{$installationId}/access_tokens", [
                'repositories' => [$repository->name],
                'permissions' => $permissions,
            ]),
        );

        $token = $response->json('token');

        if ($response->clientError()) {
            $message = $response->json('message');

            throw GitHubApiException::tokenRefused($response->status(), is_string($message) ? rtrim($message, '.') : '');
        }

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw GitHubApiException::unavailable();
        }

        return $token;
    }

    private function openedPullRequest(GitHubRepository $repository, Response $response, string $prefix = ''): ?GitHubOpenedPullRequest
    {
        if ($prefix === '' && ! $response->successful()) {
            return null;
        }

        $url = $response->json($prefix.'html_url');
        if (! is_string($url) || $url === '') {
            return null;
        }

        $number = $response->json($prefix.'number');
        $number = is_int($number) && $number > 0 ? $number : $repository->pullRequestNumber($url);
        $author = $this->text($response->json($prefix.'user.login'));

        return new GitHubOpenedPullRequest($url, $number, $author);
    }

    private function repositoryPath(GitHubRepository $repository): string
    {
        return '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function installation(mixed $row): ?GitHubInstallation
    {
        if (! is_array($row)) {
            return null;
        }

        $id = $row['id'] ?? null;
        $account = is_array($row['account'] ?? null) ? ($row['account']['login'] ?? null) : null;
        $type = $row['target_type'] ?? null;
        $repositories = $row['repository_selection'] ?? null;

        if (! is_int($id) || ! is_string($account) || ! is_string($type) || ! is_string($repositories)) {
            return null;
        }

        return new GitHubInstallation(
            id: $id,
            account: $account,
            type: strtolower($type) === 'organization' ? 'organization' : 'user',
            repositories: $repositories === 'all' ? 'all' : 'selected',
            suspended: ($row['suspended_at'] ?? null) !== null,
        );
    }

    /** @param callable(): Response $request */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (Throwable) {
            throw GitHubApiException::unavailable();
        }
    }

    private function asApp(GitHubAppCredentials $credentials): PendingRequest
    {
        return $this->request()->withToken(GitHubAppJwt::sign($credentials, now()->getTimestamp()));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'orbit-gateway',
            ]);
    }
}
