<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
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

    it('refuses an App name or owner that GitHub cannot accept', function (array $body): void {
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

        expect($page->getContent())
            ->toContain('action="https://github.com/organizations/acme/settings/apps/new?state='.$state.'"')
            ->toContain('&quot;redirect_url&quot;:&quot;https://gateway.orbit/api/v1/github/app/callback&quot;')
            ->toContain('&quot;public&quot;:true')
            ->toContain('&quot;default_permissions&quot;:{&quot;contents&quot;:&quot;read&quot;,&quot;metadata&quot;:&quot;read&quot;}')
            ->not->toContain('hook_attributes');

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

    it('opens the install page and lists current accounts when the App exists', function (): void {
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

    it('shows the App and its installations without the private key', function (): void {
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
