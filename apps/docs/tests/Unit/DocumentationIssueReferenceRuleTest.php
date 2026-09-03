<?php

declare(strict_types=1);

use App\Librarian\Rules\DocumentationIssueReferenceRule;
use HardImpact\Librarian\Docs\DocsConfig;
use HardImpact\Librarian\Docs\DocsFilesystem;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-issue-ref-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($this->root.'/reference', 0777, true);
    $files->makeDirectory($this->root.'/decisions', 0777, true);
    $files->makeDirectory($this->root.'/generated', 0777, true);
    $this->rule = new DocumentationIssueReferenceRule(
        new MarkdownSnapshot(new DocsFilesystem(new DocsConfig($this->root))),
        ['NCK', 'ORB'],
    );
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

it('reports an issue identifier in prose', function (): void {
    file_put_contents($this->root.'/reference/apps.md', "# Apps\n\nORB-76 does not add a backfill command.\n");

    $findings = $this->rule->check();

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->path)
        ->toBe('docs/reference/apps.md')
        ->and($findings[0]->line)
        ->toBe(3)
        ->and($findings[0]->severity)
        ->toBe(FindingSeverity::Error)
        ->and($findings[0]->rule)
        ->toBe('orbit.docs_issue_reference')
        ->and($findings[0]->message)
        ->toContain('`ORB-76`');
});

it('accepts an identifier inside backticks, a heading, or a fenced block', function (): void {
    file_put_contents($this->root.'/reference/apps.md', <<<'MARKDOWN'
        # Proof NCK-73

        The plan `.loop/proof/NCK-73.json` re-enables the exporter.

        ```text
        bash .loop/proof/matrix.sh
        ```

        Orbit records the branch it selected.
        MARKDOWN);

    expect($this->rule->check())->toBe([]);
});

it('exempts decision records and generated files', function (): void {
    file_put_contents($this->root.'/decisions/0004-boundary.md', "# ADR 0004\n\nThe NCK-54 contract approved this.\n");
    file_put_contents($this->root.'/generated/index.md', "# Index\n\nORB-99 wrote this.\n");

    expect($this->rule->check())->toBe([]);
});

it('reports every page that names an issue, once per line', function (): void {
    file_put_contents($this->root.'/reference/a.md', "# A\n\nORB-1 and ORB-2 changed this.\n");
    file_put_contents($this->root.'/reference/b.md', "# B\n\nNCK-9 changed that.\n");

    expect(array_map(static fn (Finding $finding): string => $finding->path, $this->rule->check()))
        ->toEqualCanonicalizing(['docs/reference/a.md', 'docs/reference/b.md']);
});

it('matches only the configured prefixes', function (): void {
    file_put_contents($this->root.'/reference/apps.md', "# Apps\n\nOrbit uses SHA-256 and UTF-8 and ABC-12 here.\n");

    expect($this->rule->check())->toBe([]);
});

it('rejects a prefix that is not uppercase alphanumeric', function (): void {
    new DocumentationIssueReferenceRule(
        new MarkdownSnapshot(new DocsFilesystem(new DocsConfig($this->root))),
        ['nck'],
    );
})->throws(LogicException::class);
