<?php

declare(strict_types=1);

return [
    'index_path' => base_path('../../docs/generated/context.json'),

    'ignored_librarian_rules' => [
        // Orbit's introductory pages use reader-focused headings instead of
        // Librarian's fixed Why / How / What / Boundaries templates.
        'librarian.core_docs_structure',
    ],

    // Architecture decision records numbered at or above `from_number` must
    // follow the template in .agents/skills/recording-decisions/template.md and
    // avoid the blocked phrases. Earlier accepted records are immutable and
    // exempt.
    'decision_records' => [
        'from_number' => 19,
        'word_limit' => 600,
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
