<?php

declare(strict_types=1);

use App\Documentation\DocsImpact;
use Illuminate\Support\Facades\Artisan;

$GLOBALS['docsImpactGateFixtureRoots'] = [];

afterEach(function (): void {
    foreach ($GLOBALS['docsImpactGateFixtureRoots'] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
    $GLOBALS['docsImpactGateFixtureRoots'] = [];
});

function docsImpactGateFixture(): array
{
    $root = sys_get_temp_dir().'/docs-impact-gate-'.bin2hex(random_bytes(6));
    $GLOBALS['docsImpactGateFixtureRoots'][] = $root;
    mkdir($root.'/docs/reference', 0777, true);
    mkdir($root.'/apps/gateway/app/Domain/Tasks', 0777, true);
    mkdir($root.'/apps/gateway/resources/mcp', 0777, true);
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"apps/gateway/app/Domain/Tasks/**\"\n---\nTasks\n");
    file_put_contents($root.'/docs/reference/mcp.mdx', "---\ntitle: MCP\n---\nMCP documentation.\n");
    file_put_contents($root.'/apps/gateway/resources/mcp/tools.json', '{"tools":[]}');
    file_put_contents($root.'/apps/gateway/app/Domain/Tasks/RunTask.php', "<?php\nclass RunTask {}\n");
    exec('git -C '.escapeshellarg($root).' init -q -b task-1');
    // Detached maintenance can create object files while teardown removes the repository.
    exec('git -C '.escapeshellarg($root).' config gc.auto 0');
    exec('git -C '.escapeshellarg($root).' config maintenance.auto false');
    exec('git -C '.escapeshellarg($root).' add .');
    exec('git -C '.escapeshellarg($root).' -c user.name=Docs -c user.email=docs@example.test commit -qm fixture');
    $base = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));

    return [$root, $base];
}

function changeDocsImpactGateSource(string $root): void
{
    file_put_contents($root.'/apps/gateway/app/Domain/Tasks/RunTask.php', "<?php\nclass RunTask { public function changed(): void {} }\n");
}

function writeUnaffectedPageNote(string $root, string $branch, string $note): void
{
    $directory = dirname($root.'/.git/orbit/docs-unaffected/'.$branch.'.txt');
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($root.'/.git/orbit/docs-unaffected/'.$branch.'.txt', $note."\n");
}

function runDocsImpactGateCommand(string $root, string $base): array
{
    config(['librarian.path' => $root.'/docs']);
    $exitCode = Artisan::call('orbit:docs-impact', ['--base' => $base, '--gate' => true]);

    return [$exitCode, Artisan::output()];
}

function unownedMigrationContents(): string
{
    return <<<'PHP'
<?php
Schema::create('orphaned', function (Blueprint $table): void { $table->string('name'); });
PHP;
}

it('docs impact gate fixtures disable automatic Git maintenance before cleanup', function (): void {
    [$root] = docsImpactGateFixture();
    $settings = [];
    exec('git -C '.escapeshellarg($root).' config --local --get-regexp '.escapeshellarg('^(gc|maintenance)\.auto$'), $settings, $status);

    expect($status)->toBe(0)
        ->and($settings)->toContain('gc.auto 0', 'maintenance.auto false');
});

it('docs impact gate fails and names an impacted page that was not changed', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toContain('docs/reference/tasks.md');
});

it('a note for the checked-out branch waives an impacted page', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    writeUnaffectedPageNote($root, 'task-1', 'docs/reference/tasks.md: This branch changes only internal behavior.');

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeTrue()
        ->and($gate['missing_pages'])->toBe([])
        ->and($gate['exceptions']['docs/reference/tasks.md'])->toBe('This branch changes only internal behavior.');
});

it('a note for another branch does not waive an impacted page', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    writeUnaffectedPageNote($root, 'task-2', 'docs/reference/tasks.md: Another branch changed only internal behavior.');
    writeUnaffectedPageNote($root, 't3code/task-1', 'docs/reference/tasks.md: A nested branch name is a different branch.');

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toContain('docs/reference/tasks.md')
        ->and($gate['exceptions'])->toBe([]);
});

it('a detached HEAD has no notes', function (): void {
    [$root, $base] = docsImpactGateFixture();
    exec('git -C '.escapeshellarg($root).' checkout -q --detach');
    changeDocsImpactGateSource($root);
    writeUnaffectedPageNote($root, 'task-1', 'docs/reference/tasks.md: This branch changes only internal behavior.');

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['exceptions'])->toBe([]);
});

it('a committed docs/.docs-unaffected file does not waive an impacted page', function (): void {
    [$root, $base] = docsImpactGateFixture();
    file_put_contents($root.'/docs/.docs-unaffected', "docs/reference/tasks.md: The old committed waiver file.\n");
    exec('git -C '.escapeshellarg($root).' add docs/.docs-unaffected');
    changeDocsImpactGateSource($root);

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['missing_pages'])->toContain('docs/reference/tasks.md')
        ->and($gate['exceptions'])->toBe([]);
});

it('docs impact gate rejects unowned migration errors', function (): void {
    [$root, $base] = docsImpactGateFixture();
    mkdir($root.'/apps/gateway/database/migrations', 0777, true);
    $path = 'apps/gateway/database/migrations/2026_01_01_create_orphans.php';
    file_put_contents($root.'/'.$path, unownedMigrationContents());

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toContain("Unowned migration surface in {$path}; add a matching covers glob.");
});

it('docs impact gate rejects failed generators even when their pages are updated', function (string $path, string $contents, string $page, string $generator): void {
    [$root, $base] = docsImpactGateFixture();
    file_put_contents($root.'/'.$path, $contents);
    file_put_contents($root.'/'.$page, file_get_contents($root.'/'.$page)." Updated for this change.\n");

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toContain($generator.' generator status is missing.');
})->with([
    'MCP' => ['apps/gateway/resources/mcp/tools.json', '{"tools":[{"name":"new-tool","method":"GET","path":"/api/v1/widgets"}]}', 'docs/reference/mcp.mdx', 'bin/mcp-tools'],
    'OpenAPI' => ['docs/openapi.json', '{"openapi":"3.1.0","info":{"title":"Changed","version":"1"},"paths":{}}', 'docs/reference/tasks.md', 'bin/docs-openapi'],
]);

it('docs impact gate command exits nonzero for extractor errors and failed generators', function (string $path, string $contents, string $error): void {
    [$root, $base] = docsImpactGateFixture();
    if (str_contains($path, 'migrations/')) {
        mkdir(dirname($root.'/'.$path), 0777, true);
    }
    file_put_contents($root.'/'.$path, $contents);
    if ($path === 'apps/gateway/resources/mcp/tools.json') {
        file_put_contents($root.'/docs/reference/mcp.mdx', "---\ntitle: MCP\n---\nUpdated MCP documentation.\n");
    }

    [$exitCode, $output] = runDocsImpactGateCommand($root, $base);

    expect($exitCode)->toBe(1)->and($output)->toContain($error);
})->with([
    'migration error' => ['apps/gateway/database/migrations/2026_01_01_create_orphans.php', unownedMigrationContents(), 'Unowned migration surface'],
    'MCP generator failure' => ['apps/gateway/resources/mcp/tools.json', '{"tools":[{"name":"new-tool","method":"GET","path":"/api/v1/widgets"}]}', 'bin/mcp-tools generator status is missing.'],
    'OpenAPI generator failure' => ['docs/openapi.json', '{"openapi":"3.1.0","info":{"title":"Changed","version":"1"},"paths":{}}', 'bin/docs-openapi generator status is missing.'],
]);

it('docs impact gate passes when the impacted page is updated', function (): void {
    [$root, $base] = docsImpactGateFixture();
    changeDocsImpactGateSource($root);
    file_put_contents($root.'/docs/reference/tasks.md', "---\ntitle: Tasks\ncovers:\n  - \"apps/gateway/app/Domain/Tasks/**\"\n---\nUpdated tasks documentation.\n");

    $gate = new DocsImpact($root)->gate($base);

    expect($gate['passed'])->toBeTrue()
        ->and($gate['missing_pages'])->toBe([]);
});
