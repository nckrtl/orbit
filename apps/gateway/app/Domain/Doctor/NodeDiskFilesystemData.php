<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class NodeDiskFilesystemData
{
    public function __construct(
        public string $location,
        public int $availableKiB,
        public int $sizeKiB,
        /** Null when the filesystem does not report inode totals (for example, Btrfs). */
        public ?int $freeInodes,
        public ?int $totalInodes,
    ) {}
}
