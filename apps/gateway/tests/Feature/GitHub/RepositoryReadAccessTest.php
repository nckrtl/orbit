<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

describe('RepositoryReadAccess', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('asks GitHub for nothing without an App or for another host', function (): void {
        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop')->isEmpty())->toBeTrue();

        GitHubTestSupport::storeApp();

        expect(app(RepositoryReadAccess::class)->for('https://gitlab.com/acme/shop')->isEmpty())->toBeTrue();

        Http::assertNothingSent();
    });

    it('creates a read-only token for the one covered repository', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_sentinel'], 201),
        ]);

        $environment = app(RepositoryReadAccess::class)->for('git@github.com:acme/shop.git');

        expect($environment->variables['GIT_CONFIG_VALUE_0'])
            ->toBe('Authorization: Basic '.base64_encode('x-access-token:ghs_sentinel'));

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'read']]);
    });

    it('reads without a credential when no installation covers the repository', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake(['https://api.github.com/repos/acme/shop/installation' => Http::response([], 404)]);

        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop')->isEmpty())->toBeTrue();
    });

    it('reads without a credential when GitHub is unavailable', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake(['https://api.github.com/*' => Http::response([], 503)]);

        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop')->isEmpty())->toBeTrue();
    });
});
