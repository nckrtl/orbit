<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

enum UbuntuRelease: string
{
    case Noble = 'noble';
    case Resolute = 'resolute';

    public function label(): string
    {
        return match ($this) {
            self::Noble => 'Ubuntu 24.04 Noble',
            self::Resolute => 'Ubuntu 26.04 Resolute',
        };
    }

    /** @return non-empty-list<self> */
    public static function baseReleases(): array
    {
        return [self::Noble, self::Resolute];
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

    public static function requirementText(): string
    {
        return self::requirementTextFor(self::baseReleases());
    }

    /** @param non-empty-list<self> $releases */
    public static function requirementTextFor(array $releases): string
    {
        $orderedReleases = array_values(array_filter(
            self::baseReleases(),
            static fn (self $release): bool => in_array(needle: $release, haystack: $releases, strict: true),
        ));

        return (
            'Orbit requires '
            .implode(' or ', array_map(
                static fn (self $release): string => $release->label(),
                $orderedReleases,
            ))
            .'.'
        );
    }
}
