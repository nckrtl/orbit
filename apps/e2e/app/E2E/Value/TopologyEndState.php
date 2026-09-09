<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The topology a proof plan declares it ends with: the nodes Orbit must still
 * know when verification runs.
 *
 * A plan that removes a node leaves it out of `nodes`. Verification then still
 * runs in full against the declared set. Only the probes that run *on* the
 * declared-absent node are skipped, and they are named in the proof result.
 * Every fleet probe still runs, told which nodes to expect, so the declaration
 * is checked in both directions: a node declared absent that is still
 * registered fails `role.assignments`, and a node removed without a
 * declaration fails the probes that read it.
 *
 * The gateway holds the registry every other probe reads, so it can never be
 * declared absent.
 *
 * The declaration speaks about Orbit's node registry only. The Incus instances
 * of all three roles still exist and are still checked for network identity;
 * `release` remains the only thing that removes a VM.
 */
final readonly class TopologyEndState
{
    /** The gateway holds the registry every other probe reads; it can never be declared absent. */
    public const string REQUIRED_ROLE = 'gateway';

    private const string NODES = 'nodes';

    /**
     * @param list<string> $nodes The declared-present roles, in profile order.
     * @param list<string> $recipeNodes
     */
    private function __construct(
        public array $nodes,
        private array $recipeNodes,
    ) {}

    /** The implicit declaration of a plan that says nothing: every node of the profile stays. */
    public static function complete(?TopologyRecipe $recipe = null): self
    {
        $nodes = ($recipe ?? TopologyRecipe::registered())->nodeKeys();

        return new self($nodes, $nodes);
    }

    public static function fromArray(mixed $declared, ?TopologyRecipe $recipe = null): self
    {
        $recipeNodes = ($recipe ?? TopologyRecipe::registered())->nodeKeys();
        if (! is_array($declared) || array_keys($declared) !== [self::NODES]) {
            throw new InvalidArgumentException(
                'The proof plan key ends_with must be an object with exactly the key nodes.',
            );
        }
        /** @var mixed $nodes */
        $nodes = $declared[self::NODES];
        if (! is_array($nodes) || ! array_is_list($nodes) || $nodes === []) {
            throw new InvalidArgumentException('The proof plan key ends_with.nodes must be a non-empty list.');
        }
        $declaredNodes = [];
        /** @mago-expect analysis:mixed-assignment Each declared node is validated before it joins the set. */
        foreach ($nodes as $node) {
            if (! is_string($node) || ! in_array($node, $recipeNodes, strict: true)) {
                throw new InvalidArgumentException(
                    'The proof plan key ends_with.nodes must name nodes from '.implode(', ', $recipeNodes).'.',
                );
            }
            if (in_array($node, $declaredNodes, strict: true)) {
                throw new InvalidArgumentException("The proof plan declares node [{$node}] in ends_with twice.");
            }
            $declaredNodes[] = $node;
        }
        if (! in_array(self::REQUIRED_ROLE, $declaredNodes, strict: true)) {
            throw new InvalidArgumentException(
                'The proof plan key ends_with.nodes must keep the '.self::REQUIRED_ROLE.' node.',
            );
        }

        // Profile order, so the record and the skip set never depend on how the plan was written.
        return new self(self::inRecipeOrder($declaredNodes, $recipeNodes), $recipeNodes);
    }

    /** @return list<string> The roles the plan declares gone, in profile order. */
    public function absent(): array
    {
        return self::inRecipeOrder(array_values(array_diff($this->recipeNodes, $this->nodes)), $this->recipeNodes);
    }

    /** @return list<string> The declared-present roles other than the gateway, in profile order. */
    public function peers(): array
    {
        return self::inRecipeOrder(array_values(array_diff($this->nodes, [self::REQUIRED_ROLE])), $this->recipeNodes);
    }

    public function declaresAbsence(): bool
    {
        return $this->absent() !== [];
    }

    public function keeps(string $role): bool
    {
        return in_array($role, $this->nodes, strict: true);
    }

    /** @return list<string> */
    public function recipeNodes(): array
    {
        return $this->recipeNodes;
    }

    /** @return array{nodes:list<string>} */
    public function toArray(): array
    {
        return [self::NODES => $this->nodes];
    }

    /**
     * @param list<string> $roles
     * @param list<string> $recipeNodes
     * @return list<string>
     */
    private static function inRecipeOrder(array $roles, array $recipeNodes): array
    {
        return array_values(array_filter(
            $recipeNodes,
            static fn (string $role): bool => in_array($role, $roles, strict: true),
        ));
    }
}
