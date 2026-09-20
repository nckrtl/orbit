<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;

final readonly class ShowGitHubAppAction
{
    public function __construct(
        private GitHubAppStore $store,
        private GitHubApi $github,
    ) {}

    public function handle(): GitHubAppData
    {
        $credentials = $this->store->credentials();

        if (! $credentials instanceof GitHubAppCredentials) {
            throw new ResourceOperationException(
                errorCode: 'github.app_missing',
                message: 'The Gateway has no GitHub App. Run github:app:install.',
                status: 404,
            );
        }

        try {
            return new GitHubAppData($credentials, $this->github->installations($credentials));
        } catch (GitHubApiException $exception) {
            throw new ResourceOperationException(
                errorCode: 'github.unavailable',
                message: $exception->getMessage(),
                status: 502,
                previous: $exception,
            );
        }
    }
}
