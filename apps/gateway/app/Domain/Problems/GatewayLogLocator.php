<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use RuntimeException;

final readonly class GatewayLogFile
{
    public function __construct(
        public string $path,
        public int $inode,
        public int $size,
    ) {}
}

/**
 * The Gateway log the collector tails: laravel.log when that path is a regular file,
 * otherwise the newest laravel-*.log.
 */
final readonly class GatewayLogLocator
{
    public function current(): ?GatewayLogFile
    {
        $single = storage_path('logs/laravel.log');

        if (is_file($single)) {
            return $this->describe($single);
        }

        $matches = glob(storage_path('logs'.DIRECTORY_SEPARATOR.'laravel-*.log'));
        $files = is_array($matches) ? array_values(array_filter($matches, is_file(...))) : [];

        if ($files === []) {
            return null;
        }

        usort($files, static function (string $left, string $right): int {
            $byTime = filemtime($left) <=> filemtime($right);

            return $byTime !== 0 ? $byTime : strcmp($left, $right);
        });

        return $this->describe(array_last($files));
    }

    public function matching(string $path, int $inode): ?GatewayLogFile
    {
        if (! is_file($path)) {
            return null;
        }

        $file = $this->describe($path);

        return $file->inode === $inode ? $file : null;
    }

    public function findInode(int $inode): ?GatewayLogFile
    {
        $matches = glob(storage_path('logs'.DIRECTORY_SEPARATOR.'laravel*.log'));

        if (! is_array($matches)) {
            return null;
        }

        foreach ($matches as $path) {
            if (! is_file($path)) {
                continue;
            }

            $file = $this->describe($path);

            if ($file->inode === $inode) {
                return $file;
            }
        }

        return null;
    }

    public function describe(string $path): GatewayLogFile
    {
        clearstatcache(true, $path);
        $inode = fileinode($path);
        $size = filesize($path);

        if ($inode === false || $size === false) {
            throw new RuntimeException('The log file could not be read.');
        }

        return new GatewayLogFile($path, $inode, $size);
    }
}
