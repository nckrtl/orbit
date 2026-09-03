<?php

declare(strict_types=1);

use App\Librarian\Rules\ContextIndexFreshRule;
use App\Librarian\Rules\DecisionRecordLanguageRule;
use App\Librarian\Rules\DecisionRecordStructureRule;

return [
    'path' => base_path('../../docs'),

    'generated_docs' => [
        'enforce' => false,
    ],

    'rules' => [
        ContextIndexFreshRule::class,
        DecisionRecordStructureRule::class,
        DecisionRecordLanguageRule::class,
    ],
];
