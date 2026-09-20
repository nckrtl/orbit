<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppRegistration;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

/**
 * Finishes an App registration when GitHub redirects the operator's browser to the Gateway. The
 * `state` must match the pending registration, and the one-time code is exchanged once.
 */
final readonly class CompleteGitHubAppRegistrationAction
{
    public function __construct(
        private GitHubAppStore $store,
        private GitHubApi $github,
    ) {}

    public function handle(#[SensitiveParameter] string $state, #[SensitiveParameter] string $code): GitHubAppCredentials
    {
        $registration = $this->store->registration();

        if (
            ! $registration instanceof GitHubAppRegistration
            || ! $registration->matches($state, now()->getTimestamp())
            || $this->store->credentials() instanceof GitHubAppCredentials
        ) {
            throw new ResourceOperationException(
                errorCode: 'github.registration_invalid',
                message: 'The redirect does not match a pending GitHub App registration. Run github:app:install again.',
            );
        }

        try {
            $credentials = $this->github->convertManifest($code);
        } catch (GitHubApiException $exception) {
            throw new ResourceOperationException(
                errorCode: 'github.registration_failed',
                message: 'GitHub refused to exchange the one-time code.',
                status: 502,
                previous: $exception,
            );
        }

        $this->store->put($credentials);

        return $credentials;
    }
}
