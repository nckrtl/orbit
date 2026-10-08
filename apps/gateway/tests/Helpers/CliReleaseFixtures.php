<?php

declare(strict_types=1);

use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CLI_RELEASE_FIXTURE_COMMIT = '1f0e4c5d6b7a8c9d0e1f2a3b4c5d6e7f8a9b0c1d';

const CLI_RELEASE_FIXTURE_NUMBER = 4681;

function cli_release_fixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/GitHub/CliRelease/'.$name);
}

/** @return array<string, mixed> */
function cli_release_fixture_json(string $name): array
{
    return json_decode(cli_release_fixture($name), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fakes GitHub for the fixture release `cli-v0.4681.0`. Each override replaces the response for a URL path.
 *
 * @param  array<string, mixed>  $overrides
 */
function fake_cli_release_github(array $overrides = []): void
{
    $responses = [
        '/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(cli_release_fixture_json('tag-ref.json')),
        '/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response(cli_release_fixture_json('release.json')),
        '/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS' => Http::response(cli_release_fixture('SHA256SUMS')),
        ...$overrides,
    ];

    Http::preventStrayRequests();
    Http::fake(static function (Request $request) use ($responses) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return $responses[$path] ?? Http::response(['message' => 'Not Found'], 404);
    });
}

/**
 * Fakes GitHub for several releases built from the fixture release: each number is published with its tag on the
 * commit. The arrays are read on every request, so a test can publish a release or fail a path later. A path
 * in `$responses` answers before any release.
 *
 * @param  array<int, string>  $published  Commit per release number.
 * @param  array<string, mixed>  $responses  Response per URL path.
 */
function fake_cli_releases(array &$published, array &$responses = []): void
{
    Http::preventStrayRequests();
    Http::fake(static function (Request $request) use (&$published, &$responses) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (isset($responses[$path])) {
            return $responses[$path];
        }

        if (preg_match('#/cli-v0\.([0-9]+)\.0(/|\z)#', $path, $match) === 1 && isset($published[(int) $match[1]])) {
            $fixture = static fn (string $name): string => str_replace(
                ['0.'.CLI_RELEASE_FIXTURE_NUMBER.'.0', CLI_RELEASE_FIXTURE_COMMIT],
                ['0.'.$match[1].'.0', $published[(int) $match[1]]],
                cli_release_fixture($name),
            );

            return match (true) {
                str_contains($path, '/git/ref/tags/') => Http::response($fixture('tag-ref.json')),
                str_contains($path, '/releases/tags/') => Http::response($fixture('release.json')),
                str_ends_with($path, '/SHA256SUMS') => Http::response($fixture('SHA256SUMS')),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        }

        return Http::response(['message' => 'Not Found'], 404);
    });
}

/**
 * @param  array<string, int>  $ancestors  The commits the Gateway's commit reaches, newest first, with their counts.
 * @param  list<string>  $cliChanged  Ancestors whose CLI build inputs differ from the Gateway's commit.
 */
function fake_release_history(?string $commit = CLI_RELEASE_FIXTURE_COMMIT, ?int $count = CLI_RELEASE_FIXTURE_NUMBER, array $ancestors = [], array $cliChanged = []): void
{
    app()->instance(ReleaseHistory::class, new readonly class($commit, $count, $ancestors, $cliChanged) implements ReleaseHistory
    {
        /**
         * @param  array<string, int>  $older
         * @param  list<string>  $changed
         */
        public function __construct(private ?string $resolved, private ?int $total, private array $older, private array $changed) {}

        public function commit(string $revision): ?string
        {
            return $this->resolved !== null && str_starts_with($this->resolved, $revision) ? $this->resolved : null;
        }

        public function count(string $commit): ?int
        {
            return $this->older[$commit] ?? $this->total;
        }

        public function ancestors(string $commit, int $limit): array
        {
            return array_slice(array_keys($this->older), 0, $limit);
        }

        public function unchanged(string $from, string $to, array $paths): bool
        {
            return array_intersect([$from, $to], $this->changed) === [];
        }
    });
}

/** An active operator machine with no roles and no access edges: any authenticated machine. */
function desired_fleet_state_peer(string $address = '10.44.0.7'): Node
{
    return Node::query()->create([
        'name' => 'operator-mac',
        'status' => LifecycleStatus::Active,
        'platform' => 'darwin',
        'public_ssh_host' => '192.0.2.7',
        'wireguard_ip' => $address,
    ]);
}
