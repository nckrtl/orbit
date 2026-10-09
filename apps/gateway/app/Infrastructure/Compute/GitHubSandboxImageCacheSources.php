<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\SandboxImageCacheSources;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Reads `composer.lock` and `package-lock.json`, with their manifests, from each UpCloud-lane Project's
 * default branch. A Project the Gateway GitHub App covers is read with a one-hour `contents: read`
 * token; another repository is read without one, so only a public one is warmed.
 *
 * Only packages that need no credential are kept. Composer keeps packages published on packagist.org,
 * and the build writes a manifest with no requirements, repositories, scripts, or plugins of its own.
 * npm is warmed only when every package resolves from registry.npmjs.org.
 */
final readonly class GitHubSandboxImageCacheSources implements SandboxImageCacheSources
{
    private const int FileBytes = 8 * 1024 * 1024;

    private const int TotalBytes = 64 * 1024 * 1024;

    public function __construct(private GitHubAppStore $apps, private GitHubApi $github) {}

    public function collect(): array
    {
        $projects = [];
        $skipped = [];
        $total = 0;
        $lane = Project::query()->where('task_compute', TaskCompute::Vm->value)->where('slug', '!=', 'orbit')->orderBy('slug')->get();
        foreach ($lane as $project) {
            $slug = $project->slug;
            if (preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/D', $slug) !== 1) {
                continue;
            }
            $repository = GitHubRepository::fromOrigin((string) $project->repository_url);
            if (! $repository instanceof GitHubRepository) {
                $skipped[$slug] = ['composer' => 'not_github', 'npm' => 'not_github'];

                continue;
            }
            try {
                $files = $this->files($project, $repository);
            } catch (Throwable) {
                $skipped[$slug] = ['composer' => 'unreadable', 'npm' => 'unreadable'];

                continue;
            }
            $selected = [];
            $composer = $this->composer($files['composer.lock'] ?? null);
            if (is_string($composer)) {
                $selected['composer.json'] = "{\"name\": \"orbit/cache-warm\", \"require\": {}}\n";
                $selected['composer.lock'] = $composer;
            } else {
                $skipped[$slug]['composer'] = $composer === false ? 'no_public_packages' : 'missing';
            }
            $npm = $this->npm($files['package.json'] ?? null, $files['package-lock.json'] ?? null);
            if ($npm === true) {
                $selected['package.json'] = (string) $files['package.json'];
                $selected['package-lock.json'] = (string) $files['package-lock.json'];
            } else {
                $skipped[$slug]['npm'] = $npm === false ? 'other_source' : 'missing';
            }
            $size = array_sum(array_map(strlen(...), $selected));
            if ($selected === [] || $total + $size > self::TotalBytes) {
                if ($selected !== []) {
                    $skipped[$slug] = ['composer' => 'too_large', 'npm' => 'too_large'];
                }

                continue;
            }
            $total += $size;
            $projects[$slug] = $selected;
        }

        return ['projects' => $projects, 'skipped' => $skipped];
    }

    /** @return array<string, string> */
    private function files(Project $project, GitHubRepository $repository): array
    {
        $token = $this->token($project, $repository);
        $directory = ltrim(ApplicationDirectory::resolve('', $project->root), '/');
        $files = [];
        foreach (['composer.lock', 'package.json', 'package-lock.json'] as $name) {
            $path = ($directory === '' ? '' : $directory.'/').$name;
            $request = Http::connectTimeout(5)->timeout(30)->withHeaders(['Accept' => 'application/vnd.github.raw', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->withOptions(['allow_redirects' => false]);
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $request->get(GitHubActionsReader::Api.GitHubActionsReader::repositoryPath($repository).'/contents/'
                .implode('/', array_map(rawurlencode(...), explode('/', $path))), ['ref' => $project->default_branch]);
            if ($response->status() === 404) {
                continue;
            }
            if (! $response->successful() || strlen($response->body()) > self::FileBytes) {
                throw new RuntimeException('The repository file is unavailable.');
            }
            $files[$name] = $response->body();
        }

        return $files;
    }

    private function token(Project $project, GitHubRepository $repository): ?string
    {
        if ($project->source_access !== ProjectSourceAccess::GitHubApp) {
            return null;
        }
        $credentials = $this->apps->credentials();
        if ($credentials === null) {
            return null;
        }
        $installation = $this->github->repositoryInstallation($credentials, $repository);

        return $installation === null ? null : $this->github->repositoryReadToken($credentials, $installation, $repository);
    }

    /** The lock file with only packagist.org packages; false when none are left, null when there is no usable lock file. */
    private function composer(?string $lock): string|false|null
    {
        if ($lock === null) {
            return null;
        }
        try {
            $data = json_decode($lock, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }
        $kept = 0;
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $data[$section] ?? [];
            if (! is_array($packages) || ! array_is_list($packages)) {
                return null;
            }
            $data[$section] = array_values(array_filter($packages, fn (mixed $package): bool => is_array($package)
                && ($package['notification-url'] ?? null) === 'https://packagist.org/downloads/'));
            $kept += count($data[$section]);
        }
        if ($kept === 0) {
            return false;
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    /** True when every package resolves from registry.npmjs.org; false when one comes from another source. */
    private function npm(?string $manifest, ?string $lock): ?bool
    {
        if ($manifest === null || $lock === null) {
            return null;
        }
        try {
            $data = json_decode($lock, true, 64, JSON_THROW_ON_ERROR);
            json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $packages = is_array($data) ? ($data['packages'] ?? null) : null;
        if (! is_array($packages)) {
            return null;
        }
        foreach ($packages as $path => $package) {
            if ($path === '' || ! is_array($package)) {
                continue;
            }
            $resolved = $package['resolved'] ?? null;
            if (($package['link'] ?? false) === true || ! is_string($resolved) || ! str_starts_with($resolved, 'https://registry.npmjs.org/')) {
                return false;
            }
        }

        return true;
    }
}
