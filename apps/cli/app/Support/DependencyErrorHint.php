<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed operator guidance for dependency error codes; never derived from Gateway text. */
final readonly class DependencyErrorHint
{
    private const array HINTS = [
        'dependencies.stale_npm_lockfile' => 'Run npm install in the project root and commit the lockfile.',
        'dependencies.stale_pnpm_lockfile' => 'Run pnpm install in the project root and commit pnpm-lock.yaml.',
        'dependencies.stale_bun_lockfile' => 'Run bun install in the project root and commit bun.lock.',
    ];

    public static function for(?string $errorCode): ?string
    {
        return $errorCode === null ? null : (self::HINTS[$errorCode] ?? null);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function withHint(array $fields, ?string $errorCode): array
    {
        $hint = self::for($errorCode);

        return $hint === null ? $fields : [...$fields, 'Fix' => $hint];
    }
}
