<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Str;

final readonly class NativeWebSocketCredentialManager implements WebSocketCredentialManager
{
    private const int SecretLength = 40;

    private const int IdentifierLength = 20;

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function ensure(Node $node): WebSocketCredentials
    {
        $scope = $this->scope($node);

        $appId = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppId);
        $appKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKey);
        $appSecret = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppSecret);
        $laravelAppKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKeyLaravel);

        $appId ??= $this->generate($scope, WebSocketFootprint::SettingKeyAppId, self::IdentifierLength, SettingValueProtection::Plain);
        $appKey ??= $this->generate($scope, WebSocketFootprint::SettingKeyAppKey, self::IdentifierLength, SettingValueProtection::Plain);
        $appSecret ??= $this->generate($scope, WebSocketFootprint::SettingKeyAppSecret, self::SecretLength, SettingValueProtection::Secret);
        $laravelAppKey ??= $this->generateLaravelAppKey($scope);

        return new WebSocketCredentials($appId, $appKey, $appSecret, $laravelAppKey);
    }

    public function current(): ?WebSocketCredentials
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::WebSocket->value)
            ->where('status', LifecycleStatus::Active->value)
            ->with('node')
            ->limit(2)
            ->get();

        if ($assignments->count() !== 1) {
            return null;
        }

        $node = $assignments->sole()->node;

        if ($node->status !== LifecycleStatus::Active) {
            return null;
        }

        $scope = $this->scope($node);
        $appId = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppId);
        $appKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKey);
        $appSecret = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppSecret);
        $laravelAppKey = $this->settings->get($scope, WebSocketFootprint::SettingKeyAppKeyLaravel);

        if (
            ! is_string($appId) || $appId === ''
            || ! is_string($appKey) || $appKey === ''
            || ! is_string($appSecret) || $appSecret === ''
            || ! is_string($laravelAppKey) || $laravelAppKey === ''
        ) {
            return null;
        }

        return new WebSocketCredentials(
            $appId,
            $appKey,
            $appSecret,
            $laravelAppKey,
            is_string($node->wireguard_ip) && $node->wireguard_ip !== '' ? $node->wireguard_ip : null,
        );
    }

    public function purge(Node $node): void
    {
        $scope = $this->scope($node);

        foreach ([
            WebSocketFootprint::SettingKeyAppId,
            WebSocketFootprint::SettingKeyAppKey,
            WebSocketFootprint::SettingKeyAppSecret,
            WebSocketFootprint::SettingKeyAppKeyLaravel,
        ] as $key) {
            $this->settings->delete($scope, $key);
        }
    }

    private function generate(SettingScope $scope, string $key, int $length, SettingValueProtection $protection): string
    {
        $value = Str::random($length);
        $this->settings->put($scope, $key, $value, $protection);

        return $value;
    }

    private function generateLaravelAppKey(SettingScope $scope): string
    {
        $value = 'base64:'.base64_encode(random_bytes(32));
        $this->settings->put($scope, WebSocketFootprint::SettingKeyAppKeyLaravel, $value, SettingValueProtection::Secret);

        return $value;
    }

    private function scope(Node $node): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $node->id);
    }
}
