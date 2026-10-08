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
 * @param  array<string, int>  $ancestors  The commits the Gateway's commit reaches, newest first, with their counts.
 */
function fake_release_history(?string $commit = CLI_RELEASE_FIXTURE_COMMIT, ?int $count = CLI_RELEASE_FIXTURE_NUMBER, array $ancestors = []): void
{
    app()->instance(ReleaseHistory::class, new readonly class($commit, $count, $ancestors) implements ReleaseHistory
    {
        /** @param  array<string, int>  $older */
        public function __construct(private ?string $resolved, private ?int $total, private array $older) {}

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
