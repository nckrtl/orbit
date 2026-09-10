<?php

declare(strict_types=1);

namespace App\E2E;

use InvalidArgumentException;
use RuntimeException;

final class DeliveryFlow
{
    public static function forWorktree(string $worktree): string
    {
        $path = $worktree.'/.loop/flow.json';
        if (! file_exists($path) && ! is_link($path)) {
            return 'discovery';
        }
        if (! is_file($path) || is_link($path) || is_link(dirname($path))) {
            throw new InvalidArgumentException('Flow selection must be a regular .loop/flow.json file.');
        }
        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true);
        if (
            ! is_array($data)
            || ($data['schema'] ?? null) !== 1
            || ! in_array($data['flow'] ?? null, ['discovery', 'proof'], true)
        ) {
            throw new InvalidArgumentException('Invalid .loop/flow.json; select discovery or proof explicitly.');
        }

        return $data['flow'];
    }

    public static function requireProof(string $worktree): void
    {
        if (self::forWorktree($worktree) !== 'proof') {
            throw new RuntimeException(
                'The discovery flow disables proof, equivalence, and candidate convergence. '
                .'Use bin/loop-flow select --flow=proof to switch explicitly.',
            );
        }
    }
}
