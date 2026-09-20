<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Models\Node;
use Illuminate\Support\Str;

final readonly class NativeAnalyticsSecretManager implements AnalyticsSecretManager
{
    public const string SettingKeySecretKeyBase = 'analytics.secret_key_base';

    /** Plausible refuses a shorter `SECRET_KEY_BASE`. */
    private const int Length = 64;

    public function __construct(private SettingRepository $settings) {}

    public function secretKeyBase(Node $node): string
    {
        $scope = $this->scope($node);
        $stored = $this->settings->get($scope, self::SettingKeySecretKeyBase);

        if (is_string($stored) && strlen($stored) >= self::Length) {
            return $stored;
        }

        $generated = Str::random(self::Length);
        $this->settings->put($scope, self::SettingKeySecretKeyBase, $generated, SettingValueProtection::Secret);

        return $generated;
    }

    public function purge(Node $node): void
    {
        $this->settings->delete($this->scope($node), self::SettingKeySecretKeyBase);
    }

    private function scope(Node $node): SettingScope
    {
        return new SettingScope(SettingScopeType::Node, $node->id);
    }
}
