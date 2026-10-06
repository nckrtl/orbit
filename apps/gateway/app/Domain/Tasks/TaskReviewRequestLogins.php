<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** Comma-separated GitHub logins requested as reviewers on a published task pull request. */
final readonly class TaskReviewRequestLogins
{
    /**
     * Reads `login,login` into a unique list. Empty tokens are dropped. Order is kept.
     *
     * @return list<string>
     */
    public static function parseEnv(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $logins = [];
        $seen = [];
        foreach (explode(',', $value) as $login) {
            $login = trim($login);
            if ($login === '') {
                continue;
            }
            $key = strtolower($login);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $logins[] = $login;
        }

        return $logins;
    }

    /**
     * GitHub rejects a request that includes the pull request author.
     *
     * @param  list<string>  $logins
     * @return list<string>
     */
    public static function withoutAuthor(array $logins, ?string $authorLogin): array
    {
        if ($authorLogin === null || $authorLogin === '') {
            return $logins;
        }

        $author = strtolower($authorLogin);

        return array_values(array_filter(
            $logins,
            static fn (string $login): bool => strtolower($login) !== $author,
        ));
    }
}
