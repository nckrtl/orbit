<?php

declare(strict_types=1);

use App\Domain\Projects\TiaBaselineFiles;
use App\Domain\Projects\TiaBaselineSetup;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

/** @param array<string, string> $files */
function tia_archive(array $files, bool $symlink = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'tia-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
        if ($symlink) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function tia_graph(): string
{
    return '{"baselines":{"main":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","complete":true}}}';
}

/** @param list<array<string, mixed>>|null $runs
 * @param  array<string, mixed>|null  $artifact
 */
function tia_http_fixture(?array $runs = null, ?array $artifact = null, ?string $location = null, ?string $archive = null, bool $permissionFailure = false): void
{
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 7]),
        'https://api.github.com/app/installations/7/access_tokens' => ($permissionFailure ? Http::response(['message' => 'tia-token-secret signed-secret'], 403) : Http::response(['token' => 'tia-token-secret', 'permissions' => ['actions' => 'read']])),
        'https://api.github.com/repos/acme/shop' => Http::response(['default_branch' => 'main']),
        'https://api.github.com/repos/acme/shop/actions/workflows/tia-baseline.yml/runs?branch=main&status=success&per_page=100' => Http::response(['workflow_runs' => $runs ?? [
            ['id' => 9, 'head_branch' => 'main', 'head_sha' => str_repeat('a', 40), 'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'],
        ]]),
        'https://api.github.com/repos/acme/shop/actions/runs/9/artifacts?per_page=100' => Http::response(['total_count' => 1, 'artifacts' => [$artifact ?? [
            'id' => 11, 'name' => 'pest-tia-baseline', 'expired' => false, 'size_in_bytes' => 100,
            'workflow_run' => ['id' => 9, 'head_sha' => str_repeat('a', 40)],
        ]]]),
        'https://api.github.com/repos/acme/shop/actions/artifacts/11/zip' => Http::response('', 302, ['Location' => $location ?? 'https://productionresultssa1.blob.core.windows.net/actions/cache?sig=signed-secret']),
        'https://productionresultssa1.blob.core.windows.net/actions/cache?sig=signed-secret' => Http::response($archive ?? tia_archive(['graph.json' => tia_graph(), 'js-module-graph.cache.json' => '{}'])),
    ]);
}

function tia_project(): Project
{
    return new Project(['repository_url' => 'git@github.com:acme/shop.git']);
}

describe('Gateway GitHub TIA baseline', function (): void {
    it('downloads with a repository-scoped Actions token and no storage authentication', function (): void {
        tia_http_fixture();
        $files = app(TiaBaselineSource::class)->fetch(tia_project());
        expect($files->files)->toBe(['graph.json' => tia_graph(), 'js-module-graph.cache.json' => '{}']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/7/access_tokens'
            && $request->data() === ['repositories' => ['shop'], 'permissions' => ['actions' => 'read']]);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://productionresultssa1.blob.core.windows.net/')
            && ! $request->hasHeader('Authorization'));
        Http::assertSentCount(7);
    });

    it('prepares the TIA restore while instance creation holds time for rollback', function (): void {
        tia_http_fixture();
        $deadline = app(CommandDeadline::class);
        $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);

        $command = $deadline->holding(150.0, fn (): string => app(TiaBaselineSetup::class)->command(tia_project(), 60.0));

        expect($command)->toContain('ORBIT_TIA_FILES', base64_encode(tia_graph()));
        Http::assertSentCount(7);
    });

    it('accepts Pest baselines with recorded results and no optional complete marker', function (): void {
        $graph = json_encode(['schema' => 1, 'baselines' => ['main' => [
            'sha' => str_repeat('a', 40), 'tree' => [],
            'results' => ['example' => ['status' => 0, 'message' => '', 'time' => 0.01, 'assertions' => 1, 'file' => 'tests/ExampleTest.php']],
        ]]], JSON_THROW_ON_ERROR);
        tia_http_fixture(archive: tia_archive(['graph.json' => $graph]));
        expect(app(TiaBaselineSource::class)->fetch(tia_project())->files)->toBe(['graph.json' => $graph]);
    });

    it('refuses missing App credentials without using the CLI or network', function (): void {
        Http::preventStrayRequests();
        expect(fn () => app(TiaBaselineSource::class)->fetch(tia_project()))->toThrow(ResourceOperationException::class, 'Actions read');
        Http::assertNothingSent();
    });

    it('rejects PR, wrong-branch, and unsuccessful runs before artifact lookup', function (array $changes): void {
        $run = array_replace(['id' => 9, 'head_branch' => 'main', 'head_sha' => str_repeat('a', 40), 'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'], $changes);
        tia_http_fixture([$run]);
        expect(fn () => app(TiaBaselineSource::class)->fetch(tia_project()))->toThrow(ResourceOperationException::class, 'No successful default-branch');
        Http::assertSentCount(4);
    })->with([
        'pull request' => [['event' => 'pull_request']],
        'feature branch' => [['head_branch' => 'feature']],
        'failed run' => [['conclusion' => 'failure']],
    ]);

    it('skips expired artifacts', function (): void {
        tia_http_fixture(artifact: ['name' => 'pest-tia-baseline', 'expired' => true]);
        expect(fn () => app(TiaBaselineSource::class)->fetch(tia_project()))->toThrow(ResourceOperationException::class, 'No successful default-branch');
        Http::assertSentCount(5);
    });

    it('redacts permission failures and never forwards the token', function (): void {
        tia_http_fixture(permissionFailure: true);
        try {
            app(TiaBaselineSource::class)->fetch(tia_project());
            test()->fail('Expected an unreadable baseline.');
        } catch (ResourceOperationException $exception) {
            expect($exception->getMessage())->toContain('Actions read')->not->toContain('tia-token-secret', 'signed-secret');
            expect($exception->getPrevious())->toBeNull();
        }
        Http::assertSentCount(2);
    });

    it('rejects an untrusted location without contacting it', function (string $location): void {
        tia_http_fixture(location: $location);
        expect(fn () => app(TiaBaselineSource::class)->fetch(tia_project()))->toThrow(ResourceOperationException::class, 'Actions read');
        Http::assertSentCount(6);
    })->with(['http://productionresultssa1.blob.core.windows.net/x', 'https://evil.example/x', 'https://api.github.com/x', 'https://user@productionresultssa1.blob.core.windows.net/x']);

    it('rejects hostile archives before delivery', function (array $files): void {
        tia_http_fixture(archive: tia_archive($files));
        expect(fn () => app(TiaBaselineSource::class)->fetch(tia_project()))->toThrow(ResourceOperationException::class, 'archive is invalid');
        Http::assertSentCount(7);
    })->with([
        'traversal' => [['../graph.json' => '{}']],
        'unexpected file' => [['graph.json' => '{}', 'credentials' => 'secret']],
        'invalid JSON' => [['graph.json' => '{']],
        'wrong commit' => [['graph.json' => '{"baselines":{"main":{"sha":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","complete":true}}}']],
        'missing results and completeness' => [['graph.json' => '{"baselines":{"main":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}}']],
        'incomplete graph' => [['graph.json' => '{"baselines":{"main":{"sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","complete":false}}}']],
        'oversized expansion' => [['graph.json' => str_repeat(' ', TiaBaselineFiles::MaxBytes + 1)]],
    ]);
});

it('refuses symlink entries in an otherwise valid baseline archive', function (): void {
    $archive = tia_archive(['graph.json' => tia_graph()], symlink: true);
    expect(fn () => TiaBaselineFiles::fromArchive($archive, 'main', str_repeat('a', 40)))
        ->toThrow(ResourceOperationException::class, 'archive is invalid');
});
