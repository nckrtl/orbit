<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\Processes\CommandResult;
use Closure;
use JsonException;
use stdClass;

final readonly class ComposerInstalledInventoryParser
{
    private const int MAX_VERSION_LENGTH = 255;

    /**
     * @param  Closure(string): bool  $validatePackage
     */
    public function parse(CommandResult $result, Closure $validatePackage): ComposerInstalledInventory
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($result->stdout, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Composer installed version probe returned malformed output.',
                result: $result,
                previous: $exception,
            );
        }

        if ($decoded === []) {
            return new ComposerInstalledInventory([], $result);
        }

        if (
            ! $decoded instanceof stdClass
            || ! property_exists($decoded, 'installed')
            || ! is_array($decoded->installed)
        ) {
            throw $this->malformed($result);
        }

        /** @var array<string, list<string>> $versionsByPackage */
        $versionsByPackage = [];

        /** @var mixed $entry */
        foreach ($decoded->installed as $entry) {
            if (! $entry instanceof stdClass) {
                throw $this->malformed($result);
            }

            /** @var mixed $name */
            $name = $entry->name ?? null;
            /** @var mixed $version */
            $version = $entry->version ?? null;

            if (
                ! is_string($name)
                || ! is_string($version)
                || ! $validatePackage($name)
                || ! $this->isSafePackageVersion($version)
            ) {
                throw $this->malformed($result);
            }

            $versionsByPackage[$name][] = $version;
        }

        return new ComposerInstalledInventory($versionsByPackage, $result);
    }

    private function isSafePackageVersion(string $version): bool
    {
        return
            $version !== ''
            && strlen($version) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1
            && preg_match('/\s/', $version) !== 1;
    }

    private function malformed(CommandResult $result): ToolManagerException
    {
        return new ToolManagerException(
            step: 'installed-version',
            message: 'The Composer installed version probe returned malformed output.',
            result: $result,
        );
    }
}
