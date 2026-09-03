<?php

declare(strict_types=1);

use App\Librarian\Rules\ContextIndexFreshRule;
use App\Librarian\Rules\DecisionRecordLanguageRule;
use App\Librarian\Rules\DecisionRecordStructureRule;
use App\Librarian\Rules\DocumentationNarrativeRule;
use HardImpact\Librarian\Linting\Rules\BulletComplexityRule;
use HardImpact\Librarian\Linting\Rules\CompoundNounStackRule;
use HardImpact\Librarian\Linting\Rules\DocumentComplexityRule;
use HardImpact\Librarian\Linting\Rules\LongSectionStructureRule;
use HardImpact\Librarian\Linting\Rules\RequirementSmellRule;
use HardImpact\Librarian\Linting\Rules\SectionOpenerProseRule;
use HardImpact\Librarian\Linting\Rules\SentenceCaseHeadingRule;
use HardImpact\Librarian\Linting\Rules\TableProseComplexityRule;

return [
    'path' => base_path('../../docs'),

    'generated_docs' => [
        'enforce' => false,
    ],

    'rules' => [
        ContextIndexFreshRule::class,
        DecisionRecordStructureRule::class,
        DecisionRecordLanguageRule::class,
        DocumentationNarrativeRule::class,
        RequirementSmellRule::class,
        SentenceCaseHeadingRule::class,
        DocumentComplexityRule::class,
        BulletComplexityRule::class,
        LongSectionStructureRule::class,
        SectionOpenerProseRule::class,
        CompoundNounStackRule::class,
        TableProseComplexityRule::class,
    ],
];
