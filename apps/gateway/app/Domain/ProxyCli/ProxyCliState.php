<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;

final readonly class ProxyCliState
{
    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->get($this->scope(), ProxyCliSettings::Enabled) === '1';
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new ResourceOperationException(
                'proxycli.disabled',
                'The proxycli extension is disabled.',
                409,
            );
        }
    }

    public function nodeId(): ?int
    {
        $value = $this->settings->get($this->scope(), ProxyCliSettings::NodeId);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    public function cacheConnection(): ?string
    {
        return $this->settings->get($this->scope(), ProxyCliSettings::CacheConnection);
    }

    public function cliproxyUrl(): ?string
    {
        return $this->settings->get($this->scope(), ProxyCliSettings::CliproxyUrl);
    }

    public function cliproxyManagementKey(): ?string
    {
        return $this->settings->get($this->scope(), ProxyCliSettings::CliproxyManagementKey);
    }

    public function readToken(): ?string
    {
        return $this->settings->get($this->scope(), ProxyCliSettings::ReadToken);
    }

    public function controlToken(): ?string
    {
        return $this->settings->get($this->scope(), ProxyCliSettings::ControlToken);
    }

    public function processPort(): int
    {
        $value = $this->settings->get($this->scope(), ProxyCliSettings::ProcessPort);

        return is_string($value) && ctype_digit($value) ? (int) $value : 8787;
    }

    public function enable(
        int $nodeId,
        string $cacheConnection,
        string $cliproxyUrl,
        string $managementKey,
        string $readToken,
        string $controlToken,
        int $port = 8787,
    ): void {
        $scope = $this->scope();
        $this->settings->put($scope, ProxyCliSettings::Enabled, '1');
        $this->settings->put($scope, ProxyCliSettings::NodeId, (string) $nodeId);
        $this->settings->put($scope, ProxyCliSettings::CacheConnection, $cacheConnection);
        $this->settings->put($scope, ProxyCliSettings::CliproxyUrl, $cliproxyUrl);
        $this->settings->put($scope, ProxyCliSettings::CliproxyManagementKey, $managementKey, SettingValueProtection::Secret);
        $this->settings->put($scope, ProxyCliSettings::ReadToken, $readToken, SettingValueProtection::Secret);
        $this->settings->put($scope, ProxyCliSettings::ControlToken, $controlToken, SettingValueProtection::Secret);
        $this->settings->put($scope, ProxyCliSettings::ProcessPort, (string) $port);
    }

    public function disable(): void
    {
        $scope = $this->scope();
        $this->settings->put($scope, ProxyCliSettings::Enabled, '0');
        $this->settings->delete($scope, ProxyCliSettings::CliproxyManagementKey);
        $this->settings->delete($scope, ProxyCliSettings::ReadToken);
        $this->settings->delete($scope, ProxyCliSettings::ControlToken);
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
