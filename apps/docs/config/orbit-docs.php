<?php

declare(strict_types=1);

return [
    'index_path' => base_path('../../docs/generated/context.json'),

    'ignored_librarian_rules' => [
        // Orbit's introductory pages use reader-focused headings instead of
        // Librarian's fixed Why / How / What / Boundaries templates.
        'librarian.core_docs_structure',
    ],

    'components' => [
        'apps/cli',
        'apps/docs',
        'apps/e2e',
        'apps/gateway',
        'packages/php-sdk',
    ],
];
