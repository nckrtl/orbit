<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppData;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;

/**
 * Deletes the App's stored identity and private key. GitHub offers no API that deletes an App
 * registration, so the result carries the GitHub page where the owner deletes it.
 */
final readonly class DestroyGitHubAppAction
{
    public function __construct(
        private GitHubAppStore $store,
    ) {}

    public function handle(): GitHubAppData
    {
        $credentials = $this->store->credentials();

        if (! $credentials instanceof GitHubAppCredentials) {
            throw new ResourceOperationException(
                errorCode: 'github.app_missing',
                message: 'The Gateway has no GitHub App.',
                status: 404,
            );
        }

        $this->store->delete();

        return new GitHubAppData($credentials, []);
    }
}
