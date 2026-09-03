<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\DocumentationContextIndexBuilder;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ContextIndexFreshRule implements GroupedRule
{
    private const string RULE = 'orbit.context_index_fresh';

    public function __construct(
        private DocumentationContextIndexBuilder $builder,
    ) {}

    public function group(): string
    {
        return 'generation';
    }

    public function check(): array
    {
        if ($this->builder->isFresh()) {
            return [];
        }

        return [
            new Finding(
                path: 'docs/generated/context.json',
                line: null,
                severity: FindingSeverity::Error,
                rule: self::RULE,
                message: 'Documentation context index is stale. Run `composer docs-build` from the repository root.',
            ),
        ];
    }
}
