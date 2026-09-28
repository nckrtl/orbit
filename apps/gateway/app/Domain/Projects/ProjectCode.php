<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Str;
use LogicException;

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
            $code = self::letter(intdiv($i, 676)).self::letter(intdiv($i % 676, 26)).self::letter($i % 26);
            if (! isset($taken[$code])) {
                return $code;
            }
        }
        throw new ResourceOperationException('project.codes_exhausted', 'All three-letter Project codes are in use.', 409);
    }

    private static function letter(int $index): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        if ($index < 0 || $index > 25) {
            throw new LogicException('Project code letter is out of range.');
        }

        return $alphabet[$index];
    }

    public static function validate(string $code): string
    {
        if (preg_match('/\A[A-Z]{3}\z/D', $code) !== 1) {
            throw new ResourceOperationException('project.invalid_code', 'A Project code must contain exactly three uppercase letters.', 422);
        }

        return $code;
    }
}
