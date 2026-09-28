<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use InvalidArgumentException;

final readonly class ExtensionStore
{
    /** @var list<string> */
    public const array Extensions = ['tasks', 'proxycli'];

    public function __construct(private SettingRepository $settings) {}

    public function enabled(string $extension): bool
    {
        $this->assertKnown($extension);

        return $this->settings->get($this->scope(), "extension.{$extension}.enabled") === '1';
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return array_combine(self::Extensions, array_map($this->enabled(...), self::Extensions));
    }

    public function set(string $extension, bool $enabled): void
    {
        $this->assertKnown($extension);
        $key = "extension.{$extension}.enabled";
        if ($enabled) {
            $this->settings->put($this->scope(), $key, '1');
        } else {
            $this->settings->delete($this->scope(), $key);
        }
    }

    private function assertKnown(string $extension): void
    {
        if (! in_array($extension, self::Extensions, true)) {
            throw new InvalidArgumentException("Unknown extension [{$extension}].");
        }
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
