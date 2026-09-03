<?php

declare(strict_types=1);

use App\Documentation\BlockedPhrases;
use App\Documentation\MarkdownProse;
use App\Librarian\Rules\DocumentationNarrativeRule;
use HardImpact\Librarian\Docs\DocsConfig;
use HardImpact\Librarian\Docs\DocsFilesystem;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-narrative-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($this->root.'/decisions', 0777, true);
    $files->makeDirectory($this->root.'/generated', 0777, true);
    $files->makeDirectory($this->root.'/reference', 0777, true);
    $this->rule = new DocumentationNarrativeRule(
        new MarkdownSnapshot(new DocsFilesystem(new DocsConfig($this->root))),
        new BlockedPhrases(['no longer', 'retired', 'deprecated']),
    );
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

it('accepts a page that states current behavior', function (): void {
    file_put_contents($this->root.'/reference/apps.md', "# Apps\n\nThe Gateway stores the repository URL.\n");

    expect($this->rule->check())->toBe([]);
});

it('rejects change narration outside decisions and generated files', function (): void {
    file_put_contents(
        $this->root.'/reference/apps.md',
        "# Apps\n\nThe directory is no longer read.\n\n- The retired file is copied once.\n",
    );
    file_put_contents($this->root.'/decisions/0009-old.md', "# ADR 0009: Old\n\nThis is no longer the case.\n");
    file_put_contents($this->root.'/generated/index.md', "# Index\n\nretired\n");

    $findings = $this->rule->check();

    expect(array_map(
        static fn (Finding $finding): string => "{$finding->path}:{$finding->line} {$finding->message}",
        $findings,
    ))
        ->toBe([
            'docs/reference/apps.md:3 Blocked phrase `no longer`. Name the actor, the condition, and the observable result instead.',
            'docs/reference/apps.md:5 Blocked phrase `retired`. Name the actor, the condition, and the observable result instead.',
        ])
        ->and($findings[0]->severity)
        ->toBe(FindingSeverity::Error)
        ->and($findings[0]->rule)
        ->toBe('orbit.docs_narrative');
});

it('ignores headings, inline code, and backtick or tilde fences', function (): void {
    file_put_contents($this->root.'/reference/apps.md', <<<'MARKDOWN'
        # Retired options

        Use `--deprecated` to list them.

        ```text
        no longer
        ```

        ~~~
        retired
        ~~~

        The Gateway refuses a deprecated option.
        MARKDOWN);

    $findings = $this->rule->check();

    expect(array_map(static fn (Finding $finding): int => (int) $finding->line, $findings))->toBe([13]);
});

it('splits prose lines outside both fence styles', function (): void {
    $lines = MarkdownProse::lines("a\n```\nb\n```\nc\n~~~sh\nd\n~~~\ne");

    expect($lines)->toBe([1 => 'a', 5 => 'c', 9 => 'e']);
});
