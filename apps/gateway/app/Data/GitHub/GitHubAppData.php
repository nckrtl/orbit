<?php

declare(strict_types=1);

namespace App\Data\GitHub;

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubInstallation;

final readonly class GitHubAppData
{
    /** @param list<GitHubInstallation> $installations */
    public function __construct(
        public GitHubAppCredentials $app,
        public array $installations,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     slug: string,
     *     app_id: int,
     *     owner: string,
     *     url: string,
     *     settings_url: string,
     *     installations: list<array{id: int, account: string, type: string, repositories: string, suspended: bool}>
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->app->name,
            'slug' => $this->app->slug,
            'app_id' => $this->app->appId,
            'owner' => $this->app->owner,
            'url' => $this->app->url,
            'settings_url' => $this->app->settingsUrl(),
            'installations' => array_map(
                static fn (GitHubInstallation $installation): array => $installation->toArray(),
                $this->installations,
            ),
        ];
    }
}
