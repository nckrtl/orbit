<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use Illuminate\Http\Client\Request;

/** Real nckrtl/orbit responses, projected as described in `tests/Fixtures/GitHub/GreenCommit/SOURCE.md`. */
final class GreenCommitFixtures
{
    /** @return list<array{sha: string, parents: list<array{sha: string}>}> */
    public static function commits(): array
    {
        return self::read('commits.json');
    }

    /** @return list<string> */
    public static function mainShas(): array
    {
        return array_column(self::commits(), 'sha');
    }

    /**
     * The `Required checks` response for a commit of the fixture history.
     *
     * @return array{total_count: int, check_runs: list<array<string, mixed>>}
     */
    public static function requiredChecks(string $sha): array
    {
        return self::read('required-checks.json')[$sha];
    }

    /**
     * A real `Required checks` response of another commit, moved onto this one.
     *
     * @return array{total_count: int, check_runs: list<array<string, mixed>>}
     */
    public static function requiredChecksAs(string $state, string $sha): array
    {
        $response = self::read('required-checks-states.json')[$state];
        foreach ($response['check_runs'] as $index => $run) {
            $response['check_runs'][$index]['head_sha'] = $sha;
        }

        return $response;
    }

    /** @return list<array{total_count: int, check_runs: list<array<string, mixed>>}> */
    public static function checkRunPages(): array
    {
        return self::read('check-runs-pages.json');
    }

    /** @return array{status: string, base_commit: array{sha: string}, merge_base_commit: array{sha: string}} */
    public static function comparison(string $status): array
    {
        return self::read('compare.json')[$status];
    }

    /** @return array<array-key, mixed> */
    public static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<array-key, mixed> */
    private static function read(string $file): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/GitHub/GreenCommit/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }
}
