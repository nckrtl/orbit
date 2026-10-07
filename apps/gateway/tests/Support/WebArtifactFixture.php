<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\GitHub\GitHubTestSupport;
use ZipArchive;

/**
 * GitHub Actions answers for one `web-dist-<sha>` artifact, built from the recorded shapes in
 * `tests/Fixtures/GitHub/WebBuild`, plus a web directory in a temporary directory. The shared
 * release repository's origin is `https://github.com/nckrtl/orbit.git`; every other command runs.
 */
final class WebArtifactFixture implements ProcessRunner
{
    public const string Sha = 'a30e23427e697cd3359d8c127c00d7dd09719007';

    public const string Token = 'web-token-secret';

    public const string Storage = 'https://productionresultssa9.blob.core.windows.net/actions-results/web.zip?sig=signed-secret';

    public readonly string $web;

    /** @var list<list<string>> */
    public array $commands = [];

    private readonly NativeProcessRunner $native;

    public function __construct(public readonly GatewayReleaseLayout $layout, ?string $web = null)
    {
        $this->web = $web ?? sys_get_temp_dir().'/orbit-web-'.Str::lower(Str::random(10));
        $this->native = new NativeProcessRunner(maxOutputBytes: 1_048_576);
        @mkdir($this->web.'/releases', 0750, true);
    }

    public static function group(): string
    {
        $group = posix_getgrgid(posix_getegid());

        if (! is_array($group)) {
            throw new RuntimeException('The test process has no group name.');
        }

        return $group['name'];
    }

    public function build(): GitHubArtifactWebBuild
    {
        return new GitHubArtifactWebBuild(
            layout: $this->layout,
            processes: $this,
            actions: app(GitHubActionsReader::class),
            webRoot: $this->web,
            group: self::group(),
        );
    }

    /**
     * Fakes GitHub for the artifact of `$sha`. `$artifact` and `$run` change single fields of the
     * recorded shapes. `$archive` is the ZIP served from storage.
     *
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $run
     */
    public function github(string $sha = self::Sha, ?string $archive = null, array $artifact = [], array $run = [], bool $missing = false, bool $denied = false): string
    {
        $archive ??= self::archive(['index.html' => '<!doctype html><script src="/assets/app-abc123.js"></script>', 'assets/app-abc123.js' => 'console.log(1)']);
        $listing = self::recorded($missing ? 'artifacts-none.json' : 'artifacts.json');

        if (! $missing) {
            $listing['artifacts'][0] = [
                ...$listing['artifacts'][0],
                'name' => 'web-dist-'.$sha,
                'size_in_bytes' => strlen($archive),
                'digest' => 'sha256:'.hash('sha256', $archive),
                'workflow_run' => [...$listing['artifacts'][0]['workflow_run'], 'head_sha' => $sha],
                ...$artifact,
            ];
        }

        $recordedRun = self::recorded('run.json');

        GitHubTestSupport::storeApp();
        Http::preventStrayRequests();
        $runBody = [...$recordedRun, 'head_sha' => $sha, ...$run];
        $token = $denied
            ? ['body' => ['message' => self::Token], 'status' => 403]
            : ['body' => ['token' => self::Token, 'permissions' => ['actions' => 'read']], 'status' => 200];

        // Closures answer each request with a fresh body, so a second install reads GitHub again.
        Http::fake([
            'https://api.github.com/repos/nckrtl/orbit/installation' => fn () => Http::response(['id' => 7]),
            'https://api.github.com/app/installations/7/access_tokens' => fn () => Http::response($token['body'], $token['status']),
            'https://api.github.com/repos/nckrtl/orbit/actions/artifacts?name=web-dist-'.$sha.'&per_page=100' => fn () => Http::response($listing),
            'https://api.github.com/repos/nckrtl/orbit/actions/runs/37639888555' => fn () => Http::response($runBody),
            'https://api.github.com/repos/nckrtl/orbit/actions/artifacts/11492530096/zip' => fn () => Http::response('', 302, ['Location' => self::Storage]),
            self::Storage => fn () => Http::response($archive),
        ]);

        return $archive;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, int>  $modes  Unix file modes by entry name.
     */
    public static function archive(array $files, array $modes = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'web-artifact-');
        $zip = new ZipArchive;
        $zip->open((string) $path, ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $contents);
            }

            if (isset($modes[$name])) {
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $modes[$name] << 16);
            }
        }

        $zip->close();
        $bytes = (string) file_get_contents((string) $path);
        unlink((string) $path);

        return $bytes;
    }

    /** @return array<string, mixed> */
    public static function recorded(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/GitHub/WebBuild/'.$file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture [{$file}] is not an object.");
        }

        return $decoded;
    }

    /** @return list<string> the entries of the web releases directory, hidden ones included */
    public function releases(): array
    {
        return array_values(array_diff((array) scandir($this->web.'/releases'), ['.', '..']));
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->commands[] = $invocation->arguments;

        if (array_slice($invocation->arguments, -3) === ['remote', 'get-url', 'origin']) {
            return new CommandResult(0, "https://github.com/nckrtl/orbit.git\n", '', 1, false);
        }

        return $this->native->run($invocation);
    }

    public function cleanup(): void
    {
        if (is_dir($this->web)) {
            exec('chmod -R u+w '.escapeshellarg($this->web).' && rm -rf '.escapeshellarg($this->web));
        }
    }
}
