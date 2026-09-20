<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppInstallData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppRegistration;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubInstallation;
use App\Domain\Shared\ResourceOperationException;

final readonly class InstallGitHubAppAction
{
    public function __construct(
        private GitHubAppStore $store,
        private GitHubApi $github,
    ) {}

    public function handle(string $gatewayUrl, string $name, ?string $owner): GitHubAppInstallData
    {
        $credentials = $this->store->credentials();

        if (! $credentials instanceof GitHubAppCredentials) {
            $state = bin2hex(random_bytes(32));
            $this->store->beginRegistration(new GitHubAppRegistration(
                state: $state,
                name: $name,
                owner: $owner,
                gatewayUrl: $gatewayUrl,
                expiresAt: now()->getTimestamp() + GitHubAppRegistration::LIFETIME_SECONDS,
            ));

            return new GitHubAppInstallData(
                step: 'register',
                url: "{$gatewayUrl}/api/v1/github/app/register?state={$state}",
                accounts: [],
            );
        }

        try {
            $installations = $this->github->installations($credentials);
        } catch (GitHubApiException $exception) {
            throw new ResourceOperationException(
                errorCode: 'github.unavailable',
                message: $exception->getMessage(),
                status: 502,
                previous: $exception,
            );
        }

        return new GitHubAppInstallData(
            step: 'install',
            url: $credentials->installUrl(),
            accounts: array_map(
                static fn (GitHubInstallation $installation): string => $installation->account,
                $installations,
            ),
        );
    }
}
