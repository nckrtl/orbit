<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('HttpGitHubApi requested reviewers', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('posts the exact requested_reviewers payload', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers' => Http::response([
                'requested_reviewers' => [['login' => 'reviewbot']],
            ], 201),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        app(GitHubApi::class)->requestPullRequestReviewers('ghs_publish', $repository, 11, ['reviewbot']);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers'
            && $request->data() === ['reviewers' => ['reviewbot']]
            && $request->body() === '{"reviewers":["reviewbot"]}'
            && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
        Http::assertSentCount(1);
    });

    it('does not post requested_reviewers when the login list is empty', function (): void {
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        app(GitHubApi::class)->requestPullRequestReviewers('ghs_publish', $repository, 11, []);

        Http::assertNothingSent();
    });

    it('keeps the open pull request payload limited to title, head, base, and body', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls' => Http::response([
                'html_url' => 'https://github.com/acme/shop/pull/11',
                'number' => 11,
                'user' => ['login' => 'orbit-bot'],
            ], 201),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        $opened = app(GitHubApi::class)->openPullRequest('ghs_publish', $repository, new GitHubPullRequestDraft('task-7', 'main', 'Export orders', "Adds the export.\n"));

        expect($opened->url)->toBe('https://github.com/acme/shop/pull/11')
            ->and($opened->number)->toBe(11)
            ->and($opened->authorLogin)->toBe('orbit-bot');
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
            && $request->data() === ['title' => 'Export orders', 'head' => 'task-7', 'base' => 'main', 'body' => "Adds the export.\n"]);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
    });

    it('reuses the open pull request on 422 and still exposes its number', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls' => Http::response(['message' => 'A pull request already exists'], 422),
            'https://api.github.com/repos/acme/shop/pulls?*' => Http::response([[
                'html_url' => 'https://github.com/acme/shop/pull/12',
                'number' => 12,
                'user' => ['login' => 'orbit-bot'],
            ]]),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        $opened = app(GitHubApi::class)->openPullRequest('ghs_publish', $repository, new GitHubPullRequestDraft('task-7', 'main', 'Export orders', 'Body'));

        expect($opened->url)->toBe('https://github.com/acme/shop/pull/12')
            ->and($opened->number)->toBe(12)
            ->and($opened->authorLogin)->toBe('orbit-bot');
    });

    it('names the GitHub refusal when requested_reviewers fails', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers' => Http::response([
                'message' => 'Review cannot be requested from pull request author.',
            ], 422),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        expect(fn () => app(GitHubApi::class)->requestPullRequestReviewers('ghs_publish', $repository, 11, ['orbit-bot']))
            ->toThrow(GitHubApiException::class, 'GitHub refused the reviewer request (422): Review cannot be requested from pull request author.');
    });
});
