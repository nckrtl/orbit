<?php

declare(strict_types=1);

namespace App\Domain\Instances\Copy;

use SensitiveParameter;

/**
 * Replaces a source checkout path or route domain inside a copied value.
 *
 * A path matches when the next byte does not continue the same path segment.
 * A segment continues through letters, digits, `.`, `_`, and `-`. `/` starts
 * another segment and still matches. A domain matches only as a whole host.
 */
final readonly class InstanceCopyReferenceRewriter
{
    private const string PATH_CONTINUATION = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';

    private const string HOST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-';

    public function rewrite(
        #[SensitiveParameter]
        string $value,
        string $sourceCheckout,
        string $targetCheckout,
        string $sourceDomain,
        string $targetDomain,
    ): string {
        if ($sourceCheckout !== '' && $sourceCheckout !== $targetCheckout) {
            $value = $this->replacePath($value, $sourceCheckout, $targetCheckout);
        }

        if ($sourceDomain !== '' && $targetDomain !== '' && $sourceDomain !== $targetDomain) {
            $value = $this->replaceDomain($value, $sourceDomain, $targetDomain);
        }

        return $value;
    }

    public function isInsideCheckout(string $path, string $checkout): bool
    {
        if ($checkout === '' || ! str_starts_with($path, $checkout)) {
            return false;
        }

        return strlen($path) === strlen($checkout) || $path[strlen($checkout)] === '/';
    }

    private function replacePath(string $value, string $source, string $target): string
    {
        if ($source === '') {
            return $value;
        }

        $result = '';
        $offset = 0;
        $sourceLength = strlen($source);
        $valueLength = strlen($value);

        while (true) {
            $position = strpos($value, $source, $offset);

            if ($position === false) {
                return $result.substr($value, $offset);
            }

            $next = $position + $sourceLength;

            if ($next < $valueLength && str_contains(self::PATH_CONTINUATION, $value[$next])) {
                $result .= substr($value, $offset, $position - $offset + 1);
                $offset = $position + 1;

                continue;
            }

            $result .= substr($value, $offset, $position - $offset).$target;
            $offset = $next;
        }
    }

    private function replaceDomain(string $value, string $source, string $target): string
    {
        if ($source === '') {
            return $value;
        }

        $result = '';
        $offset = 0;
        $sourceLength = strlen($source);
        $valueLength = strlen($value);

        while (true) {
            $position = strpos($value, $source, $offset);

            if ($position === false) {
                return $result.substr($value, $offset);
            }

            $next = $position + $sourceLength;
            $beforeOk = $position === 0 || ! str_contains(self::HOST, $value[$position - 1]);
            $afterOk = $next === $valueLength || ! str_contains(self::HOST, $value[$next]);

            if (! $beforeOk || ! $afterOk) {
                $result .= substr($value, $offset, $position - $offset + 1);
                $offset = $position + 1;

                continue;
            }

            $result .= substr($value, $offset, $position - $offset).$target;
            $offset = $next;
        }
    }
}
