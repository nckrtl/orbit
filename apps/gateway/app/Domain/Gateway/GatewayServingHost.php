<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

final readonly class GatewayServingHost
{
    public const string SettingKey = 'gateway.serving_node_id';

    public function __construct(
        private SettingRepository $settings,
    ) {}

    public function remember(Node $node): void
    {
        $this->settings->put($this->scope(), self::SettingKey, (string) $node->id);
    }

    public function nodeId(): ?int
    {
        $value = $this->settings->get($this->scope(), self::SettingKey);

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    public function is(Node $node): bool
    {
        return $node->status === LifecycleStatus::Active && $this->nodeId() === $node->id;
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
