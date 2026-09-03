<?php

declare(strict_types=1);

use App\Librarian\Rules\DecisionRecordLanguageRule;
use App\Librarian\Rules\DecisionRecordStructureRule;
use HardImpact\Librarian\Docs\DocsConfig;
use HardImpact\Librarian\Docs\DocsFilesystem;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use Illuminate\Filesystem\Filesystem;

const VALID_RECORD = <<<'MARKDOWN'
    # ADR 0019: Register worktrees as instances

    ## Status

    Accepted on 2026-09-03. Extends ADR 0009.

    ## Context

    Operators keep checkouts that Orbit does not own.

    ## Decision

    - An AppInstance has one immutable source kind.
    - Orbit never mutates a registered checkout.

    ## Rejected alternatives

    - Adopt the worktree as managed source: Orbit would delete source it does not own.

    ## Consequences

    - Doctor must distinguish source kinds before this ships.

    ## Affects

    - Components: apps/gateway, apps/cli
    - ADRs: extends 0009
    - Detail: docs/reference/apps.md
    - Verify: apps/gateway Pest suite covers registration refusals
    MARKDOWN;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-adr-'.bin2hex(random_bytes(8));
    new Filesystem()->makeDirectory($this->root.'/decisions', 0777, true);
    $this->snapshot = new MarkdownSnapshot(new DocsFilesystem(new DocsConfig($this->root)));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

function writeRecord(string $root, string $name, string $contents): void
{
    file_put_contents("{$root}/decisions/{$name}", $contents);
}

/** @return list<string> */
function messages(array $findings): array
{
    return array_map(static fn (Finding $finding): string => $finding->message, $findings);
}

it('accepts a record that follows the template', function (): void {
    writeRecord($this->root, '0019-register-worktrees.md', VALID_RECORD);

    $rule = new DecisionRecordStructureRule($this->snapshot, 19, 600, ['apps/cli', 'apps/gateway']);

    expect($rule->check())->toBe([]);
});

it('exempts records numbered before the configured start', function (): void {
    writeRecord($this->root, '0018-legacy.md', "# ADR 0018: Legacy\n\n## Status\n\nAccepted.\n");
    writeRecord($this->root, 'README.md', "# Architecture decisions\n\nShould be ignored later.\n");

    $structure = new DecisionRecordStructureRule($this->snapshot, 19, 600, ['apps/cli', 'apps/gateway']);
    $language = new DecisionRecordLanguageRule($this->snapshot, 19, ['should', 'later']);

    expect($structure->check())->toBe([])->and($language->check())->toBe([]);
});

it('rejects a wrong section sequence', function (): void {
    $contents = str_replace(
        "## Rejected alternatives\n\n- Adopt the worktree as managed source: Orbit would delete source it does not own.\n\n",
        '',
        VALID_RECORD,
    );
    writeRecord($this->root, '0019-register-worktrees.md', $contents);

    $findings = new DecisionRecordStructureRule($this->snapshot, 19, 600, ['apps/cli', 'apps/gateway'])->check();

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->severity)
        ->toBe(FindingSeverity::Error)
        ->and($findings[0]->rule)
        ->toBe('orbit.adr_structure')
        ->and($findings[0]->path)
        ->toBe('docs/decisions/0019-register-worktrees.md')
        ->and($findings[0]->message)
        ->toContain('Status, Context, Decision, Rejected alternatives, Consequences, Affects');
});

it('rejects a title number that differs from the filename', function (): void {
    writeRecord($this->root, '0020-register-worktrees.md', VALID_RECORD);

    $findings = new DecisionRecordStructureRule($this->snapshot, 19, 600, ['apps/cli', 'apps/gateway'])->check();

    expect(messages($findings))->toContain('The H1 number `0019` must match the filename number.');
});

it('rejects an empty section, a malformed status, and missing affects fields', function (): void {
    $contents = str_replace(
        [
            'Accepted on 2026-09-03. Extends ADR 0009.',
            "- Doctor must distinguish source kinds before this ships.\n",
            '- Detail: docs/reference/apps.md',
        ],
        ['Accepted 3 September.', '', ''],
        VALID_RECORD,
    );
    writeRecord($this->root, '0019-register-worktrees.md', $contents);

    $messages = messages(
        new DecisionRecordStructureRule($this->snapshot, 19, 600, ['apps/cli', 'apps/gateway'])->check(),
    );

    expect($messages)
        ->toContain('Status must start with `Proposed.` or `Accepted on YYYY-MM-DD.`.')
        ->toContain('The required section [Consequences] is empty.')
        ->toContain('Affects must list `- Detail: <value>`.')
        ->toHaveCount(3);
});

it('rejects unknown components and accepts none', function (): void {
    writeRecord(
        $this->root,
        '0019-a.md',
        str_replace('- Components: apps/gateway, apps/cli', '- Components: apps/gateway, apps/web', VALID_RECORD),
    );
    writeRecord(
        $this->root,
        '0020-b.md',
        str_replace(
            ['ADR 0019', '- Components: apps/gateway, apps/cli'],
            ['ADR 0020', '- Components: none'],
            VALID_RECORD,
        ),
    );

    $findings = new DecisionRecordStructureRule($this->snapshot, 19, 600, ['apps/cli', 'apps/gateway'])->check();

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->path)
        ->toBe('docs/decisions/0019-a.md')
        ->and($findings[0]->message)
        ->toBe('Unknown component `apps/web`. Use `none` or a comma-separated subset of: apps/cli, apps/gateway.');
});

it('warns when a record exceeds the word target', function (): void {
    writeRecord($this->root, '0019-register-worktrees.md', VALID_RECORD);

    $findings = new DecisionRecordStructureRule($this->snapshot, 19, 20, ['apps/cli', 'apps/gateway'])->check();

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->severity)
        ->toBe(FindingSeverity::Warning)
        ->and($findings[0]->line)
        ->toBeNull()
        ->and($findings[0]->message)
        ->toContain('the target is 20');
});

it('rejects blocked phrases outside headings and code', function (): void {
    $contents = str_replace(
        "- Orbit never mutates a registered checkout.\n",
        "- Orbit should clean up later.\n- Use `--should-not-flag` and `later` in code.\n\n```text\nshould later\n```\n",
        VALID_RECORD,
    );
    writeRecord($this->root, '0019-register-worktrees.md', $contents);

    $findings = new DecisionRecordLanguageRule($this->snapshot, 19, ['should', 'later', 'etc.'])->check();

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->line)
        ->toBe(14)
        ->and($findings[0]->severity)
        ->toBe(FindingSeverity::Error)
        ->and($findings[0]->rule)
        ->toBe('orbit.adr_language')
        ->and(messages($findings))
        ->toBe([
            'Blocked phrase `should`. Name the actor, the condition, and the observable result instead.',
            'Blocked phrase `later`. Name the actor, the condition, and the observable result instead.',
        ]);
});

it('matches blocked phrases as whole words only', function (): void {
    $contents = str_replace(
        "- Orbit never mutates a registered checkout.\n",
        "- Shoulders and latergy are not phrases; some is.\n",
        VALID_RECORD,
    );
    writeRecord($this->root, '0019-register-worktrees.md', $contents);

    $findings = new DecisionRecordLanguageRule($this->snapshot, 19, ['should', 'later', 'some'])->check();

    expect(messages($findings))->toBe([
        'Blocked phrase `some`. Name the actor, the condition, and the observable result instead.',
    ]);
});

it('rejects a non-lowercase blocked phrase configuration', function (): void {
    new DecisionRecordLanguageRule($this->snapshot, 19, ['Should']);
})->throws(LogicException::class);
