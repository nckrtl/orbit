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
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if ($token === null || ! $repository instanceof GitHubRepository || ! is_string($group->pr_url)) {
            return null;
        }
        $number = $this->pullRequestNumber($repository, $group->pr_url);
        if ($number === null) {
            return null;
        }
        try {
            $response = Http::baseUrl('https://api.github.com')->connectTimeout(3)->timeout(10)->withoutRedirecting()->acceptJson()->withToken($token)->get('/repos/'.$repository->owner.'/'.$repository->name.'/pulls/'.$number);
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

    public function verifies(TaskGroup $group, string $url, string $commit): bool
    {
        $token = $this->string(config('orbit.tasks.github_token'));
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if ($token === null || ! $repository instanceof GitHubRepository) {
            return false;
        }
        $number = $this->pullRequestNumber($repository, $url);
        if ($number === null) {
            return false;
        }
        try {
            $client = Http::baseUrl('https://api.github.com')->connectTimeout(3)->timeout(10)->withoutRedirecting()->acceptJson()->withToken($token);
            $pr = $client->get('/repos/'.$repository->owner.'/'.$repository->name.'/pulls/'.$number);
            $repo = $client->get('/repos/'.$repository->owner.'/'.$repository->name);
            if (! $pr->successful() || ! $repo->successful()) {
                return false;
            }
            $defaultBranch = $repo->json('default_branch');
            if ($pr->json('html_url') !== $url
                || (string) $pr->json('number') !== $number
                || $pr->json('base.repo.full_name') !== $repository->owner.'/'.$repository->name
                || $pr->json('head.repo.full_name') !== $repository->owner.'/'.$repository->name
                || $pr->json('head.ref') !== 'task-'.$group->id
                || ! is_string($defaultBranch) || $defaultBranch === ''
                || $pr->json('base.ref') !== $defaultBranch) {
                return false;
            }

            return $pr->json('head.sha') === $commit;
        } catch (ConnectionException) {
            return false;
        }
    }

    private function pullRequestNumber(GitHubRepository $repository, string $url): ?string
    {
        $prefix = preg_quote('https://github.com/'.$repository->owner.'/'.$repository->name.'/pull/', '#');

        return preg_match('#\A'.$prefix.'([1-9][0-9]*)\z#D', $url, $matches) === 1 ? $matches[1] : null;
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
