<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

describe('RepositoryReadAccess', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('asks GitHub for nothing without a Project or for another host', function (): void {
        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop', ProjectSourceAccess::GitHubApp)->isEmpty())->toBeTrue();

        GitHubTestSupport::storeApp();

        expect(app(RepositoryReadAccess::class)->for('https://gitlab.com/acme/shop', ProjectSourceAccess::GitHubApp)->isEmpty())->toBeTrue();

        Http::assertNothingSent();
    });

    it('creates a read-only token for the one covered repository', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_sentinel'], 201),
        ]);

        $environment = app(RepositoryReadAccess::class)->for('git@github.com:acme/shop.git', ProjectSourceAccess::GitHubApp);

        expect($environment->variables['GIT_CONFIG_VALUE_0'])
            ->toBe('Authorization: Basic '.base64_encode('x-access-token:ghs_sentinel'));

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'read']]);
    });

    it('reads without a credential when no installation covers the repository', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake(['https://api.github.com/repos/acme/shop/installation' => Http::response([], 404)]);

        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop', ProjectSourceAccess::GitHubApp)->isEmpty())->toBeTrue();
    });

    it('reads without a credential when the stored private key cannot be decrypted', function (): void {
        GitHubTestSupport::storeApp();
        Setting::query()->where('key', 'github.app.private_key')->update(['value' => 'not-encrypted']);

        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop', ProjectSourceAccess::GitHubApp)->isEmpty())->toBeTrue();

        Http::assertNothingSent();
    });

    it('reads without a credential when GitHub is unavailable', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake(['https://api.github.com/*' => Http::response([], 503)]);

        expect(app(RepositoryReadAccess::class)->for('https://github.com/acme/shop', ProjectSourceAccess::GitHubApp)->isEmpty())->toBeTrue();
    });

    it('reads a gh_cli repository with the GitHub CLI token and never asks the App', function (): void {
        GitHubTestSupport::storeApp();
        app()->instance(GitHubCliToken::class, new class implements GitHubCliToken
        {
            public function token(): string
            {
                return 'gho_sentinel000000000000000000';
            }
        });

        $environment = app(RepositoryReadAccess::class)->for('git@github.com:acme/shop.git', ProjectSourceAccess::GhCli);

        expect($environment->variables['GIT_CONFIG_VALUE_0'])
            ->toBe('Authorization: Basic '.base64_encode('x-access-token:gho_sentinel000000000000000000'))
            ->and($environment->variables['GIT_CONFIG_KEY_1'])->toBe('url.https://github.com/.insteadOf');

        Http::assertNothingSent();
    });

    it('never falls back to a read without a credential when the GitHub CLI has no login', function (): void {
        app()->instance(GitHubCliToken::class, new class implements GitHubCliToken
        {
            public function token(): string
            {
                throw new ResourceOperationException('github.cli_unauthenticated', 'No login.');
            }
        });

        expect(fn () => app(RepositoryReadAccess::class)->for('https://github.com/acme/shop', ProjectSourceAccess::GhCli))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('github.cli_unauthenticated'));
    });

    it('refuses a gh_cli read of a repository on another host', function (): void {
        expect(fn () => app(RepositoryReadAccess::class)->for('https://gitlab.com/acme/shop', ProjectSourceAccess::GhCli))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('project.source_access_invalid'));
    });
});
