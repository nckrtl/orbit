<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Str;

final readonly class ProjectCode
{
    /** @param list<string> $used */
    public static function suggest(string $slug, array $used): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii($slug))) ?? '';
        $preferred = str_pad(substr($letters, 0, 3), 3, 'X');
        $taken = array_fill_keys($used, true);
        if (! isset($taken[$preferred])) {
            return $preferred;
        }
        for ($i = 0; $i < 26 * 26 * 26; $i++) {
            $code = chr(65 + intdiv($i, 676)).chr(65 + intdiv($i % 676, 26)).chr(65 + $i % 26);
            if (! isset($taken[$code])) {
                return $code;
            }
        }
        throw new ResourceOperationException('app.codes_exhausted', 'All three-letter Project codes are in use.', 409);
    }

    public static function validate(string $code): string
    {
        if (preg_match('/\A[A-Z]{3}\z/D', $code) !== 1) {
            throw new ResourceOperationException('app.invalid_code', 'A Project code must contain exactly three uppercase letters.', 422);
        }

        return $code;
    }
}
