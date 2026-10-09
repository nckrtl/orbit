<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\T3Environment;
use App\Models\T3Pairing;
use App\Models\T3Profile;
use App\Models\T3ProfileBinding;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const T3_LAYER_SERVER = 'http://10.44.0.30:3773';
const T3_LAYER_PHONE = '10.44.0.77';

/** @return array<string, mixed> */
function t3_layer_registration(array $overrides = []): array
{
    return [
        'environment_id' => 'env-beast',
        'label' => 'beast',
        'url' => T3_LAYER_SERVER,
        'pairing_url' => 'http://localhost:3773/pair#token=admin-pairing-token',
        ...$overrides,
    ];
}

/** Fakes a T3 server that answers registration the way T3 Code's environment API does. */
function t3_layer_fake_server(array $extra = [], string $scope = 'orchestration:read orchestration:operate terminal:operate review:write relay:read access:read access:write relay:write'): void
{
    Http::preventStrayRequests();
    Http::fake([
        T3_LAYER_SERVER.'/.well-known/t3/environment' => Http::response([
            'environmentId' => 'env-beast',
            'label' => 'beast',
            'platform' => ['os' => 'linux', 'arch' => 'x64'],
            'serverVersion' => '0.9.1',
            'capabilities' => [],
        ]),
        T3_LAYER_SERVER.'/oauth/token' => Http::response([
            'access_token' => 'admin-session-token',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'token_type' => 'Bearer',
            'expires_in' => 2_592_000,
            'scope' => $scope,
        ]),
        T3_LAYER_SERVER.'/api/auth/clients' => Http::response([]),
        ...$extra,
    ]);
}

function t3_layer_environment(array $attributes = []): T3Environment
{
    return T3Environment::query()->create([
        'environment_id' => 'env-beast',
        'label' => 'beast',
        'url' => T3_LAYER_SERVER,
        'admin_session' => 'admin-session-token',
        'admin_session_expires_at' => Carbon::now()->addDays(30),
        'registered_at' => Carbon::now(),
        ...$attributes,
    ]);
}

function t3_layer_node(string $name, string $address, LifecycleStatus $status = LifecycleStatus::Active): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'ios',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
    ]);
}

beforeEach(function (): void {
    $this->phone = t3_layer_node('phone', T3_LAYER_PHONE);
    $this->laptop = t3_layer_node('laptop', '10.44.0.78');
    $this->withServerVariables(['REMOTE_ADDR' => T3_LAYER_PHONE]);
});

describe('identity', function (): void {
    it('identifies the caller as the active Node that owns its WireGuard address', function (): void {
        $this->getJson('/api/v1/t3/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->phone->id)
            ->assertJsonPath('data.name', 'phone')
            ->assertJsonPath('data.wireguard_ip', T3_LAYER_PHONE)
            ->assertJsonPath('data.profile', null);
    });

    it('refuses a caller that is not an active Node', function (string $address): void {
        t3_layer_node('provisioning-phone', '10.44.0.90', LifecycleStatus::Provisioning);

        $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->getJson('/api/v1/t3/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    })->with(['unknown address' => '10.44.0.91', 'provisioning Node' => '10.44.0.90', 'public address' => '203.0.113.9']);

    it('answers an unknown caller before it resolves a route binding', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/t3/profiles/999/settings')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('needs no node access grant', function (): void {
        expect(Node::query()->find($this->phone->id)?->accessibleNodes()->count())->toBe(0);

        $this->getJson('/api/v1/t3/profiles')->assertOk();
    });
});

describe('profiles', function (): void {
    it('creates and lists profiles that every Node can see', function (): void {
        $this->postJson('/api/v1/t3/profiles', ['name' => 'Nick'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nick')
            ->assertJsonPath('data.settings_version', 1);
        $this->postJson('/api/v1/t3/profiles', ['name' => 'Nick'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson('/api/v1/t3/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nick');
    });

    it('binds the calling Node to one profile and keeps the binding', function (): void {
        $nick = T3Profile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        $work = T3Profile::query()->create(['name' => 'Work', 'settings' => ['workspaces' => []]]);

        $this->putJson('/api/v1/t3/me/profile', ['profile_id' => $nick->id])
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Nick')
            ->assertJsonPath('data.id', $this->phone->id);
        $this->getJson('/api/v1/t3/me')->assertJsonPath('data.profile.id', $nick->id);

        $this->putJson('/api/v1/t3/me/profile', ['profile_id' => $work->id])->assertOk();
        expect(T3ProfileBinding::query()->where('node_id', $this->phone->id)->count())->toBe(1);
        $this->getJson('/api/v1/t3/me')->assertJsonPath('data.profile.name', 'Work');
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson('/api/v1/t3/me')->assertJsonPath('data.profile', null);

        $this->putJson('/api/v1/t3/me/profile', ['profile_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    });
});

describe('profile settings', function (): void {
    $workspace = [
        'id' => 'ws-1',
        'name' => 'Orbit',
        'color' => 'blue',
        'icon' => 'rocket',
        'projectRefs' => ['env-beast:project-1', 'env-mini:project-2'],
        'projectKeys' => ['orbit'],
    ];

    it('replaces the settings document and increases its version', function () use ($workspace): void {
        $profile = T3Profile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        $this->getJson("/api/v1/t3/profiles/{$profile->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.settings', ['workspaces' => []]);

        $this->putJson("/api/v1/t3/profiles/{$profile->id}/settings", [
            'version' => 1,
            'settings' => ['workspaces' => [$workspace, ['id' => 'ws-2', 'name' => 'Home', 'color' => 'red', 'icon' => null, 'image' => 'data:image/png;base64,iVBORw0KGgo=', 'projectKeys' => ['home']]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0', $workspace)
            ->assertJsonPath('data.settings.workspaces.1.image', 'data:image/png;base64,iVBORw0KGgo=')
            ->assertJsonMissingPath('data.settings.workspaces.1.projectRefs');
        expect(Activity::query()->where('command', 't3:profile:settings:update')->sole()->properties?->toArray()['input'])
            ->toBe(['version' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson("/api/v1/t3/profiles/{$profile->id}/settings")
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0.projectRefs', ['env-beast:project-1', 'env-mini:project-2']);
    });

    it('refuses a write from a stale version and keeps the newer document', function () use ($workspace): void {
        $profile = T3Profile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        $this->putJson("/api/v1/t3/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => [$workspace]]])
            ->assertOk();

        $this->putJson("/api/v1/t3/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => []]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 't3.settings_version_conflict')
            ->assertJsonPath('error.details.current_version', 2);

        expect($profile->refresh()->settings['workspaces'])->toHaveCount(1)
            ->and($profile->settings_version)->toBe(2);
    });

    it('refuses settings outside the workspace contract', function (array $settings, string $field): void {
        $profile = T3Profile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        $this->putJson("/api/v1/t3/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => $settings])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors([$field], 'error.details');
    })->with([
        'unknown top-level key' => [['workspaces' => [], 'theme' => 'dark'], 'settings'],
        'project ref without environment' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => ['project-1']]]], 'settings.workspaces.0.projectRefs.0'],
        'unknown workspace key' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => [], 'secret' => 'x']]], 'settings.workspaces.0'],
        'duplicate workspace id' => [['workspaces' => [
            ['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => []],
            ['id' => 'ws-1', 'name' => 'B', 'color' => 'blue', 'icon' => null, 'projectRefs' => []],
        ]], 'settings.workspaces.0.id'],
        'image that is not a picture' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'image' => 'https://example.com/a.png', 'projectRefs' => []]]], 'settings.workspaces.0.image'],
    ]);
});

describe('environment registration', function (): void {
    it('exchanges the admin pairing link for an admin session and stores it encrypted', function (): void {
        t3_layer_fake_server();

        $this->postJson('/api/v1/t3/environments', t3_layer_registration())
            ->assertOk()
            ->assertJsonPath('data.environment_id', 'env-beast')
            ->assertJsonPath('data.url', T3_LAYER_SERVER)
            ->assertJsonPath('data.server_version', '0.9.1')
            ->assertJsonPath('data.registered_by', 'phone')
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonMissingPath('data.admin_session');

        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/oauth/token'
            && $request->isForm()
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $request['subject_token'] === 'admin-pairing-token'
            && $request['subject_token_type'] === 'urn:t3:params:oauth:token-type:environment-bootstrap'
            && $request['requested_token_type'] === 'urn:ietf:params:oauth:token-type:access_token'
            && $request['client_label'] === 'Orbit Gateway');

        $environment = T3Environment::query()->sole();
        $stored = DB::table('t3_environments')->value('admin_session');
        expect($environment->admin_session)->toBe('admin-session-token')
            ->and($stored)->not->toContain('admin-session-token')
            ->and($environment->admin_session_expires_at->isAfter(Carbon::now()->addDays(29)))->toBeTrue()
            ->and(Activity::query()->where('command', 't3:environment:register')->sole()->properties?->toArray()['input'])
            ->toBe(['environment_id' => 'env-beast', 'label' => 'beast', 'url' => T3_LAYER_SERVER])
            ->and(json_encode(Activity::query()->sole()->properties))->not->toContain('admin-pairing-token');

        $this->getJson('/api/v1/t3/environments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'beast');
    });

    it('replaces the session on re-registration and revokes the earlier Gateway sessions', function (): void {
        t3_layer_fake_server([
            T3_LAYER_SERVER.'/api/auth/clients/revoke' => Http::response(['revoked' => true]),
            T3_LAYER_SERVER.'/api/auth/clients' => Http::response([
                ['sessionId' => 'new', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => true],
                ['sessionId' => 'old', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => false],
                ['sessionId' => 'phone', 'client' => ['label' => 'iPhone', 'deviceType' => 'mobile'], 'current' => false],
            ]),
        ]);
        t3_layer_environment(['label' => 'old label', 'admin_session' => 'old-session']);

        $this->postJson('/api/v1/t3/environments', t3_layer_registration())->assertOk()->assertJsonPath('data.label', 'beast');

        expect(T3Environment::query()->count())->toBe(1)
            ->and(T3Environment::query()->sole()->admin_session)->toBe('admin-session-token');
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] === 'old'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));
        Http::assertNotSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/clients/revoke'
            && in_array($request['sessionId'], ['new', 'phone'], true));
    });

    it('refuses a URL that serves another environment before it spends the pairing link', function (): void {
        t3_layer_fake_server();

        $this->postJson('/api/v1/t3/environments', t3_layer_registration(['environment_id' => 'env-other']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 't3.environment_mismatch');

        Http::assertNotSent(static fn (HttpRequest $request): bool => str_ends_with($request->url(), '/oauth/token'));
        expect(T3Environment::query()->count())->toBe(0);
    });

    it('refuses a pairing link that is not an admin link', function (): void {
        t3_layer_fake_server(scope: 'orchestration:read orchestration:operate terminal:operate review:write relay:read');

        $this->postJson('/api/v1/t3/environments', t3_layer_registration())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 't3.admin_scope_missing');
        expect(T3Environment::query()->count())->toBe(0);
    });

    it('reports a pairing link the T3 server rejects', function (): void {
        t3_layer_fake_server([
            T3_LAYER_SERVER.'/oauth/token' => Http::response(['_tag' => 'EnvironmentAuthInvalidError', 'code' => 'auth_invalid', 'reason' => 'invalid_credential', 'traceId' => 't'], 401),
        ]);

        $this->postJson('/api/v1/t3/environments', t3_layer_registration())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 't3.session_rejected')
            ->assertJsonPath('error.details.reason', 'invalid_credential');
    });

    it('refuses a pairing URL without a token', function (): void {
        $this->postJson('/api/v1/t3/environments', t3_layer_registration(['pairing_url' => 'http://localhost:3773/pair']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pairing_url'], 'error.details');
    });
});

describe('pairing', function (): void {
    it('mints a pairing link for the calling Node and records it', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            T3_LAYER_SERVER.'/api/auth/pairing-token' => Http::response([
                'id' => 'link-1',
                'credential' => 'device-pairing-token',
                'label' => 'ignored',
                'expiresAt' => '2026-10-08T12:05:00.000Z',
            ]),
        ]);
        t3_layer_environment();

        $response = $this->postJson('/api/v1/t3/environments/env-beast/pairings')
            ->assertCreated()
            ->assertJsonPath('data.pairing_url', T3_LAYER_SERVER.'/pair#token=device-pairing-token')
            ->assertJsonPath('data.pairing.environment_id', 'env-beast')
            ->assertJsonPath('data.pairing.node_name', 'phone')
            ->assertJsonPath('data.pairing.revoked_at', null);

        $pairing = T3Pairing::query()->sole();
        expect($pairing->pairing_link_id)->toBe('link-1')
            ->and($pairing->client_label)->toStartWith('phone via Orbit ')
            ->and($response->json('data.pairing.client_label'))->toBe($pairing->client_label);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/pairing-token'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token')
            && $request['label'] === $pairing->client_label
            && ! isset($request['scopes']));

        $this->getJson('/api/v1/t3/environments/env-beast/pairings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.pairing_link_id')
            ->assertJsonPath('data.0.client_label', $pairing->client_label);
        expect(Activity::query()->where('command', 't3:pairing:create')->value('caller_ip'))->toBe(T3_LAYER_PHONE);
    });

    it('refuses to mint with an expired admin session', function (): void {
        Http::preventStrayRequests();
        t3_layer_environment(['admin_session_expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/v1/t3/environments/env-beast/pairings')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 't3.session_expired');
        $this->getJson('/api/v1/t3/environments')->assertJsonPath('data.0.status', 'session_expired');
    });

    it('reports an unreachable T3 server', function (): void {
        Http::fake([T3_LAYER_SERVER.'/*' => Http::failedConnection()]);
        t3_layer_environment();

        $this->postJson('/api/v1/t3/environments/env-beast/pairings')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 't3.server_unreachable');
        expect(T3Pairing::query()->count())->toBe(0);
    });

    it('answers 404 for an environment that never registered', function (): void {
        $this->postJson('/api/v1/t3/environments/env-missing/pairings')->assertNotFound();
    });
});

describe('revoke', function (): void {
    it("revokes the Node's sessions and unused links on the server", function (): void {
        Http::preventStrayRequests();
        $environment = t3_layer_environment();
        $phone = $this->phone;
        $laptop = $this->laptop;
        foreach ([[$phone, 'link-1', 'phone via Orbit a'], [$phone, 'link-2', 'phone via Orbit b'], [$laptop, 'link-3', 'laptop via Orbit c']] as [$node, $link, $label]) {
            T3Pairing::query()->create([
                'node_id' => $node->id,
                't3_environment_id' => $environment->id,
                'pairing_link_id' => $link,
                'client_label' => $label,
                'expires_at' => Carbon::now()->addMinutes(5),
            ]);
        }
        Http::fake([
            T3_LAYER_SERVER.'/api/auth/clients/revoke' => Http::response(['revoked' => true]),
            T3_LAYER_SERVER.'/api/auth/pairing-links/revoke' => Http::response(['revoked' => false]),
            T3_LAYER_SERVER.'/api/auth/clients' => Http::response([
                ['sessionId' => 'gateway', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => true],
                ['sessionId' => 'phone-session', 'client' => ['label' => 'phone via Orbit a', 'deviceType' => 'mobile'], 'current' => false],
                ['sessionId' => 'laptop-session', 'client' => ['label' => 'laptop via Orbit c', 'deviceType' => 'desktop'], 'current' => false],
            ]),
        ]);

        $this->postJson('/api/v1/t3/environments/env-beast/pairings/revoke')
            ->assertOk()
            ->assertJsonPath('data.node_id', $phone->id)
            ->assertJsonPath('data.revoked_sessions', 1)
            ->assertJsonCount(2, 'data.pairings');

        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] === 'phone-session'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));
        Http::assertNotSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] !== 'phone-session');
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/pairing-links/revoke' && $request['id'] === 'link-2');
        expect(T3Pairing::query()->whereNotNull('revoked_at')->pluck('pairing_link_id')->all())->toBe(['link-1', 'link-2']);

        $this->postJson('/api/v1/t3/environments/env-beast/pairings/revoke', ['node_id' => $laptop->id])
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 1);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === T3_LAYER_SERVER.'/api/auth/clients/revoke' && $request['sessionId'] === 'laptop-session');
    });

    it('calls nothing when the Node holds no pairing on the server', function (): void {
        Http::preventStrayRequests();
        t3_layer_environment();

        $this->postJson('/api/v1/t3/environments/env-beast/pairings/revoke')
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 0)
            ->assertJsonPath('data.pairings', []);
        Http::assertNothingSent();
    });
});
