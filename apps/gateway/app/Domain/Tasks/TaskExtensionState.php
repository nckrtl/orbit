<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;

final readonly class TaskExtensionState
{
    public const string Key = 'tasks.enabled';

    public function __construct(private SettingRepository $settings) {}

    public function enabled(): bool
    {
        return $this->settings->get($this->scope(), self::Key) === '1';
    }

    public function enable(): void
    {
        $this->settings->put($this->scope(), self::Key, '1');
    }

    public function disable(): void
    {
        $this->settings->delete($this->scope(), self::Key);
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
