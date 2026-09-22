<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Models\TaskGroup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

final readonly class HttpTaskPullRequestWatcher implements TaskPullRequestWatcher
{
    public function status(TaskGroup $group): ?string
    {
        $token = $this->string(config('orbit.tasks.github_token'));
        if ($token === null || ! is_string($group->pr_url) || preg_match('#/pull/(\d+)\z#', $group->pr_url, $match) !== 1) {
            return null;
        }
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if (! $repository instanceof GitHubRepository) {
            return null;
        }
        try {
            $response = Http::baseUrl('https://api.github.com')->connectTimeout(3)->timeout(10)->acceptJson()->withToken($token)->get('/repos/'.$repository->owner.'/'.$repository->name.'/pulls/'.$match[1]);
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        if ($response->json('merged') === true) {
            return 'merged';
        }

        return $response->json('state') === 'closed' ? 'closed' : 'open';
    }

    public function verifies(TaskGroup $group, string $commit): bool
    {
        $token = $this->string(config('orbit.tasks.github_token'));
        if ($token === null || ! is_string($group->pr_url) || preg_match('#/pull/(\d+)\z#', $group->pr_url, $match) !== 1) {
            return false;
        }
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if (! $repository instanceof GitHubRepository) {
            return false;
        }
        try {
            $client = Http::baseUrl('https://api.github.com')->connectTimeout(3)->timeout(10)->acceptJson()->withToken($token);
            $pr = $client->get('/repos/'.$repository->owner.'/'.$repository->name.'/pulls/'.$match[1]);
            $repo = $client->get('/repos/'.$repository->owner.'/'.$repository->name);
            if (! $pr->successful() || ! $repo->successful()) {
                return false;
            }
            if ($pr->json('base.repo.full_name') !== $repository->owner.'/'.$repository->name
                || $pr->json('head.ref') !== 'task-'.$group->id
                || $pr->json('base.ref') !== $repo->json('default_branch')) {
                return false;
            }
            $commits = $client->get('/repos/'.$repository->owner.'/'.$repository->name.'/pulls/'.$match[1].'/commits');
            if (! $commits->successful()) {
                return false;
            }

            return array_any(is_array($commits->json()) ? $commits->json() : [],
                static fn (mixed $item): bool => is_array($item) && ($item['sha'] ?? null) === $commit);
        } catch (ConnectionException) {
            return false;
        }
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
