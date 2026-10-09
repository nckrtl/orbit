<?php

declare(strict_types=1);

use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\GitHub\GitHubApi;
use App\Infrastructure\Fleet\GitHubCliReleaseCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

function find_fixture_cli_release(): array
{
    return app(CliReleaseCatalog::class)
        ->find(CLI_RELEASE_FIXTURE_COMMIT, new CliReleaseName(CLI_RELEASE_FIXTURE_NUMBER))
        ->toArray();
}

describe(GitHubCliReleaseCatalog::class, function (): void {
    it('reads each platform checksum from the published SHA256SUMS of the release', function (): void {
        fake_cli_release_github();

        expect(find_fixture_cli_release())->toBe([
            'status' => 'available',
            'reason' => null,
            'version' => '0.4681.0',
            'tag' => 'cli-v0.4681.0',
            'commit' => CLI_RELEASE_FIXTURE_COMMIT,
            'checksums_url' => 'https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS',
            'assets' => [
                [
                    'platform' => 'linux-x86_64',
                    'name' => 'orbit-0.4681.0-linux-x86_64',
                    'url' => 'https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-linux-x86_64',
                    'sha256' => '79d7424eeafdc38c773b8f0a7d1b67b21e38e6abb6b458f3e42b89e2cde7a0ce',
                ],
                [
                    'platform' => 'linux-aarch64',
                    'name' => 'orbit-0.4681.0-linux-aarch64',
                    'url' => 'https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-linux-aarch64',
                    'sha256' => '302392f9254b17c6acfd2ab5861fd1b1881495b0db83fad390fa4ef685e021e5',
                ],
                [
                    'platform' => 'macos-arm64',
                    'name' => 'orbit-0.4681.0-macos-arm64',
                    'url' => 'https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-macos-arm64',
                    'sha256' => 'a85894d3eab58e3ae397defa53cbffbcebb7a232c43c1de6b54d443b6989705f',
                ],
            ],
        ]);

        Http::assertSent(static fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    });

    it('asks GitHub with a read-only App token when the App is installed on the repository', function (): void {
        GitHubTestSupport::storeApp();
        $github = mock(GitHubApi::class);
        $github->shouldReceive('repositoryInstallation')->once()->andReturn(77);
        $github->shouldReceive('repositoryReadToken')->once()->with(Mockery::any(), 77, Mockery::any())->andReturn('ghs_read-only');
        app()->instance(GitHubApi::class, $github);
        fake_cli_release_github();

        expect(find_fixture_cli_release()['status'])->toBe('available');

        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/')
            && $request->header('Authorization') === ['Bearer ghs_read-only']);
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/SHA256SUMS')
            && ! $request->hasHeader('Authorization'));
    });

    it('falls back to anonymous reads when the App token cannot be had', function (): void {
        GitHubTestSupport::storeApp();
        $github = mock(GitHubApi::class);
        $github->shouldReceive('repositoryInstallation')->once()->andThrow(new RuntimeException('GitHub is down.'));
        app()->instance(GitHubApi::class, $github);
        fake_cli_release_github();

        expect(find_fixture_cli_release()['status'])->toBe('available');
    });

    it('follows an annotated tag to its commit', function (): void {
        fake_cli_release_github([
            '/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(['object' => ['type' => 'tag', 'sha' => str_repeat('a', 40)]]),
            '/repos/nckrtl/orbit/git/tags/'.str_repeat('a', 40) => Http::response(['object' => ['type' => 'commit', 'sha' => CLI_RELEASE_FIXTURE_COMMIT]]),
        ]);

        expect(find_fixture_cli_release()['status'])->toBe('available');
    });

    it('reports a release CI has not published yet as pending, with its version', function (Closure $overrides): void {
        fake_cli_release_github($overrides());

        expect(find_fixture_cli_release())->toBe([
            'status' => 'pending',
            'reason' => 'release_missing',
            'version' => '0.4681.0',
            'tag' => 'cli-v0.4681.0',
            'commit' => CLI_RELEASE_FIXTURE_COMMIT,
            'checksums_url' => null,
            'assets' => [],
        ]);
    })->with([
        'no tag yet' => [static fn (): array => ['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(['message' => 'Not Found'], 404)]],
        'tag without a published release' => [static fn (): array => ['/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response(['message' => 'Not Found'], 404)]],
    ]);

    it('reports an unavailable release with a reason instead of failing', function (Closure $overrides, string $reason): void {
        fake_cli_release_github($overrides());

        expect(find_fixture_cli_release())->toBe([
            'status' => 'unavailable',
            'reason' => $reason,
            'version' => null,
            'tag' => null,
            'commit' => null,
            'checksums_url' => null,
            'assets' => [],
        ]);
    })->with([
        'tag on another commit' => [static fn (): array => ['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(['object' => ['type' => 'commit', 'sha' => str_repeat('b', 40)]])], 'release_mismatch'],
        'draft release' => [static fn (): array => ['/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response([...cli_release_fixture_json('release.json'), 'draft' => true])], 'release_incomplete'],
        'missing binary' => [static fn (): array => ['/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response([
            ...cli_release_fixture_json('release.json'),
            'assets' => array_values(array_filter(cli_release_fixture_json('release.json')['assets'], static fn (array $asset): bool => $asset['name'] !== 'orbit-0.4681.0-macos-arm64')),
        ])], 'release_incomplete'],
        'asset still uploading' => [static fn (): array => ['/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response([
            ...cli_release_fixture_json('release.json'),
            'assets' => array_map(static fn (array $asset): array => [...$asset, 'state' => 'starter'], cli_release_fixture_json('release.json')['assets']),
        ])], 'release_incomplete'],
        'SHA256SUMS without a binary' => [static fn (): array => ['/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS' => Http::response(implode("\n", array_slice(explode("\n", cli_release_fixture('SHA256SUMS')), 0, 2))."\n")], 'release_incomplete'],
        'SHA256SUMS in another format' => [static fn (): array => ['/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS' => Http::response(str_replace('  ', ' *', cli_release_fixture('SHA256SUMS')))], 'release_incomplete'],
        'GitHub error' => [static fn (): array => ['/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response(['message' => 'Server Error'], 502)], 'github_unavailable'],
        'rate limited' => [static fn (): array => ['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(['message' => 'API rate limit exceeded'], 403)], 'github_unavailable'],
    ]);

    it('reports GitHub unavailable when the connection fails', function (): void {
        Http::fake(static fn () => throw new ConnectionException('Connection refused.'));

        expect(find_fixture_cli_release()['reason'])->toBe('github_unavailable');
    });
});
