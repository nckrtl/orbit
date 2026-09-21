<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskPullRequestOpener;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\AppInstance;
use App\Models\TaskGroup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

final readonly class HttpGitHubTaskPullRequestOpener implements TaskPullRequestOpener
{
    private const string BASE_URL = 'https://api.github.com';

    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 10.0;

    public function open(TaskGroup $group): ?string
    {
        $token = $this->string(config('orbit.tasks.github_token'));

        if ($token === null) {
            return null;
        }

        $group->loadMissing(['app', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        $instance = $group->taskable;

        if (! $repository instanceof GitHubRepository || ! $instance instanceof AppInstance) {
            return null;
        }

        $head = $instance->branch !== null && $instance->branch !== ''
            ? $instance->branch
            : TaskWorkspaceName::for($group);
        $base = $group->app->default_branch !== null && $group->app->default_branch !== ''
            ? $group->app->default_branch
            : 'main';

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post('/repos/'.$repository->owner.'/'.$repository->name.'/pulls', [
                    'title' => $group->title,
                    'body' => $group->brief,
                    'head' => $head,
                    'base' => $base,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $url = $response->json('html_url');

        if (! $response->successful() || ! is_string($url) || $url === '') {
            return null;
        }

        return $url;
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
