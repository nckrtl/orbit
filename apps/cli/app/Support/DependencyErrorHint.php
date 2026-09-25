<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed operator guidance for dependency error codes; never derived from Gateway text. */
final readonly class DependencyErrorHint
{
    private const array HINTS = [
        'dependencies.stale_npm_lockfile' => 'The npm lockfile does not match package.json. Run npm install in the project root, then commit the updated lockfile.',
        'dependencies.stale_pnpm_lockfile' => 'pnpm-lock.yaml does not match package.json. Run pnpm install in the project root, then commit the updated lockfile.',
        'dependencies.stale_bun_lockfile' => 'bun.lock does not match package.json. Run bun install in the project root, then commit the updated lockfile.',
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
