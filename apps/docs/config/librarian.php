<?php

declare(strict_types=1);

use App\Librarian\Rules\ContextIndexFreshRule;

return [
    'path' => base_path('../../docs'),

    'generated_docs' => [
        'enforce' => false,
    ],

    'rules' => [
        ContextIndexFreshRule::class,
    ],
];
