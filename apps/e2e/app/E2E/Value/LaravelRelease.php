<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class LaravelRelease
{
    public function __construct(
        public string $tag,
        public string $commit,
    ) {
        if (preg_match('/\Av\d+\.\d+\.\d+\z/D', $tag) !== 1) {
            throw new InvalidArgumentException('The Laravel release tag is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('The Laravel commit must be a lowercase full SHA.');
        }
    }
}
