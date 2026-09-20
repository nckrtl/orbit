<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Owns the Gateway's GitHub App settings: the App's identity and private key, and the pending
 * registration that a browser redirect from GitHub must match
 * ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
 */
final readonly class GitHubAppStore
{
    private const string APP = 'github.app';

    private const string PRIVATE_KEY = 'github.app.private_key';

    private const string REGISTRATION = 'github.app.registration';

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function credentials(): ?GitHubAppCredentials
    {
        $app = $this->decode($this->settings->get($this->scope(), self::APP));
        $privateKey = $this->settings->get($this->scope(), self::PRIVATE_KEY);

        if ($app === null || $privateKey === null) {
            return null;
        }

        $appId = $app['app_id'] ?? null;
        $slug = $app['slug'] ?? null;
        $name = $app['name'] ?? null;
        $owner = $app['owner'] ?? null;
        $ownerType = $app['owner_type'] ?? null;
        $url = $app['url'] ?? null;

        if (! is_int($appId) || ! is_string($slug) || ! is_string($name) || ! is_string($owner) || ! is_string($ownerType) || ! is_string($url)) {
            return null;
        }

        return new GitHubAppCredentials($appId, $slug, $name, $owner, $ownerType, $url, $privateKey);
    }

    public function put(GitHubAppCredentials $credentials): void
    {
        DB::transaction(function () use ($credentials): void {
            $this->settings->put($this->scope(), self::APP, json_encode([
                'app_id' => $credentials->appId,
                'slug' => $credentials->slug,
                'name' => $credentials->name,
                'owner' => $credentials->owner,
                'owner_type' => $credentials->ownerType,
                'url' => $credentials->url,
            ], JSON_THROW_ON_ERROR));
            $this->settings->put(
                $this->scope(),
                self::PRIVATE_KEY,
                $credentials->privateKey,
                SettingValueProtection::Secret,
            );
            $this->settings->delete($this->scope(), self::REGISTRATION);
        });
    }

    public function delete(): void
    {
        DB::transaction(function (): void {
            $this->settings->delete($this->scope(), self::APP);
            $this->settings->delete($this->scope(), self::PRIVATE_KEY);
            $this->settings->delete($this->scope(), self::REGISTRATION);
        });
    }

    public function beginRegistration(GitHubAppRegistration $registration): void
    {
        $this->settings->put(
            $this->scope(),
            self::REGISTRATION,
            json_encode($registration->toArray(), JSON_THROW_ON_ERROR),
            SettingValueProtection::Secret,
        );
    }

    public function registration(): ?GitHubAppRegistration
    {
        $stored = $this->decode($this->settings->get($this->scope(), self::REGISTRATION));

        return $stored === null ? null : GitHubAppRegistration::fromArray($stored);
    }

    /** @return array<array-key, mixed>|null */
    private function decode(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
