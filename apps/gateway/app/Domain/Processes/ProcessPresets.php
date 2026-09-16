<?php

declare(strict_types=1);

namespace App\Domain\Processes;

final readonly class ProcessPresets
{
    /** @return list<string> */
    public static function names(): array
    {
        return [
            VpDevPreset::NAME,
            AgentationMcpPreset::NAME,
            AntigravityWatchPreset::NAME,
        ];
    }

    public static function isKnown(string $preset): bool
    {
        return in_array($preset, self::names(), true);
    }

    /** @return list<string> */
    public static function command(string $preset): array
    {
        return match ($preset) {
            VpDevPreset::NAME => VpDevPreset::command(),
            AgentationMcpPreset::NAME => AgentationMcpPreset::command(),
            AntigravityWatchPreset::NAME => AntigravityWatchPreset::command(),
            default => throw new \InvalidArgumentException('Unsupported Process preset.'),
        };
    }

    public static function restartPolicy(string $preset): string
    {
        return $preset === AntigravityWatchPreset::NAME ? 'always' : 'on-failure';
    }

    public static function refusesKeepAlive(string $preset): bool
    {
        return in_array($preset, [AgentationMcpPreset::NAME, AntigravityWatchPreset::NAME], true);
    }
}
