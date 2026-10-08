<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

/** @param list<string> $arguments */
function docsMergeGit(string $root, array $arguments): string
{
    $process = new Process(['git', '-C', $root, ...$arguments]);
    $process->mustRun();

    return trim($process->getOutput());
}

function docsMergeCommit(string $root, string $message): void
{
    docsMergeGit($root, ['add', '.']);
    docsMergeGit($root, ['commit', '-qm', $message]);
}

function docsMergeIndex(string $root): void
{
    $process = new Process(['php', base_path('artisan'), 'orbit:docs-index'], $root, ['ORBIT_DOCS_ROOT' => $root.'/docs']);
    $process->mustRun();
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function docsMergeRun(string $root, array $arguments, array $environment = []): Process
{
    $process = new Process([base_path('../../bin/docs-merge-check'), ...$arguments], $root, $environment);
    $process->run();

    return $process;
}

function withDocsMergeFixture(Closure $test): void
{
    $root = sys_get_temp_dir().'/orbit-docs-merge-fixture-'.bin2hex(random_bytes(8));
    mkdir($root.'/docs/decisions', 0777, true);
    mkdir($root.'/docs/domains', 0777, true);
    mkdir($root.'/docs/reference', 0777, true);
    mkdir($root.'/apps/docs/config', 0777, true);
    try {
        foreach (['README', 'mission', 'architecture', 'tech-stack', 'concepts'] as $name) {
            file_put_contents($root.'/docs/'.$name.'.md', "# Documentation\n\nOrbit checks documentation.\n");
        }
        file_put_contents($root.'/docs/domains/.gitkeep', '');
        file_put_contents($root.'/docs/decisions/overview.mdx', "# Decisions\n\n## Records\n\nOrbit records decisions.\n\n## Retired decisions\n\n| Record | Decision | Now in |\n| --- | --- | --- |\n");
        file_put_contents($root.'/docs/docs.json', '{"navigation":{"pages":["README"]},"redirects":[]}');
        file_put_contents($root.'/apps/docs/config/adr-legacy-allowlist.php', '<?php return [];');
        file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', '<?php return [];');
        docsMergeGit($root, ['init', '-q', '-b', 'main']);
        docsMergeGit($root, ['config', 'user.name', 'Docs']);
        docsMergeGit($root, ['config', 'user.email', 'docs@example.test']);
        docsMergeGit($root, ['config', 'commit.gpgsign', 'false']);
        docsMergeGit($root, ['config', 'gc.auto', '0']);
        docsMergeGit($root, ['config', 'maintenance.auto', 'false']);
        docsMergeIndex($root);
        docsMergeCommit($root, 'common ancestor');
        docsMergeGit($root, ['branch', 'pr']);

        // The base adds retirement metadata after the PR has forked.
        file_put_contents($root.'/docs/decisions/overview.mdx', file_get_contents($root.'/docs/decisions/overview.mdx')."| 0197 | Check documentation | [Guide](/README) |\n");
        file_put_contents($root.'/apps/docs/config/adr-retired-slugs.php', "<?php return ['0197' => ['0197-check-documentation']];");
        file_put_contents($root.'/docs/docs.json', '{"navigation":{"pages":["README"]},"redirects":[{"source":"/decisions/0197-check-documentation","destination":"/README"}]}');
        docsMergeIndex($root);
        docsMergeCommit($root, 'retire 0197 on base');
        docsMergeGit($root, ['checkout', '-q', 'pr']);
        $test($root);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
}

it('retired 0197 reuse passes head-only and fails the merge check', function (bool $cached): void {
    withDocsMergeFixture(function (string $root) use ($cached): void {
        file_put_contents($root.'/docs/decisions/0197-live-decision.md', "# ADR 0197: Check a documentation change\n\nOrbit checks the merge result.\n\n## Status\n\nIn progress.\n\nPrinciple: lean.\n\n## Context\n\nBranches can diverge.\n\n## Decision\n\nOrbit checks the resulting tree.\n\n## Rejected alternatives\n\n- Check only the head: misses base changes.\n\n## Consequences\n\n- Finds invalid combinations.\n\n## Affects\n\n- Components: apps/docs\n- ADRs: none\n- Detail: [Guide](/README)\n- Verify: Docs lint tests.\n");
        docsMergeIndex($root);
        docsMergeCommit($root, 'reuse 0197 on old branch');
        $environment = [];
        $cacheContents = null;
        if ($cached) {
            // Generate a real Laravel cache pointing at a clean, different tree.
            // Keep all files under fixture .git, never in the tooling's workspace cache.
            $clean = $root.'/.git/clean-base';
            docsMergeGit($root, ['clone', '--quiet', '--shared', '--branch', 'main', $root, $clean]);
            $cache = $root.'/.git/cached-config.php';
            $environment = ['APP_CONFIG_CACHE' => $cache];
            new Process(['php', base_path('artisan'), 'config:cache'], $root, [
                ...$environment,
                'ORBIT_DOCS_ROOT' => $clean.'/docs',
            ])->mustRun();
            $cacheContents = file_get_contents($cache);
            expect($cacheContents)->toBeString()->toContain($clean.'/docs');
            // Confirm the cached configuration bypasses the requested root.
            $probe = new Process(['php', base_path('artisan'), 'orbit:docs-lint', '--format=text', '--strict'], $root, [
                ...$environment,
                'ORBIT_DOCS_ROOT' => $root.'/missing-docs',
            ]);
            $probe->mustRun();
            expect($probe->getOutput())->toContain('Documentation lint passed.');
        }
        $before = docsMergeGit($root, ['rev-parse', 'HEAD']);
        $head = docsMergeRun($root, ['--base', 'main', '--head-only'], $environment);
        expect($head->getExitCode())->toBe(0, $head->getOutput().$head->getErrorOutput());
        $merge = docsMergeRun($root, ['--base', 'main', '--head', 'pr'], $environment);
        expect($merge->getExitCode())->toBe(1)
            ->and($merge->getOutput())->toContain('Retired ADR number 0197 cannot be reused.')
            ->and(docsMergeGit($root, ['rev-parse', 'HEAD']))->toBe($before)
            ->and(docsMergeGit($root, ['status', '--porcelain']))->toBe('');
        if ($cached) {
            expect(file_get_contents($root.'/.git/cached-config.php'))->toBe($cacheContents);
        }
    });
})->with(['uncached configuration' => false, 'cached configuration points at clean docs' => true]);

it('a normal fixture PR passes both head-only and merge checks', function (): void {
    withDocsMergeFixture(function (string $root): void {
        file_put_contents($root.'/docs/architecture.md', "# Documentation\n\nOrbit checks a clean change.\n");
        docsMergeIndex($root);
        docsMergeCommit($root, 'normal documentation change');
        foreach ([['--head-only'], ['--base', 'main']] as $arguments) {
            $result = docsMergeRun($root, $arguments);
            expect($result->getExitCode())->toBe(0, $result->getOutput().$result->getErrorOutput());
        }
    });
});

it('detects ratchet removal despite a missing or stale local main', function (bool $missingMain, bool $headOnly): void {
    withDocsMergeFixture(function (string $root) use ($missingMain, $headOnly): void {
        // Add the ratchet on the shared history, then remove it on the PR.
        file_put_contents($root.'/docs/reference/guide.md', "---\ncovers:\n  - apps/docs/config/adr-retired-slugs.php\n---\n\n# Documentation\n\nOrbit checks documentation.\n");
        file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return ['docs/reference/guide.md'];\n");
        docsMergeIndex($root);
        docsMergeCommit($root, 'establish ratchet baseline');
        $baseline = docsMergeGit($root, ['rev-parse', 'HEAD']);
        if ($headOnly) {
            docsMergeGit($root, ['update-ref', 'refs/remotes/origin/main', $baseline]);
        }
        if ($missingMain) {
            docsMergeGit($root, ['branch', '-D', 'main']);
        }
        file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return [];\n");
        docsMergeCommit($root, 'remove ratchet entry');
        $before = docsMergeGit($root, ['rev-parse', 'HEAD']);
        // Merge mode also accepts a SHA with no corresponding local branch.
        // Head-only must ignore --base and use the real source origin/main.
        $arguments = $headOnly ? ['--head-only', '--base', 'missing-ref'] : ['--base', $baseline];
        $result = docsMergeRun($root, $arguments);
        expect($result->getExitCode())->toBe(1, $result->getOutput().$result->getErrorOutput())
            ->and($result->getOutput())->toContain('The coverage ratchet cannot remove docs/reference/guide.md.')
            ->and(docsMergeGit($root, ['rev-parse', 'HEAD']))->toBe($before)
            ->and(docsMergeGit($root, ['for-each-ref', '--format=%(objectname)', 'refs/remotes/origin/main']))->toBe($headOnly ? $baseline : '')
            ->and(docsMergeGit($root, ['status', '--porcelain']))->toBe('');
    });
})->with(['no local main' => true, 'stale local main' => false])
    ->with(['head-only' => true, 'merge preview' => false]);

it('head-only does not invent a source baseline from local main', function (): void {
    withDocsMergeFixture(function (string $root): void {
        docsMergeGit($root, ['checkout', '-q', 'main']);
        file_put_contents($root.'/docs/reference/guide.md', "---\ncovers:\n  - apps/docs/config/adr-retired-slugs.php\n---\n\n# Documentation\n\nOrbit checks documentation.\n");
        file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return ['docs/reference/guide.md'];\n");
        docsMergeIndex($root);
        docsMergeCommit($root, 'ratchet only on local main');
        docsMergeGit($root, ['checkout', '-q', '-B', 'pr']);
        file_put_contents($root.'/apps/docs/config/docs-covers-ratchet.php', "<?php return [];\n");
        docsMergeCommit($root, 'remove ratchet without a source remote baseline');
        $result = docsMergeRun($root, ['--head-only']);
        expect($result->getExitCode())->toBe(0, $result->getOutput().$result->getErrorOutput());
    });
});

it('a conflicting fixture merge fails closed without reporting a head-only result', function (): void {
    withDocsMergeFixture(function (string $root): void {
        file_put_contents($root.'/docs/architecture.md', "# Documentation\n\nThe PR changes this sentence.\n");
        docsMergeCommit($root, 'PR conflict');
        docsMergeGit($root, ['checkout', '-q', 'main']);
        file_put_contents($root.'/docs/architecture.md', "# Documentation\n\nThe base changes this sentence.\n");
        docsMergeCommit($root, 'base conflict');
        docsMergeGit($root, ['checkout', '-q', 'pr']);
        expect(docsMergeRun($root, ['--head-only'])->getExitCode())->toBe(0);
        $result = docsMergeRun($root, ['--base', 'main']);
        expect($result->getExitCode())->toBe(2)
            ->and($result->getErrorOutput())->toContain('merge_conflict:', 'docs/architecture.md')
            ->and($result->getOutput())->not->toContain('Documentation lint passed.');
    });
});

it('documents flags and exit codes and refuses an unavailable base or head', function (): void {
    withDocsMergeFixture(function (string $root): void {
        $help = docsMergeRun($root, ['--help']);
        expect($help->getExitCode())->toBe(0)
            ->and($help->getOutput())->toContain('--base', '--head', '--head-only', 'Exit codes: 0', '1 lint failed', '2 preview/setup failed');
        foreach ([[], ['--base', 'missing-ref']] as $arguments) {
            $result = docsMergeRun($root, $arguments);
            expect($result->getExitCode())->toBe(2)
                ->and($result->getErrorOutput())->toContain('base_unavailable:');
        }
        $head = docsMergeRun($root, ['--head-only', '--head', 'missing-ref']);
        expect($head->getExitCode())->toBe(2)
            ->and($head->getErrorOutput())->toContain('head_unavailable:');
    });
});
