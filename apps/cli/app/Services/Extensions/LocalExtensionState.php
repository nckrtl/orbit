<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigLock;
use App\Support\EffectiveUser;
use JsonException;

final readonly class LocalExtensionState
{
    /** @var list<string> */
    private const array Extensions = ['proxycli'];

    public function __construct(private string $path) {}

    /** @return list<string> */
    public function extensions(): array
    {
        return self::Extensions;
    }

    public function known(string $extension): bool
    {
        return in_array($extension, self::Extensions, true);
    }

    public function enabled(string $extension): bool
    {
        $this->assertKnown($extension);

        return in_array($extension, $this->read(), true);
    }

    public function enable(string $extension): void
    {
        $this->assertKnown($extension);
        $this->mutate(static function (array $enabled) use ($extension): array {
            $enabled[] = $extension;

            return $enabled;
        });
    }

    public function disable(string $extension): void
    {
        $this->assertKnown($extension);
        $this->mutate(static fn (array $enabled): array => array_values(array_diff($enabled, [$extension])));
    }

    /**
     * Reads the enabled extensions. A slug this CLI does not know is ignored, and the next write drops it.
     *
     * @return list<string>
     */
    private function read(): array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $metadata = lstat($this->path);
        } finally {
            restore_error_handler();
        }

        if ($metadata === false) {
            return [];
        }

        if (($metadata['mode'] & 0o170_000) !== 0o100_000) {
            throw $this->privacyFailure();
        }

        new GatewayConfigLock($this->path)->ensurePrivateDirectory();
        $contents = $this->readPrivateContents();

        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GatewayConfigException('Orbit extension configuration is invalid.', previous: $exception);
        }

        if (! is_array($decoded) || array_keys($decoded) !== ['enabled']) {
            throw new GatewayConfigException('Orbit extension configuration is invalid.');
        }

        $enabled = is_array($decoded['enabled']) ? $decoded['enabled'] : null;

        if ($enabled === null || array_any($enabled, static fn (mixed $item): bool => ! is_string($item))) {
            throw new GatewayConfigException('Orbit extension configuration is invalid.');
        }

        return array_values(array_unique(array_filter($enabled, $this->known(...))));
    }

    private function readPrivateContents(): string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($this->path, 'rb');
        } finally {
            restore_error_handler();
        }

        if (! is_resource($handle)) {
            throw $this->privacyFailure();
        }

        clearstatcache(true, $this->path);
        set_error_handler(static fn (): bool => true);

        try {
            $pathStat = lstat($this->path);
        } finally {
            restore_error_handler();
        }

        $handleStat = fstat($handle);
        $effectiveUserId = EffectiveUser::id();

        if (
            is_link($this->path)
            || ! is_array($pathStat)
            || ! is_array($handleStat)
            || ($handleStat['mode'] & 0o170_000) !== 0o100_000
            || ($handleStat['mode'] & 0o077) !== 0
            || ! is_int($effectiveUserId)
            || $handleStat['uid'] !== $effectiveUserId
            || $pathStat['dev'] !== $handleStat['dev']
            || $pathStat['ino'] !== $handleStat['ino']
        ) {
            fclose($handle);

            throw $this->privacyFailure();
        }

        $contents = stream_get_contents($handle, 65_537);
        fclose($handle);

        if (! is_string($contents) || strlen($contents) > 65_536) {
            throw new GatewayConfigException('Orbit extension configuration is invalid.');
        }

        return $contents;
    }

    /** @param callable(list<string>): list<string> $operation */
    private function mutate(callable $operation): void
    {
        new GatewayConfigLock($this->path)->synchronized(function () use ($operation): void {
            $enabled = $operation($this->read());
            sort($enabled);
            $temporary = $this->path.'.tmp.'.bin2hex(random_bytes(8));

            try {
                $contents = json_encode(['enabled' => array_values(array_unique($enabled))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

                if (@file_put_contents($temporary, $contents, LOCK_EX) === false
                    || ! @chmod($temporary, 0600)
                    || ! @rename($temporary, $this->path)) {
                    throw new GatewayConfigException('Could not update Orbit extension configuration.');
                }
            } catch (JsonException $exception) {
                throw new GatewayConfigException('Could not update Orbit extension configuration.', previous: $exception);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        });
    }

    private function assertKnown(string $extension): void
    {
        if (! $this->known($extension)) {
            throw new GatewayConfigException("Unknown Orbit extension [{$extension}].");
        }
    }

    private function privacyFailure(): GatewayConfigException
    {
        return new GatewayConfigException(
            'Orbit extension configuration is not private.',
            errorCode: GatewayConfigException::CONFIG_NOT_PRIVATE,
        );
    }
}
