<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class DirtyOverlay
{
    /** @param list<string> $paths */
    public function __construct(
        public array $paths,
        public string $treeHash,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $treeHash) !== 1 || count($paths) !== count(array_unique($paths))) {
            throw new InvalidArgumentException('The dirty overlay is invalid.');
        }
        foreach ($paths as $path) {
            if (
                $path === ''
                || str_starts_with($path, '/')
                || str_contains($path, "\0")
                || str_contains($path, '//')
                || preg_match('/[\r\n]/', $path) === 1
                || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path) === 1
            ) {
                throw new InvalidArgumentException('The dirty overlay contains an unsafe path.');
            }
        }
    }

    /** @return array{paths:list<string>,tree_hash:string} */
    public function toArray(): array
    {
        return ['paths' => $this->paths, 'tree_hash' => $this->treeHash];
    }
}
