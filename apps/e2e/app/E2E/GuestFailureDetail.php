<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\SecretRedactor;

/**
 * The stderr detail on a guest script failure.
 *
 * Redact first, then replace bytes that are not valid UTF-8, then keep the
 * last 20 lines and the last 2000 characters. Scrubbing before the line split
 * keeps the tail when stderr holds an invalid byte; otherwise the Unicode
 * split fails and the tail disappears. The scrubbed tail still JSON-encodes.
 */
final readonly class GuestFailureDetail
{
    public function append(string $message, string $stderr): string
    {
        $tail = $this->tail($stderr);

        return $tail === '' ? $message : $message."\n".$tail;
    }

    private function tail(string $stderr): string
    {
        $redacted = rtrim((new SecretRedactor)->redact($stderr), "\r\n");
        if ($redacted === '') {
            return '';
        }
        $scrubbed = mb_scrub($redacted, 'UTF-8');
        $lines = preg_split('/\R/u', $scrubbed);
        if ($lines === false) {
            $lines = [$scrubbed];
        }
        $tail = implode("\n", array_slice($lines, -20));
        if (mb_strlen($tail) <= 2000) {
            return $tail;
        }

        return mb_substr($tail, -2000);
    }
}
