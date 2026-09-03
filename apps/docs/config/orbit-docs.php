<?php

declare(strict_types=1);

return [
    'index_path' => base_path('../../docs/generated/context.json'),

    'ignored_librarian_rules' => [
        // Orbit's introductory pages use reader-focused headings instead of
        // Librarian's fixed Why / How / What / Boundaries templates.
        'librarian.core_docs_structure',
    ],

    // Prose rules that do not apply to accepted decision records numbered
    // below decision_records.from_number, because those records are immutable.
    'legacy_decision_rules' => [
        'librarian.requirement_smell',
        'librarian.sentence_case_heading',
        'librarian.long_section_structure',
        'librarian.compound_noun_stack',
        'librarian.table_prose_complexity',
    ],

    // Prose rules that never apply to a decision record: the ADR template opens
    // its sections with bullets and summarises the decision in one sentence.
    'decision_ignored_rules' => [
        'librarian.section_opener_prose',
        'librarian.bullet_complexity',
        'librarian.document_complexity',
    ],

    // Change narration is rejected in every maintained page outside
    // docs/decisions. A page states current behavior; history lives in ADRs.
    'narrative_phrases' => [
        'deprecated',
        'historical',
        'historically',
        'no longer',
        'previously',
        'formerly',
        'retired',
        'pre-rename',
        'transitional',
    ],

    // Architecture decision records numbered at or above `from_number` must
    // follow the template in .agents/skills/recording-decisions/template.md and
    // avoid the blocked phrases. Earlier accepted records are immutable and
    // exempt. `orbit:issue-lint` applies the same phrases to an issue draft.
    'decision_records' => [
        'from_number' => 20,
        'blocked_phrases' => [
            'appropriate',
            'reasonable',
            'sufficient',
            'proper',
            'relevant',
            'as needed',
            'as necessary',
            'if possible',
            'when possible',
            'where applicable',
            'etc.',
            'and/or',
            'should',
            'might',
            'could',
            'generally',
            'typically',
            'usually',
            'often',
            'some',
            'various',
            'best effort',
            'best-effort',
            'later',
            'future',
            'currently',
            'for now',
            'simple',
            'easy',
            'tbd',
        ],
    ],

    'components' => [
        'apps/cli',
        'apps/docs',
        'apps/e2e',
        'apps/gateway',
        'packages/php-sdk',
    ],
];
