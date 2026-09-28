<?php

declare(strict_types=1);

namespace Orbit\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node> */
final class NoInlineVarOverrideRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $scope->isInClass() || ! $node instanceof Stmt\Expression) {
            return [];
        }

        $docComment = $node->getDocComment();
        if ($docComment === null || preg_match('/@(var|phpstan-var|psalm-var)\b/', $docComment->getText()) !== 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Inline @var overrides an inferred type inside a method body.')
                ->line($docComment->getStartLine())
                ->identifier('orbit.inlineVarOverride')
                ->build(),
        ];
    }
}
