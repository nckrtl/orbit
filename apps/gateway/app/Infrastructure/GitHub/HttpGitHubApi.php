<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubInstallation;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final readonly class HttpGitHubApi implements GitHubApi
{
    private const string BASE_URL = 'https://api.github.com';

    private const float TIMEOUT = 10.0;

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
                ->get('/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name).'/installation'),
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

    public function repositoryPullRequestToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string {
        return $this->repositoryToken($credentials, $installationId, $repository, ['contents' => 'write', 'pull_requests' => 'write']);
    }

    public function openPullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubPullRequestDraft $draft): string
    {
        $path = '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name).'/pulls';
        $response = $this->send(fn (): Response => $this->request()->withToken($token)->post($path, [
            'title' => $draft->title,
            'head' => $draft->head,
            'base' => $draft->base,
            'body' => $draft->body,
        ]));
        $url = $response->json('html_url');
        if ($response->successful() && is_string($url) && $url !== '') {
            return $url;
        }
        if ($response->status() !== 422) {
            throw GitHubApiException::refused();
        }

        $existing = $this->send(fn (): Response => $this->request()->withToken($token)->get($path, [
            'state' => 'open',
            'head' => $repository->owner.':'.$draft->head,
            'base' => $draft->base,
        ]));
        $url = $existing->json('0.html_url');
        if (! $existing->successful() || ! is_string($url) || $url === '') {
            throw GitHubApiException::refused();
        }

        return $url;
    }

    public function pullRequestState(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): GitHubPullRequestState
    {
        $response = $this->send(fn (): Response => $this->request()->withToken($token)
            ->get('/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name).'/pulls/'.$number));
        if (! $response->successful()) {
            throw GitHubApiException::unavailable();
        }
        if ($response->json('merged') === true) {
            return GitHubPullRequestState::Merged;
        }

        return $response->json('state') === 'closed' ? GitHubPullRequestState::Closed : GitHubPullRequestState::Open;
    }

    /** @param array<string, string> $permissions */
    private function repositoryToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
        array $permissions,
    ): string {
        $response = $this->send(
            fn (): Response => $this->asApp($credentials)->post("/app/installations/{$installationId}/access_tokens", [
                'repositories' => [$repository->name],
                'permissions' => $permissions,
            ]),
        );

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw GitHubApiException::unavailable();
        }

        return $token;
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
