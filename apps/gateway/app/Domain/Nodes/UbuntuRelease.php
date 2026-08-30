<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

enum UbuntuRelease: string
{
    case Resolute = 'resolute';

    /** @return non-empty-list<self> */
    public static function baseReleases(): array
    {
        return [self::Resolute];
    }

    /** @return non-empty-list<self> */
    public static function forRole(RoleName $role): array
    {
        return $role === RoleName::AppDev ? self::baseReleases() : [self::Resolute];
    }

    /** @return non-empty-list<string> */
    public static function supportedCodenames(): array
    {
        return array_map(static fn (self $release): string => $release->value, self::baseReleases());
    }

    public static function unsupportedText(?string $id = null, ?string $codename = null): string
    {
        $id = self::safeIdentifier($id);
        $codename = self::safeIdentifier($codename);

        if ($id === null || $codename === null) {
            return 'Node operating system [unknown/unknown] is not supported.';
        }

        return "Node operating system [{$id}/{$codename}] is not supported.";
    }

    public static function unsupportedTextFromOutput(string $output): string
    {
        $matches = [];

        if (! preg_match(
            '/\ANode operating system \[([A-Za-z0-9._-]+)\/([A-Za-z0-9._-]+)\] is not supported\.\R?\z/',
            $output,
            $matches,
        )) {
            return self::unsupportedText();
        }

        return self::unsupportedText($matches[1], $matches[2]);
    }

    private static function safeIdentifier(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9._-]+\z/', $value)) {
            return null;
        }

        return $value;
    }
}
