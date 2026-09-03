<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity,too-many-methods The recipe owns validation and all physical Node lookups. */
final readonly class TopologyRecipe
{
    public const string BASE_IMAGE = 'orbit-base-ubuntu-26.04-runtime';

    /** @var array<string, TopologyNode> */
    private array $nodesByKey;

    /** @param list<TopologyNode> $nodes */
    public function __construct(
        public string $id,
        public array $nodes,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,62}\z/D', $id) !== 1 || $nodes === [] || ! array_is_list($nodes)) {
            throw new InvalidArgumentException('The topology recipe identity or Node inventory is invalid.');
        }

        $nodesByKey = [];
        $addresses = [];
        foreach ($nodes as $node) {
            if (! $node instanceof TopologyNode) {
                throw new InvalidArgumentException('Every topology recipe entry must be a Node.');
            }
            if (isset($nodesByKey[$node->key])) {
                throw new InvalidArgumentException('Topology recipe Node keys must be unique.');
            }
            if (isset($addresses[$node->address])) {
                throw new InvalidArgumentException('Topology recipe Node address positions must be unique.');
            }
            $nodesByKey[$node->key] = $node;
            $addresses[$node->address] = true;
        }
        $this->nodesByKey = $nodesByKey;
    }

    public static function registered(string $image = self::BASE_IMAGE): self
    {
        return new self(TopologyProfile::NAME, [
            new TopologyNode(
                'gateway',
                $image,
                TopologyNodePurpose::Gateway,
                10,
                true,
                TopologyProfile::ASSIGNMENTS['gateway'],
            ),
            new TopologyNode(
                'app-dev',
                $image,
                TopologyNodePurpose::Operator,
                11,
                true,
                TopologyProfile::ASSIGNMENTS['app-dev'],
            ),
            new TopologyNode(
                'app-prod',
                $image,
                TopologyNodePurpose::Workload,
                12,
                false,
                TopologyProfile::ASSIGNMENTS['app-prod'],
            ),
        ]);
    }

    public static function coldAcceptance(string $image = self::BASE_IMAGE): self
    {
        return new self('cold-acceptance', [
            new TopologyNode('gateway', $image, TopologyNodePurpose::Gateway, 10, true, ['gateway', 'vpn']),
            new TopologyNode('operator', $image, TopologyNodePurpose::Operator, 11, true, ['app-dev', 'metrics']),
            new TopologyNode('app-prod', $image, TopologyNodePurpose::Workload, 12, false, ['app-prod']),
            new TopologyNode('extra', $image, TopologyNodePurpose::Extension, 13, false, []),
        ]);
    }

    /** @return list<string> */
    public function nodeKeys(): array
    {
        return array_keys($this->nodesByKey);
    }

    /** @return list<string> */
    public function checkoutNodeKeys(): array
    {
        return array_values(array_map(
            static fn (TopologyNode $node): string => $node->key,
            array_filter($this->nodes, static fn (TopologyNode $node): bool => $node->checkout),
        ));
    }

    /** @return array<string, list<string>> */
    public function assignments(): array
    {
        $assignments = [];
        foreach ($this->nodes as $node) {
            $assignments[$node->key] = $node->roles;
        }

        return $assignments;
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodesByKey[$key]);
    }

    public function hasSameDefinition(self $recipe): bool
    {
        if ($this->id !== $recipe->id || count($this->nodes) !== count($recipe->nodes)) {
            return false;
        }

        foreach ($this->nodes as $index => $node) {
            $candidate = $recipe->nodes[$index];
            if (
                $node->key !== $candidate->key
                || $node->image !== $candidate->image
                || $node->purpose !== $candidate->purpose
                || $node->address !== $candidate->address
                || $node->checkout !== $candidate->checkout
                || $node->roles !== $candidate->roles
            ) {
                return false;
            }
        }

        return true;
    }

    public function node(string $key): TopologyNode
    {
        return (
            $this->nodesByKey[$key] ?? throw new InvalidArgumentException("Topology recipe Node [{$key}] is absent.")
        );
    }

    public function nodeForRole(string $role): TopologyNode
    {
        $matches = array_values(array_filter(
            $this->nodes,
            static fn (TopologyNode $node): bool => in_array($role, $node->roles, true),
        ));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException("Topology role [{$role}] must resolve to exactly one physical Node.");
        }

        return $matches[0];
    }

    public function resolveNode(string $nodeOrRole): TopologyNode
    {
        return $this->hasNode($nodeOrRole) ? $this->node($nodeOrRole) : $this->nodeForRole($nodeOrRole);
    }
}
