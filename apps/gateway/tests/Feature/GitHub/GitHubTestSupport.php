<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;
use RuntimeException;

final class GitHubTestSupport
{
    private static ?string $privateKey = null;

    public static function privateKey(): string
    {
        if (self::$privateKey !== null) {
            return self::$privateKey;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Unable to create a test key.');
        }

        return self::$privateKey = $pem;
    }

    public static function credentials(): GitHubAppCredentials
    {
        return new GitHubAppCredentials(
            appId: 4242,
            slug: 'orbit-acme',
            name: 'orbit-acme',
            owner: 'acme',
            ownerType: 'organization',
            url: 'https://github.com/apps/orbit-acme',
            privateKey: self::privateKey(),
        );
    }

    /** @return array<string, mixed> */
    public static function review(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/GitHub/review.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function comment(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/GitHub/comment.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function repository(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/GitHub/repository.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public static function pagination(string $resource): array
    {
        $headers = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/GitHub/pagination.json'), true, flags: JSON_THROW_ON_ERROR);

        return $headers[$resource];
    }

    /** @return list<GitHubReview>|list<GitHubReviewComment>|GitHubReview */
    public static function readReviews(string $resource): array|GitHubReview
    {
        $api = app(GitHubApi::class);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/widgets.git');

        return match ($resource) {
            'reviews' => $api->reviews('review-secret', $repository, 7),
            'review' => $api->review('review-secret', $repository, 7, 101),
            'comments' => $api->reviewComments('review-secret', $repository, 7, 101),
        };
    }

    public static function storeApp(): GitHubAppCredentials
    {
        $credentials = self::credentials();
        app(GitHubAppStore::class)->put($credentials);

        return $credentials;
    }
}
