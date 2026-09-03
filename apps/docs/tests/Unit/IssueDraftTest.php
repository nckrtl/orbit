<?php

declare(strict_types=1);

use App\Documentation\BlockedPhrases;
use App\Documentation\IssueDraft;
use HardImpact\Librarian\Linting\Finding;

const VALID_ISSUE = <<<'MARKDOWN'
    Running `orbit instance:register` inside a worktree serves its files at a development hostname.

    ## Scope

    - In: `instance:register` and `instance:unregister` through Gateway, SDK, and CLI
    - Out: `instance:new` behavior

    ## Acceptance

    - [ ] Registering from a nested directory records the canonical top level. Proof: gateway Pest suite.
    - [x] Unregister leaves the checkout untouched. Proof: Incus registration proof.
    MARKDOWN;

function issueMessages(array $findings): array
{
    return array_map(
        static fn (Finding $finding): string => "{$finding->line} {$finding->rule}: {$finding->message}",
        $findings,
    );
}

beforeEach(function (): void {
    $this->draft = new IssueDraft(new BlockedPhrases(['should', 'later']));
});

it('accepts a leaf issue that follows the template', function (): void {
    expect($this->draft->findings('draft.md', VALID_ISSUE, false))->toBe([]);
});

it('accepts a parent without acceptance and an optional readiness section', function (): void {
    $parent = "Feature outcome.\n\n## Readiness\n\n- ADR 0019 accepted on origin/main\n\n## Scope\n\n- In: registration\n- Out: removal\n";

    expect($this->draft->findings('draft.md', $parent, true))->toBe([]);
});

it('rejects a leaf without acceptance and a draft without an outcome', function (): void {
    $noAcceptance = substr(VALID_ISSUE, 0, strpos(VALID_ISSUE, '## Acceptance'));
    $noOutcome = "## Scope\n\n- In: x\n\n## Acceptance\n\n- [ ] Y. Proof: z.\n";

    expect(issueMessages($this->draft->findings('draft.md', $noAcceptance, false)))
        ->toBe([
            '3 orbit.issue_structure: Expected H2 sections in order: Scope, Acceptance. Readiness is optional; a parent has no Acceptance.',
        ])
        ->and(issueMessages($this->draft->findings('draft.md', $noOutcome, false)))
        ->toBe(['1 orbit.issue_structure: The description must open with the outcome paragraph before any heading.']);
});

it('rejects malformed bullets and blocked phrases', function (): void {
    $draft = str_replace(
        [
            '- Out: `instance:new` behavior',
            '- [x] Unregister leaves the checkout untouched. Proof: Incus registration proof.',
        ],
        ['- Later: cleanup', '- [ ] Unregister should work'],
        VALID_ISSUE,
    );

    expect(issueMessages($this->draft->findings('draft.md', $draft, false)))->toBe([
        '6 orbit.issue_language: Blocked phrase `later`. Name the actor, the condition, and the observable result instead.',
        '11 orbit.issue_language: Blocked phrase `should`. Name the actor, the condition, and the observable result instead.',
        '6 orbit.issue_structure: Scope bullets are shaped `- In: <change>` or `- Out: <unchanged behavior>`.',
        '11 orbit.issue_structure: Acceptance items are shaped `- [ ] <criterion>. Proof: <action>.`.',
    ]);
});
