<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayReleaseFixture;
use Tests\Support\WebArtifactFixture;

beforeEach(function (): void {
    $this->web = new WebArtifactFixture(new GatewayReleaseLayout(sys_get_temp_dir().'/orbit-web-layout/orbit/apps/gateway'));
    $this->id = substr(WebArtifactFixture::Sha, 0, 12);
});

afterEach(function (): void {
    $this->web->cleanup();
});

describe('web build install', function (): void {
    it('installs the CI artifact of the exact commit with index.html and the bin/web-deploy permissions', function (): void {
        $this->web->github();

        $reused = $this->web->build()->install($this->id, WebArtifactFixture::Sha);
        $release = $this->web->web.'/releases/'.$this->id;

        expect($reused)->toBeFalse()
            ->and(file_get_contents($release.'/index.html'))->toContain('/assets/app-abc123.js')
            ->and(file_get_contents($release.'/assets/app-abc123.js'))->toBe('console.log(1)')
            ->and(fileperms($release) & 0777)->toBe(0750)
            ->and(fileperms($release.'/assets') & 0777)->toBe(0750)
            ->and(fileperms($release.'/index.html') & 0777)->toBe(0640)
            ->and(posix_getgrgid(filegroup($release.'/index.html'))['name'])->toBe(WebArtifactFixture::group())
            ->and($this->web->releases())->toBe([$this->id])
            ->and(file_exists($this->web->web.'/current'))->toBeFalse();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/7/access_tokens'
            && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['actions' => 'read']]);
        Http::assertSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage && ! $request->hasHeader('Authorization'));
        Http::assertSentCount(6);
    });

    it('reuses a complete install without calling GitHub', function (): void {
        $this->web->github();
        $this->web->build()->install($this->id, WebArtifactFixture::Sha);

        expect($this->web->build()->install($this->id, WebArtifactFixture::Sha))->toBeTrue();
        Http::assertSentCount(6);
    });

    it('fails without a retry when CI published no artifact for the commit', function (array $variant, string $message): void {
        $this->web->github(...$variant);

        $exception = release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_missing')
            ->and($exception->step)->toBe('web')
            ->and($exception->status)->toBe(422)
            ->and($exception->getMessage())->toContain($message)
            ->and($this->web->releases())->toBe([]);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage);
    })->with([
        'no artifact' => [['missing' => true], 'published no artifact'],
        'expired' => [['artifact' => ['expired' => true]], 'has expired'],
        'pull request run' => [['run' => ['event' => 'pull_request']], 'published no artifact'],
        'fork run' => [['run' => ['head_repository' => ['id' => 99, 'full_name' => 'fork/orbit']]], 'published no artifact'],
        'other branch' => [['run' => ['head_branch' => 'feature']], 'published no artifact'],
        'other workflow' => [['run' => ['path' => '.github/workflows/tia-baseline.yml']], 'published no artifact'],
        'other commit' => [['artifact' => ['workflow_run' => ['id' => 37647307459, 'repository_id' => 1348221080, 'head_repository_id' => 1348221080, 'head_branch' => 'main', 'head_sha' => str_repeat('b', 40)]]], 'published no artifact'],
    ]);

    it('accepts the artifact of a manual CI dispatch on main', function (): void {
        $this->web->github(run: ['event' => 'workflow_dispatch']);

        expect($this->web->build()->install($this->id, WebArtifactFixture::Sha))->toBeFalse()
            ->and(is_file($this->web->web.'/releases/'.$this->id.'/index.html'))->toBeTrue();
    });

    it('refuses an artifact without a digest before it downloads it', function (): void {
        $this->web->github(artifact: ['digest' => null]);

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_build_invalid')
            ->and($this->web->releases())->toBe([]);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage);
    });

    it('retries later when GitHub cannot be read and never shows the token', function (): void {
        $this->web->github(denied: true);

        $exception = release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_unavailable')
            ->and($exception->getMessage())->not->toContain(WebArtifactFixture::Token)
            ->and($exception->getPrevious())->toBeNull()
            ->and($this->web->releases())->toBe([]);
    });

    it('refuses a hostile archive and leaves nothing behind', function (string $archive): void {
        $this->web->github(archive: $archive);

        $exception = release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_invalid')
            ->and($exception->status)->toBe(422)
            ->and($this->web->releases())->toBe([])
            ->and(file_exists(dirname($this->web->web).'/escaped.txt'))->toBeFalse();
    })->with([
        'parent traversal' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', '../escaped.txt' => 'x']),
        'nested traversal' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', 'assets/../../escaped.txt' => 'x']),
        'absolute name' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', '/tmp/escaped.txt' => 'x']),
        'backslash name' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', 'assets\\..\\escaped.txt' => 'x']),
        'symlink entry' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', 'assets/link' => '/etc/passwd'], ['assets/link' => 0120777]),
        'symlinked index' => fn (): string => WebArtifactFixture::archive(['index.html' => '/etc/passwd'], ['index.html' => 0120777]),
        'special file' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', 'fifo' => ''], ['fifo' => 0010644]),
        'file under a file' => fn (): string => WebArtifactFixture::archive(['index.html' => 'ok', 'index.html/x' => 'x']),
        'no index' => fn (): string => WebArtifactFixture::archive(['assets/app.js' => 'x']),
        'index in a subdirectory only' => fn (): string => WebArtifactFixture::archive(['dist/index.html' => 'x']),
        'not a zip' => fn (): string => 'not a zip archive',
    ]);

    it('refuses an archive that does not match the artifact digest', function (): void {
        $this->web->github(artifact: ['digest' => 'sha256:'.str_repeat('0', 64)]);

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_build_invalid')
            ->and($this->web->releases())->toBe([]);
    });

    it('refuses an artifact above the size limit before downloading it', function (): void {
        $this->web->github(artifact: ['size_in_bytes' => 512 * 1024 * 1024]);

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_build_invalid');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === WebArtifactFixture::Storage);
    });

    it('fails before a download when the web directory is missing', function (): void {
        $this->web->github();
        rmdir($this->web->web.'/releases');

        expect(release_failure(fn () => $this->web->build()->install($this->id, WebArtifactFixture::Sha))->errorCode)->toBe('gateway.release_web_directory_missing');
        Http::assertNothingSent();
    });
});

describe('web build publish and restore', function (): void {
    beforeEach(function (): void {
        foreach (['aaaaaaaaaaaa', 'bbbbbbbbbbbb'] as $id) {
            mkdir($this->web->web.'/releases/'.$id, 0750);
            file_put_contents($this->web->web.'/releases/'.$id.'/index.html', $id);
        }

        symlink('releases/aaaaaaaaaaaa', $this->web->web.'/current');
    });

    it('switches current to the release with one rename', function (): void {
        $this->web->build()->publish('bbbbbbbbbbbb');

        expect(readlink($this->web->web.'/current'))->toBe('releases/bbbbbbbbbbbb')
            ->and(file_get_contents($this->web->web.'/current/index.html'))->toBe('bbbbbbbbbbbb')
            ->and(file_exists($this->web->web.'/current.next') || is_link($this->web->web.'/current.next'))->toBeFalse()
            ->and($this->web->commands)->toContain(['mv', '-Tf', '--', $this->web->web.'/current.next', $this->web->web.'/current']);
    });

    it('refuses to publish a release that is not installed and keeps current', function (): void {
        $exception = release_failure(fn () => $this->web->build()->publish('cccccccccccc'));

        expect($exception->errorCode)->toBe('gateway.release_web_build_missing')
            ->and($exception->step)->toBe('web')
            ->and(readlink($this->web->web.'/current'))->toBe('releases/aaaaaaaaaaaa');
    });

    it('restores the build that was current before the publish', function (): void {
        $build = $this->web->build();
        $build->publish('bbbbbbbbbbbb');

        $build->restore('aaaaaaaaaaaa');

        expect(readlink($this->web->web.'/current'))->toBe('releases/aaaaaaaaaaaa');
    });

    it('restores the exact build current served, also when it was not the previous release\'s', function (): void {
        mkdir($this->web->web.'/releases/cccccccccccc', 0750);
        file_put_contents($this->web->web.'/releases/cccccccccccc/index.html', 'cccccccccccc');
        symlink('releases/cccccccccccc', $this->web->web.'/current.tmp');
        rename($this->web->web.'/current.tmp', $this->web->web.'/current');
        $build = $this->web->build();
        $build->publish('bbbbbbbbbbbb');

        $build->restore('aaaaaaaaaaaa');

        expect(readlink($this->web->web.'/current'))->toBe('releases/cccccccccccc');
    });

    it('leaves current alone on restore when this attempt published nothing', function (): void {
        $this->web->build()->restore('bbbbbbbbbbbb');

        expect(readlink($this->web->web.'/current'))->toBe('releases/aaaaaaaaaaaa')
            ->and($this->web->commands)->toBe([]);
    });

    it('removes the web build of a pruned release but never the current one', function (): void {
        $build = $this->web->build();

        $build->remove('aaaaaaaaaaaa');
        $build->remove('bbbbbbbbbbbb');

        expect($this->web->releases())->toBe(['aaaaaaaaaaaa']);
    });

    it('prunes nothing when it is given no retained release', function (): void {
        $this->web->build()->prune([]);

        expect($this->web->releases())->toEqualCanonicalizing(['aaaaaaaaaaaa', 'bbbbbbbbbbbb']);
    });

    it('prunes web builds of no retained release, but never the current one', function (): void {
        foreach (['cccccccccccc', 'dddddddddddd'] as $id) {
            mkdir($this->web->web.'/releases/'.$id, 0750);
            file_put_contents($this->web->web.'/releases/'.$id.'/index.html', $id);
        }

        mkdir($this->web->web.'/releases/.eeeeeeeeeeee.partial', 0750);
        $this->web->build()->prune(['bbbbbbbbbbbb']);

        expect($this->web->releases())->toEqualCanonicalizing(['.eeeeeeeeeeee.partial', 'aaaaaaaaaaaa', 'bbbbbbbbbbbb']);
    });
});

describe('prepare with the web build', function (): void {
    beforeEach(function (): void {
        $this->fixture = new GatewayReleaseFixture;
        $this->live = $this->fixture->layout->currentPath();
        mkdir($this->live.'/apps/gateway', 0755, true);
        $this->web->cleanup();
        $this->web = new WebArtifactFixture($this->fixture->layout, $this->fixture->base.'/web');
    });

    afterEach(function (): void {
        $this->fixture->cleanup();
    });

    it('installs the web build of the commit while it prepares the release', function (): void {
        $sha = $this->fixture->commit('Release with a web build');
        $this->web->github(sha: $sha);

        $release = $this->fixture->builder($this->web->build())->prepare($sha);

        expect($this->fixture->layout->preparedCommit($release->id))->toBe($sha)
            ->and(is_file($this->web->web.'/releases/'.$release->id.'/index.html'))->toBeTrue()
            ->and(file_exists($this->web->web.'/current'))->toBeFalse();
    });

    it('fails prepare before anything is live when the artifact is missing', function (): void {
        $sha = $this->fixture->commit('Release without a web build');
        $this->web->github(sha: $sha, missing: true);

        $exception = release_failure(fn () => $this->fixture->builder($this->web->build())->prepare($sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_missing')
            ->and($exception->sha)->toBe($sha)
            ->and($this->fixture->layout->preparedCommit(substr($sha, 0, 12)))->toBeNull()
            ->and(is_link($this->live))->toBeFalse()
            ->and($this->web->releases())->toBe([]);
    });

    it('installs a missing web build again when it reuses a prepared release', function (): void {
        $sha = $this->fixture->commit('Reused release');
        $this->web->github(sha: $sha);
        $release = $this->fixture->builder($this->web->build())->prepare($sha);
        exec('rm -rf '.escapeshellarg($this->web->web.'/releases/'.$release->id));

        $reused = $this->fixture->builder($this->web->build())->prepare($sha);

        expect($reused->reused)->toBeTrue()
            ->and(is_file($this->web->web.'/releases/'.$release->id.'/index.html'))->toBeTrue();
    });
});
