<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

describe('GitHub App API', function (): void {
    beforeEach(function (): void {
        $this->markAsGateway(Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        Http::preventStrayRequests();
    });

    it('starts a registration on a Gateway without an App', function (): void {
        $response = $this
            ->postJson('https://gateway.orbit/api/v1/github/app/install', ['name' => 'orbit-acme', 'owner' => 'acme'])
            ->assertOk()
            ->assertJsonPath('data.step', 'register')
            ->assertJsonPath('data.accounts', []);

        $registration = app(GitHubAppStore::class)->registration();

        expect($registration)->not->toBeNull()
            ->and($response->json('data.url'))
            ->toBe("https://gateway.orbit/api/v1/github/app/register?state={$registration?->state}")
            ->and($registration?->name)->toBe('orbit-acme')
            ->and($registration?->owner)->toBe('acme')
            ->and(Setting::query()->where('key', 'github.app.registration')->value('value'))
            ->not->toContain((string) $registration?->state);
    });

    it('refuses a Project name or owner that GitHub cannot accept', function (array $body): void {
        $this->postJson('/api/v1/github/app/install', $body)->assertUnprocessable();
    })->with([
        [['name' => 'orbit/acme']],
        [['name' => str_repeat('a', 35)]],
        [['owner' => 'acme/../evil']],
    ]);

    it('serves the manifest page for the pending registration only', function (): void {
        $url = $this->postJson('https://gateway.orbit/api/v1/github/app/install', ['owner' => 'acme'])->json('data.url');
        $state = app(GitHubAppStore::class)->registration()?->state;

        $page = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $html = $page->getContent();
        preg_match('/name="manifest" value="([^"]+)"/', $html, $matches);
        $manifest = json_decode(
            html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($html)
            ->toContain('action="https://github.com/organizations/acme/settings/apps/new?state='.$state.'"')
            ->toContain('&quot;redirect_url&quot;:&quot;https://gateway.orbit/api/v1/github/app/callback&quot;')
            ->toContain('&quot;public&quot;:true')
            ->toContain('&quot;default_permissions&quot;:{&quot;checks&quot;:&quot;read&quot;,&quot;contents&quot;:&quot;write&quot;,&quot;metadata&quot;:&quot;read&quot;,&quot;pull_requests&quot;:&quot;write&quot;,&quot;workflows&quot;:&quot;write&quot;}')
            ->not->toContain('hook_attributes');
        expect($manifest)
            ->toBeArray()
            ->not->toHaveKey('default_events');

        $this->get('/api/v1/github/app/register?state='.str_repeat('0', 64))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'github.registration_invalid');
    });

    it('exchanges the one-time code, stores the App, and sends the browser to the install page', function (): void {
        $this->postJson('https://gateway.orbit/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
        $state = app(GitHubAppStore::class)->registration()?->state;
        Http::fake([
            'https://api.github.com/app-manifests/one-time-code/conversions' => Http::response([
                'id' => 4242,
                'slug' => 'orbit-acme',
                'name' => 'orbit-acme',
                'owner' => ['login' => 'acme', 'type' => 'Organization'],
                'html_url' => 'https://github.com/apps/orbit-acme',
                'pem' => GitHubTestSupport::privateKey(),
            ], 201),
        ]);

        $this->get("/api/v1/github/app/callback?code=one-time-code&state={$state}")
            ->assertRedirect('https://github.com/apps/orbit-acme/installations/new');

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === 'https://api.github.com/app-manifests/one-time-code/conversions'
                && $request->method() === 'POST'
                && $request->body() === ''
                && $request->body() !== '[]';
        });

        $store = app(GitHubAppStore::class);

        expect($store->credentials()?->appId)->toBe(4242)
            ->and($store->credentials()?->ownerType)->toBe('organization')
            ->and($store->registration())->toBeNull()
            ->and(Setting::query()->where('key', 'github.app.private_key')->value('value'))
            ->not->toContain('PRIVATE KEY');

        $this->get("/api/v1/github/app/callback?code=one-time-code&state={$state}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'github.registration_invalid');
    });

    it('refuses a redirect with a wrong or expired state and exchanges nothing', function (): void {
        $this->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
        $state = app(GitHubAppStore::class)->registration()?->state;

        $this->get('/api/v1/github/app/callback?code=one-time-code&state=wrong')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'github.registration_invalid');

        $this->travel(61)->minutes();

        $this->get("/api/v1/github/app/callback?code=one-time-code&state={$state}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'github.registration_invalid');

        Http::assertNothingSent();
    });

    it('reports a refused code exchange', function (): void {
        $this->postJson('/api/v1/github/app/install', ['name' => 'orbit'])->assertOk();
        $state = app(GitHubAppStore::class)->registration()?->state;
        Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->get("/api/v1/github/app/callback?code=spent&state={$state}")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'github.registration_failed');

        expect(app(GitHubAppStore::class)->credentials())->toBeNull();
    });

    it('opens the install page and lists current accounts when the Project exists', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/app/installations*' => Http::response([[
                'id' => 9,
                'account' => ['login' => 'acme'],
                'target_type' => 'Organization',
                'repository_selection' => 'selected',
                'suspended_at' => null,
            ]]),
        ]);

        $this->postJson('/api/v1/github/app/install', ['name' => 'orbit'])
            ->assertOk()
            ->assertJsonPath('data.step', 'install')
            ->assertJsonPath('data.url', 'https://github.com/apps/orbit-acme/installations/new')
            ->assertJsonPath('data.accounts', ['acme']);
    });

    it('shows the Project and its installations without the private key', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/app/installations*' => Http::response([[
                'id' => 9,
                'account' => ['login' => 'acme'],
                'target_type' => 'Organization',
                'repository_selection' => 'all',
                'suspended_at' => '2026-09-01T00:00:00Z',
            ]]),
        ]);

        $response = $this->getJson('/api/v1/github/app')
            ->assertOk()
            ->assertJsonPath('data.slug', 'orbit-acme')
            ->assertJsonPath('data.app_id', 4242)
            ->assertJsonPath('data.settings_url', 'https://github.com/organizations/acme/settings/apps/orbit-acme')
            ->assertJsonPath('data.installations', [[
                'id' => 9,
                'account' => 'acme',
                'type' => 'organization',
                'repositories' => 'all',
                'suspended' => true,
            ]]);

        expect($response->getContent())->not->toContain('PRIVATE KEY');

        Http::assertSent(static function (Request $request): bool {
            [$header, $claims] = explode('.', substr($request->header('Authorization')[0], 7));
            $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

            return $decoded['iss'] === '4242' && $decoded['exp'] - $decoded['iat'] === 600;
        });
    });

    it('reports an unexpected installation target type as a user', function (): void {
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/app/installations*' => Http::response([[
                'id' => 9,
                'account' => ['login' => 'acme'],
                'target_type' => 'Enterprise',
                'repository_selection' => 'everything',
                'suspended_at' => null,
            ]]),
        ]);

        $this->getJson('/api/v1/github/app')
            ->assertOk()
            ->assertJsonPath('data.installations.0.type', 'user')
            ->assertJsonPath('data.installations.0.repositories', 'selected');
    });

    it('reports a missing App and an unreachable GitHub', function (): void {
        $this->getJson('/api/v1/github/app')->assertNotFound()->assertJsonPath('error.code', 'github.app_missing');
        $this->deleteJson('/api/v1/github/app')->assertNotFound()->assertJsonPath('error.code', 'github.app_missing');

        GitHubTestSupport::storeApp();
        Http::fake(['https://api.github.com/*' => Http::response([], 500)]);

        $this->getJson('/api/v1/github/app')->assertStatus(502)->assertJsonPath('error.code', 'github.unavailable');
    });

    it('destroys the stored App and names the GitHub page that deletes the registration', function (): void {
        GitHubTestSupport::storeApp();

        $this->deleteJson('/api/v1/github/app')
            ->assertOk()
            ->assertJsonPath('data.settings_url', 'https://github.com/organizations/acme/settings/apps/orbit-acme');

        expect(app(GitHubAppStore::class)->credentials())->toBeNull()
            ->and(Setting::query()->where('key', 'like', 'github.%')->count())->toBe(0);

        Http::assertNothingSent();
    });
});

describe('read-only GitHub review records', function (): void {
    it('reads typed complete reviews and inline findings without following prose URLs', function (): void {
        Http::preventStrayRequests();
        $review = GitHubTestSupport::review();
        $comment = GitHubTestSupport::comment();
        $review['body'] = 'Read https://evil.example/steal?token=anything';
        Http::fake([
            'https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response([$review]),
            'https://api.github.com/repos/acme/widgets/pulls/7/reviews/101' => Http::response($review),
            'https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([$comment]),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/widgets.git');
        $api = app(GitHubApi::class);

        $reviews = $api->reviews('review-secret', $repository, 7);
        $selected = $api->review('review-secret', $repository, 7, 101);
        $comments = $api->reviewComments('review-secret', $repository, 7, 101);

        expect($reviews)->toHaveCount(1)
            ->and($selected)->toEqual($reviews[0])
            ->and($selected->id)->toBe(101)
            ->and($selected->reviewerId)->toBe(42)
            ->and($selected->reviewerLogin)->toBe('reviewer-renamed')
            ->and($selected->state)->toBe(GitHubReviewState::Commented)
            ->and($selected->submittedAt?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-02T22:13:41Z')
            ->and($selected->commitId)->toBe('89b4c98ac63c572b0d14868f2d9cf4c7097d6d2c')
            ->and($selected->body)->toBe($review['body'])
            ->and($comments)->toHaveCount(1)
            ->and($comments[0]->id)->toBe(201)
            ->and($comments[0]->reviewId)->toBe(101)
            ->and($comments[0]->authorId)->toBe(42)
            ->and($comments[0]->authorLogin)->toBe('reviewer-renamed')
            ->and($comments[0]->body)->toBe('Inline finding.')
            ->and($comments[0]->path)->toBe('src/example.php')
            ->and($comments[0]->diffHunk)->toBe("@@ -1 +1 @@\n-old\n+new")
            ->and($comments[0]->position)->toBe(1)
            ->and($comments[0]->originalPosition)->toBe(334)
            ->and($comments[0]->line)->toBeNull()
            ->and($comments[0]->side)->toBeNull();
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer review-secret'));
    });

    it('mints a separate single-repository token with only pull_requests read', function (): void {
        Http::preventStrayRequests();
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/repos/acme/widgets/installation' => Http::response(['id' => 99]),
            'https://api.github.com/app/installations/99/access_tokens' => Http::sequence()
                ->push(['token' => 'publish-secret'])->push(['token' => 'checks-secret'])->push(['token' => 'review-secret']),
            'https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response([GitHubTestSupport::review()]),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/widgets.git');
        $access = app(RepositoryPullRequestAccess::class);

        expect($access->token($repository))->toBe('publish-secret')
            ->and($access->checksToken($repository))->toBe('checks-secret');
        $token = $access->reviewsToken($repository);
        $records = app(GitHubApi::class)->reviews($token, $repository, 7);

        expect($token)->toBe('review-secret')
            ->and(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('secret')
            ->and(json_encode(Setting::query()->pluck('value')->all(), JSON_THROW_ON_ERROR))->not->toContain('secret');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/99/access_tokens'
            && $request->data() === ['repositories' => ['widgets'], 'permissions' => ['pull_requests' => 'read']]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/reviews?') && $request->hasHeader('Authorization', 'Bearer review-secret'));
        Http::assertSentCount(7);
    });

    it('does not fall back when the App is absent', function (): void {
        Http::preventStrayRequests();

        expect(fn () => app(RepositoryPullRequestAccess::class)->reviewsToken(
            GitHubRepository::fromOrigin('https://github.com/acme/widgets.git'),
        ))->toThrow(GitHubApiException::class, 'not registered');
        Http::assertNothingSent();
    });

    it('does not fall back when the repository is outside the installation', function (): void {
        Http::preventStrayRequests();
        GitHubTestSupport::storeApp();
        Http::fake(['https://api.github.com/repos/acme/widgets/installation' => Http::response([], 404)]);

        expect(fn () => app(RepositoryPullRequestAccess::class)->reviewsToken(
            GitHubRepository::fromOrigin('https://github.com/acme/widgets.git'),
        ))->toThrow(GitHubApiException::class, 'not installed');
        Http::assertSentCount(1);
    });

    it('propagates refused review-token permissions instead of returning null', function (): void {
        Http::preventStrayRequests();
        GitHubTestSupport::storeApp();
        Http::fake([
            'https://api.github.com/repos/acme/widgets/installation' => Http::response(['id' => 99]),
            'https://api.github.com/app/installations/99/access_tokens' => Http::response([
                'message' => 'The permissions requested are not granted to this installation.',
            ], 422),
        ]);

        expect(fn () => app(RepositoryPullRequestAccess::class)->reviewsToken(
            GitHubRepository::fromOrigin('https://github.com/acme/widgets.git'),
        ))->toThrow(GitHubApiException::class, 'permissions requested are not granted');
        Http::assertSentCount(2);
    });

    it('returns complete records through the exact allowed page and record bounds', function (string $resource, string $suffix, int $limit, bool $canonical): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix;
        $linkPath = $canonical ? 'https://api.github.com/repositories/555001/pulls/7/reviews'.$suffix : $path;
        $template = $resource === 'reviews' ? GitHubTestSupport::review() : GitHubTestSupport::comment();
        $responses = $canonical ? ['https://api.github.com/repos/acme/widgets' => Http::response(GitHubTestSupport::repository())] : [];
        for ($page = 1; $page <= $limit; $page++) {
            $rows = [];
            for ($index = 1; $index <= 100; $index++) {
                $rows[] = array_replace($template, ['id' => ($page - 1) * 100 + $index]);
            }
            $headers = $page < $limit ? ['Link' => '<'.$linkPath.'?page='.($page + 1).'&per_page=100>; rel="next", <'.$linkPath.'?page='.$limit.'&per_page=100>; rel="last"'] : [];
            $responses[$path.'?per_page=100&page='.$page] = Http::response($rows, 200, $headers);
        }
        Http::fake($responses);

        $records = GitHubTestSupport::readReviews($resource);

        expect($records)->toHaveCount($limit * 100)
            ->and($records[0]->id)->toBe(1)
            ->and($records[$limit * 100 - 1]->id)->toBe($limit * 100);
        Http::assertSentCount($limit + (int) $canonical);
    })->with(['10x100 reviews' => ['reviews', '', 10], '5x100 comments' => ['comments', '/101/comments', 5]])
        ->with(['named links' => false, 'canonical links' => true]);

    it('fails on a next link after the last allowed page whether full or short', function (string $resource, string $suffix, int $limit, int $count, bool $canonical): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix;
        $linkPath = $canonical ? 'https://api.github.com/repositories/555001/pulls/7/reviews'.$suffix : $path;
        $template = $resource === 'reviews' ? GitHubTestSupport::review() : GitHubTestSupport::comment();
        $responses = $canonical ? ['https://api.github.com/repos/acme/widgets' => Http::response(GitHubTestSupport::repository())] : [];
        for ($page = 1; $page <= $limit; $page++) {
            $rows = [];
            for ($index = 1; $index <= $count; $index++) {
                $rows[] = array_replace($template, ['id' => ($page - 1) * $count + $index]);
            }
            $responses[$path.'?per_page=100&page='.$page] = Http::response($rows, 200, [
                'Link' => '<'.$linkPath.'?per_page=100&page='.($page + 1).'>; rel="next"',
            ]);
        }
        Http::fake($responses);

        expect(fn () => GitHubTestSupport::readReviews($resource))->toThrow(GitHubApiException::class);
        Http::assertSentCount($limit + (int) $canonical);
    })->with(['reviews overflow' => ['reviews', '', 10], 'comments overflow' => ['comments', '/101/comments', 5]])
        ->with(['short page' => 1, 'full page' => 100])
        ->with(['named links' => false, 'canonical links' => true]);

    it('accepts a genuinely empty complete list', function (string $resource, string $suffix): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix.'?*' => Http::response([])]);

        expect(GitHubTestSupport::readReviews($resource))->toBe([]);
        Http::assertSentCount(1);
    })->with(['reviews' => ['reviews', ''], 'comments' => ['comments', '/101/comments']]);

    it('retains pending dismissed and tied decisions without selecting or skipping any', function (): void {
        Http::preventStrayRequests();
        $rows = [];
        foreach (GitHubReviewState::cases() as $index => $state) {
            $rows[] = array_replace(GitHubTestSupport::review(), [
                'id' => $index + 101, 'state' => $state->value,
                'submitted_at' => $state === GitHubReviewState::Pending ? null : '2026-09-02T22:13:41Z',
            ]);
        }
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response($rows)]);

        $records = GitHubTestSupport::readReviews('reviews');

        expect(array_column($records, 'state'))->toBe(GitHubReviewState::cases())
            ->and(array_column($records, 'id'))->toBe([101, 102, 103, 104, 105])
            ->and($records[0]->submittedAt)->toBeNull()
            ->and($records[1]->submittedAt)->toEqual($records[2]->submittedAt);
        Http::assertSentCount(1);
    });

    it('preserves supplied current original file and reply metadata without filtering', function (): void {
        Http::preventStrayRequests();
        $comment = array_replace(GitHubTestSupport::comment(), [
            'line' => null, 'start_line' => null, 'original_line' => 50, 'original_start_line' => 45,
            'side' => 'RIGHT', 'start_side' => 'LEFT', 'in_reply_to_id' => 199, 'subject_type' => 'file',
        ]);
        $otherAuthor = array_replace($comment, ['id' => 202, 'pull_request_review_id' => 102, 'user' => ['id' => 43, 'login' => 'other-author']]);
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([$comment, $otherAuthor])]);

        $records = GitHubTestSupport::readReviews('comments');

        expect($records)->toHaveCount(2)
            ->and($records[0]->line)->toBeNull()
            ->and($records[0]->startLine)->toBeNull()
            ->and($records[0]->originalLine)->toBe(50)
            ->and($records[0]->originalStartLine)->toBe(45)
            ->and($records[0]->side)->toBe('RIGHT')
            ->and($records[0]->startSide)->toBe('LEFT')
            ->and($records[0]->inReplyToId)->toBe(199)
            ->and($records[0]->subjectType)->toBe('file')
            ->and($records[1]->authorId)->toBe(43)
            ->and($records[1]->reviewId)->toBe(102);
        Http::assertSentCount(1);
    });

    it('leaves absent optional comment locations absent', function (): void {
        Http::preventStrayRequests();
        $comment = array_intersect_key(GitHubTestSupport::comment(), array_flip(['id', 'pull_request_review_id', 'user', 'body', 'html_url']));
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([$comment])]);

        $records = GitHubTestSupport::readReviews('comments');

        expect($records[0]->path)->toBeNull()
            ->and($records[0]->diffHunk)->toBeNull()
            ->and($records[0]->position)->toBeNull()
            ->and($records[0]->originalPosition)->toBeNull()
            ->and($records[0]->commitId)->toBeNull()
            ->and($records[0]->originalCommitId)->toBeNull()
            ->and($records[0]->createdAt)->toBeNull()
            ->and($records[0]->updatedAt)->toBeNull();
        Http::assertSentCount(1);
    });

    it('rejects every malformed selection record rather than skipping it', function (string $field, mixed $value, string $resource, string $suffix): void {
        Http::preventStrayRequests();
        $bad = GitHubTestSupport::review();
        Arr::set($bad, $field, $value);
        $body = $resource === 'review' ? $bad : [GitHubTestSupport::review(), array_replace($bad, ['id' => $field === 'id' ? $value : 102])];
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix => Http::response($body)]);

        expect(fn () => GitHubTestSupport::readReviews($resource))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    })->with([
        'zero ID' => ['id', 0], 'negative ID' => ['id', -1], 'string ID' => ['id', '101'],
        'float ID' => ['id', 101.5], 'missing ID' => ['id', null],
        'zero reviewer' => ['user.id', 0], 'string reviewer' => ['user.id', '42'],
        'deleted user' => ['user', null], 'empty login' => ['user.login', ''],
        'wrong login type' => ['user.login', []], 'unknown state' => ['state', 'UNKNOWN'],
        'lowercase state' => ['state', 'approved'], 'missing state' => ['state', null],
        'missing head' => ['commit_id', null], 'short head' => ['commit_id', 'abc'],
        'nonhex head' => ['commit_id', str_repeat('z', 40)], 'wrong head type' => ['commit_id', 123],
        'missing submission' => ['submitted_at', null], 'invalid submission' => ['submitted_at', 'yesterday'],
        'impossible date' => ['submitted_at', '2026-02-30T22:13:41Z'],
        'impossible time' => ['submitted_at', '2026-09-02T24:13:41Z'],
        'wrong time type' => ['submitted_at', 123], 'missing body' => ['body', null],
        'structured body' => ['body', ['text' => 'finding']], 'missing provenance' => ['html_url', null],
        'unsafe provenance' => ['html_url', 'javascript:alert(1)'],
    ])->with(['review list' => ['reviews', '?per_page=100&page=1'], 'selected review' => ['review', '/101']]);

    it('rejects malformed inline records instead of returning partial findings', function (string $field, mixed $value): void {
        Http::preventStrayRequests();
        $bad = array_replace(GitHubTestSupport::comment(), ['id' => 202]);
        Arr::set($bad, $field, $value);
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([GitHubTestSupport::comment(), $bad])]);

        expect(fn () => GitHubTestSupport::readReviews('comments'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    })->with([
        'string comment ID' => ['id', '202'], 'zero comment ID' => ['id', 0],
        'missing review ID' => ['pull_request_review_id', null], 'negative review ID' => ['pull_request_review_id', -1],
        'missing author' => ['user', null], 'invalid author ID' => ['user.id', '42'],
        'missing author login' => ['user.login', null], 'missing body' => ['body', null],
        'missing URL' => ['html_url', null], 'malformed path' => ['path', 5],
        'malformed diff' => ['diff_hunk', []], 'invalid line' => ['line', -1],
        'string start line' => ['start_line', '5'], 'invalid original line' => ['original_line', 0],
        'invalid original start line' => ['original_start_line', false],
        'invalid side' => ['side', 'MIDDLE'], 'invalid start side' => ['start_side', []],
        'invalid position' => ['position', -1], 'invalid original position' => ['original_position', '5'],
        'invalid reply' => ['in_reply_to_id', 0], 'invalid subject' => ['subject_type', 'directory'],
        'invalid head' => ['commit_id', 'abc'], 'invalid original head' => ['original_commit_id', 'abc'],
        'invalid creation' => ['created_at', '2026-02-30T22:13:41Z'],
        'invalid update' => ['updated_at', 'yesterday'],
    ]);

    it('rejects malformed page bodies including objects that resemble empty lists', function (mixed $body, string $resource, string $suffix): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix.'?*' => Http::response($body)]);

        expect(fn () => GitHubTestSupport::readReviews($resource))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    })->with([
        'object' => ['{}'], 'null' => ['null'], 'invalid JSON' => ['['],
        'wrapped list' => [['data' => []]], 'bad row' => [[false]], 'null row' => [[null]],
        '101 rows' => [array_fill(0, 101, GitHubTestSupport::review())],
    ])->with(['reviews' => ['reviews', ''], 'comments' => ['comments', '/101/comments']]);

    it('rejects hostile malformed and incomplete pagination without sending a token there', function (string $link): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response([GitHubTestSupport::review()], 200, ['Link' => $link])]);

        expect(fn () => GitHubTestSupport::readReviews('reviews'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    })->with([
        'off host' => ['<https://evil.example/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'suffix host' => ['<https://api.github.com.evil.example/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'plain HTTP' => ['<http://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'userinfo' => ['<https://user@api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'port' => ['<https://api.github.com:443/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'fragment' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2#fragment>; rel="next"'],
        'different owner' => ['<https://api.github.com/repos/other/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'different repository' => ['<https://api.github.com/repos/acme/other/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'different PR' => ['<https://api.github.com/repos/acme/widgets/pulls/8/reviews?per_page=100&page=2>; rel="next"'],
        'different endpoint' => ['<https://api.github.com/repos/acme/widgets/pulls/7/comments?per_page=100&page=2>; rel="next"'],
        'encoded path' => ['<https://api.github.com/repos/acme/widgets/pulls/7/%72eviews?per_page=100&page=2>; rel="next"'],
        'relative URL' => ['</repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'wrong page size' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=50&page=2>; rel="next"'],
        'missing page size' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?page=2>; rel="next"'],
        'zero page' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=0>; rel="next"'],
        'loop' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=1>; rel="next"'],
        'skipped page' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=3>; rel="next"'],
        'duplicate query' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2&page=3>; rel="next"'],
        'extra query' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2&token=steal>; rel="next"'],
        'broken syntax' => ['https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2; rel="next"'],
        'duplicate next' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next", <https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next"'],
        'missing next' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=3>; rel="last"'],
        'contradictory last' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next", <https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=1>; rel="last"'],
        'hostile last even with valid next' => ['<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="next", <https://evil.example/last>; rel="last"'],
    ]);

    it('rejects pagination to another selected review comment endpoint', function (): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([GitHubTestSupport::comment()], 200, [
            'Link' => '<https://api.github.com/repos/acme/widgets/pulls/7/reviews/102/comments?per_page=100&page=2>; rel="next"',
        ])]);

        expect(fn () => GitHubTestSupport::readReviews('comments'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    });

    it('rejects missing failing malformed repeated and incomplete later pages', function (mixed $body, int $status, array $headers): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews';
        Http::fake([
            $path.'?per_page=100&page=1' => Http::response([GitHubTestSupport::review()], 200, [
                'Link' => '<'.$path.'?per_page=100&page=2>; rel="next", <'.$path.'?per_page=100&page=3>; rel="last"',
            ]),
            $path.'?per_page=100&page=2' => Http::response($body, $status, $headers),
        ]);

        expect(fn () => GitHubTestSupport::readReviews('reviews'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(2);
    })->with([
        'empty linked page' => [[], 200, []],
        'missing page' => [[], 404, []], 'rate limited page' => [[], 429, []],
        'malformed page' => ['[', 200, []],
        'duplicate record across pages' => [[GitHubTestSupport::review()], 200, []],
        'lost next link' => [[array_replace(GitHubTestSupport::review(), ['id' => 102])], 200, []],
        'changed last page' => [[array_replace(GitHubTestSupport::review(), ['id' => 102])], 200, ['Link' => '<https://api.github.com/repos/acme/widgets/pulls/7/reviews?per_page=100&page=2>; rel="last"']],
    ]);

    it('fails closed on HTTP failures without echoing credentials or external error text', function (int $status, string $resource, string $suffix): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix => Http::response(['message' => 'review-secret'], $status)]);

        expect(fn () => GitHubTestSupport::readReviews($resource))->toThrow(GitHubApiException::class, 'GitHub could not be reached or refused the Project credential.');
        Http::assertSentCount(1);
    })->with([401, 403, 404, 429, 500, 502, 302, 204])
        ->with(['reviews' => ['reviews', '?per_page=100&page=1'], 'selected review' => ['review', '/101'], 'comments' => ['comments', '/101/comments?per_page=100&page=1']]);

    it('fails closed on transport errors without chaining an exception containing a token', function (string $resource, string $suffix): void {
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix => function () use (&$attempts): never {
            $attempts++;
            throw new ConnectionException('review-secret');
        }]);

        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');
        try {
            GitHubTestSupport::readReviews($resource);
            $this->fail('A failed read must throw.');
        } catch (GitHubApiException $exception) {
            expect($exception->getMessage())->not->toContain('review-secret')
                ->and($exception->getPrevious())->toBeNull()
                ->and(json_encode($exception->getTrace(), JSON_THROW_ON_ERROR))->not->toContain('review-secret');
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArguments);
        }
        expect($attempts)->toBe(1);
        Http::assertNothingSent();
    })->with(['reviews' => ['reviews', '?per_page=100&page=1'], 'selected review' => ['review', '/101'], 'comments' => ['comments', '/101/comments?per_page=100&page=1']]);

    it('disables redirect following before sending the review credential', function (): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => function (Request $request, array $options) {
            expect($options['allow_redirects'])->toBeFalse();

            return Http::response([], 302, ['Location' => 'https://evil.example/steal']);
        }]);

        expect(fn () => GitHubTestSupport::readReviews('reviews'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    });

    it('rejects a selected review response with a different ID', function (): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101' => Http::response(array_replace(GitHubTestSupport::review(), ['id' => 102]))]);

        expect(fn () => GitHubTestSupport::readReviews('review'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    });

    it('reads complete canonical numeric-repository pages from captured GitHub headers', function (string $resource, string $suffix, int $count): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix;
        $template = $resource === 'reviews' ? GitHubTestSupport::review() : GitHubTestSupport::comment();
        $responses = ['https://api.github.com/repos/acme/widgets' => Http::response(GitHubTestSupport::repository())];
        foreach (GitHubTestSupport::pagination($resource) as $index => $header) {
            $responses[$path.'?per_page=100&page='.($index + 1)] = Http::response([
                array_replace($template, ['id' => $index + 1]),
            ], 200, ['Link' => preg_replace('/per_page=[0-9]+/', 'per_page=100', $header)]);
        }
        Http::fake($responses);

        $records = GitHubTestSupport::readReviews($resource);

        expect($records)->toHaveCount($count)
            ->and($records[0]->id)->toBe(1)
            ->and($records[$count - 1]->id)->toBe($count);
        Http::assertSentCount($count + 1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/repositories/'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/widgets'
            && $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer review-secret'));
    })->with(['canonical reviews' => ['reviews', '', 7], 'canonical comments' => ['comments', '/101/comments', 5]]);

    it('rejects canonical pagination for any other repository ID', function (string $resource, string $suffix): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews'.$suffix;
        $template = $resource === 'reviews' ? GitHubTestSupport::review() : GitHubTestSupport::comment();
        $responses = ['https://api.github.com/repos/acme/widgets' => Http::response(GitHubTestSupport::repository())];
        foreach (GitHubTestSupport::pagination($resource) as $index => $header) {
            $responses[$path.'?per_page=100&page='.($index + 1)] = Http::response([
                array_replace($template, ['id' => $index + 1]),
            ], 200, ['Link' => str_replace('/repositories/555001/', '/repositories/999999/', preg_replace('/per_page=[0-9]+/', 'per_page=100', $header))]);
        }
        Http::fake($responses);

        expect(fn () => GitHubTestSupport::readReviews($resource))->toThrow(GitHubApiException::class);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/repositories/') || str_contains($request->url(), 'page=2'));
    })->with(['canonical reviews' => ['reviews', ''], 'canonical comments' => ['comments', '/101/comments']]);

    it('fails closed when canonical pagination cannot confirm the target repository identity', function (mixed $body, int $status): void {
        Http::preventStrayRequests();
        $header = preg_replace('/per_page=[0-9]+/', 'per_page=100', GitHubTestSupport::pagination('reviews')[0]);
        Http::fake([
            'https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response([GitHubTestSupport::review()], 200, ['Link' => $header]),
            'https://api.github.com/repos/acme/widgets' => Http::response($body, $status, ['Location' => 'https://evil.example/steal']),
        ]);

        expect(fn () => GitHubTestSupport::readReviews('reviews'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(2);
    })->with([
        'missing ID' => [['full_name' => 'acme/widgets'], 200],
        'zero ID' => [['id' => 0, 'full_name' => 'acme/widgets'], 200],
        'negative ID' => [['id' => -1, 'full_name' => 'acme/widgets'], 200],
        'string ID' => [['id' => '555001', 'full_name' => 'acme/widgets'], 200],
        'float ID' => [['id' => 555001.5, 'full_name' => 'acme/widgets'], 200],
        'different owner' => [['id' => 555001, 'full_name' => 'other/widgets'], 200],
        'different name' => [['id' => 555001, 'full_name' => 'acme/other'], 200],
        'missing name' => [['id' => 555001], 200],
        'malformed name' => [['id' => 555001, 'full_name' => []], 200],
        'malformed JSON' => ['{', 200], 'not found' => [[], 404], 'permission denied' => [[], 403],
        'rate limited' => [[], 429], 'transport status' => [[], 502], 'redirect' => [[], 302],
    ]);

    it('accepts repository-name casing confirmed by the metadata endpoint', function (): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews';
        Http::fake([
            $path.'?per_page=100&page=1' => Http::response([GitHubTestSupport::review()], 200, [
                'Link' => '<https://api.github.com/repositories/555001/pulls/7/reviews?per_page=100&page=2>; rel="next"',
            ]),
            'https://api.github.com/repos/acme/widgets' => Http::response(['id' => 555001, 'full_name' => 'Acme/Widgets']),
            $path.'?per_page=100&page=2' => Http::response([array_replace(GitHubTestSupport::review(), ['id' => 102])]),
        ]);

        expect(GitHubTestSupport::readReviews('reviews'))->toHaveCount(2);
        Http::assertSentCount(3);
    });

    it('fails a canonical scan when repository identity lookup loses transport without leaking the token', function (): void {
        Http::preventStrayRequests();
        $header = preg_replace('/per_page=[0-9]+/', 'per_page=100', GitHubTestSupport::pagination('reviews')[0]);
        $attempts = 0;
        Http::fake([
            'https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response([GitHubTestSupport::review()], 200, ['Link' => $header]),
            'https://api.github.com/repos/acme/widgets' => function () use (&$attempts): never {
                $attempts++;
                throw new ConnectionException('review-secret');
            },
        ]);
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');
        try {
            GitHubTestSupport::readReviews('reviews');
            $this->fail('A failed identity lookup must throw.');
        } catch (GitHubApiException $exception) {
            expect($exception->getMessage())->not->toContain('review-secret')
                ->and($exception->getPrevious())->toBeNull()
                ->and(json_encode($exception->getTrace(), JSON_THROW_ON_ERROR))->not->toContain('review-secret');
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArguments);
        }
        expect($attempts)->toBe(1);
        Http::assertSentCount(1);
    });

    it('does not relax endpoint host or query validation for numeric-repository links', function (string $target): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([GitHubTestSupport::comment()], 200, [
            'Link' => '<'.$target.'>; rel="next"',
        ])]);

        expect(fn () => GitHubTestSupport::readReviews('comments'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    })->with([
        'off host' => ['https://evil.example/repositories/555001/pulls/7/reviews/101/comments?per_page=100&page=2'],
        'wrong PR' => ['https://api.github.com/repositories/555001/pulls/8/reviews/101/comments?per_page=100&page=2'],
        'wrong review' => ['https://api.github.com/repositories/555001/pulls/7/reviews/102/comments?per_page=100&page=2'],
        'wrong endpoint' => ['https://api.github.com/repositories/555001/pulls/7/comments?per_page=100&page=2'],
        'wrong page size' => ['https://api.github.com/repositories/555001/pulls/7/reviews/101/comments?per_page=1&page=2'],
        'extra query' => ['https://api.github.com/repositories/555001/pulls/7/reviews/101/comments?per_page=100&page=2&token=steal'],
        'noncanonical ID' => ['https://api.github.com/repositories/0555001/pulls/7/reviews/101/comments?per_page=100&page=2'],
        'port' => ['https://api.github.com:443/repositories/555001/pulls/7/reviews/101/comments?per_page=100&page=2'],
        'fragment' => ['https://api.github.com/repositories/555001/pulls/7/reviews/101/comments?per_page=100&page=2#fragment'],
    ]);

    it('accepts normal first prev next and last links while preserving source order', function (): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews';
        $link = fn (int $page, string $relation): string => '<'.$path.'?page='.$page.'&per_page=100>; rel="'.$relation.'"';
        Http::fake([
            $path.'?per_page=100&page=1' => Http::response([array_replace(GitHubTestSupport::review(), ['id' => 103])], 200, ['Link' => $link(2, 'next').', '.$link(3, 'last')]),
            $path.'?per_page=100&page=2' => Http::response([array_replace(GitHubTestSupport::review(), ['id' => 101])], 200, ['Link' => $link(1, 'first').', '.$link(1, 'prev').', '.$link(3, 'next').', '.$link(3, 'last')]),
            $path.'?per_page=100&page=3' => Http::response([array_replace(GitHubTestSupport::review(), ['id' => 102])], 200, ['Link' => $link(1, 'first').', '.$link(2, 'prev')]),
        ]);

        expect(array_column(GitHubTestSupport::readReviews('reviews'), 'id'))->toBe([103, 101, 102]);
        Http::assertSentCount(3);
    });

    it('rejects repeated IDs within a page', function (): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews?*' => Http::response([GitHubTestSupport::review(), GitHubTestSupport::review()])]);

        expect(fn () => GitHubTestSupport::readReviews('reviews'))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    });

    it('does not return earlier records when a later page loses transport', function (): void {
        Http::preventStrayRequests();
        $path = 'https://api.github.com/repos/acme/widgets/pulls/7/reviews';
        $attempts = 0;
        Http::fake([
            $path.'?per_page=100&page=1' => Http::response([GitHubTestSupport::review()], 200, ['Link' => '<'.$path.'?per_page=100&page=2>; rel="next"']),
            $path.'?per_page=100&page=2' => function () use (&$attempts): never {
                $attempts++;
                throw new ConnectionException('review-secret');
            },
        ]);

        expect(fn () => GitHubTestSupport::readReviews('reviews'))->toThrow(GitHubApiException::class);
        expect($attempts)->toBe(1);
        Http::assertSentCount(1);
    });

    it('accepts a pending record without a submission and an empty body', function (): void {
        Http::preventStrayRequests();
        $review = array_replace(GitHubTestSupport::review(), ['state' => 'PENDING', 'body' => '']);
        unset($review['submitted_at']);
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101' => Http::response($review)]);

        $record = GitHubTestSupport::readReviews('review');

        expect($record->state)->toBe(GitHubReviewState::Pending)
            ->and($record->submittedAt)->toBeNull()
            ->and($record->body)->toBe('');
        Http::assertSentCount(1);
    });

    it('retains modern current multiline locations', function (): void {
        Http::preventStrayRequests();
        $comment = array_replace(GitHubTestSupport::comment(), ['line' => 9, 'start_line' => 4, 'side' => 'LEFT', 'start_side' => 'LEFT']);
        Http::fake(['https://api.github.com/repos/acme/widgets/pulls/7/reviews/101/comments?*' => Http::response([$comment])]);

        $records = GitHubTestSupport::readReviews('comments');

        expect($records[0]->line)->toBe(9)
            ->and($records[0]->startLine)->toBe(4)
            ->and($records[0]->side)->toBe('LEFT')
            ->and($records[0]->startSide)->toBe('LEFT');
        Http::assertSentCount(1);
    });

    it('does not mint an unusable review token on malformed or unavailable responses', function (mixed $body, int $status): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/app/installations/99/access_tokens' => Http::response($body, $status)]);

        expect(fn () => app(GitHubApi::class)->repositoryReviewsToken(
            GitHubTestSupport::credentials(), 99, GitHubRepository::fromOrigin('https://github.com/acme/widgets.git'),
        ))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    })->with([
        'missing token' => [[], 201], 'empty token' => [['token' => ''], 201],
        'wrong token type' => [['token' => 42], 201], 'rate limit' => [[], 429],
        'server error' => [[], 500], 'redirect' => [[], 302],
    ]);

    it('propagates review-token transport failure without falling back', function (): void {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/app/installations/99/access_tokens' => Http::failedConnection()]);

        expect(fn () => app(GitHubApi::class)->repositoryReviewsToken(
            GitHubTestSupport::credentials(), 99, GitHubRepository::fromOrigin('https://github.com/acme/widgets.git'),
        ))->toThrow(GitHubApiException::class);
        Http::assertSentCount(1);
    });
});
