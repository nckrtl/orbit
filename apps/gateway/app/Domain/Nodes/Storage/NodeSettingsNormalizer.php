<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\AppsSettingsData;
use App\Data\Nodes\NodeSettingsData;

/** @mago-expect lint:cyclomatic-complexity Normalization preserves two legacy keys while exposing one typed apps setting. */
final readonly class NodeSettingsNormalizer
{
    public function normalize(?NodeSettingsData $settings): ?NodeSettingsData
    {
        if (! $settings instanceof NodeSettingsData) {
            return null;
        }

        $normalized = new NodeSettingsData(
            apps: $this->nested($settings->appsPath()),
        );

        return $normalized->isEmpty() ? null : $normalized;
    }

    /** @return array<string, mixed>|null */
    public function stored(?NodeSettingsData $settings, mixed $existing = null): ?array
    {
        $normalized = $this->normalize($settings);
        $payload = $this->legacyPayload($existing);

        if ($normalized instanceof NodeSettingsData) {
            $payload['apps'] = ['path' => $normalized->appsPath()];
        }

        return $payload === [] ? null : $payload;
    }

    public function fromStored(mixed $value): ?NodeSettingsData
    {
        if (! is_array($value)) {
            return null;
        }

        return $this->normalize(new NodeSettingsData(apps: $this->nestedFromStored($value['apps'] ?? null)));
    }

    public function legacyFromStored(mixed $value): ?LegacyNodeSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $settings = new LegacyNodeSettings(
            instancePath: $this->pathFromStored($value['instance'] ?? null),
            worktreePath: $this->pathFromStored($value['worktree'] ?? null),
        );

        return $settings->isEmpty() ? null : $settings;
    }

    private function nested(?string $path): ?AppsSettingsData
    {
        return $path === null ? null : new AppsSettingsData($path);
    }

    private function nestedFromStored(mixed $value): ?AppsSettingsData
    {
        $path = $this->pathFromStored($value);

        return $path === null ? null : new AppsSettingsData($path);
    }

    private function pathFromStored(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return is_string($path) ? $path : null;
    }

    /** @return array<string, mixed> */
    private function legacyPayload(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $payload = [];

        foreach (['instance', 'worktree'] as $key) {
            if (array_key_exists($key, $value)) {
                $payload[$key] = $value[$key];
            }
        }

        return $payload;
    }
}
