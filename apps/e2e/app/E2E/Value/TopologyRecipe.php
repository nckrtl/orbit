<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

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
        $this->nodesByKey = $this->validateNodes($id, $nodes);
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

    public static function extendedAppProd(string $image = self::BASE_IMAGE): self
    {
        $registered = self::registered($image);

        return new self(TopologyProfile::NAME.'_app-prod', [
            ...$registered->nodes,
            new TopologyNode(
                'app-prod-2',
                $image,
                TopologyNodePurpose::Extension,
                13,
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

    /** @return array{id:string,nodes:list<array{key:string,image:string,purpose:string,address:int,checkout:bool,roles:list<string>}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nodes' => array_map(static fn (TopologyNode $node): array => [
                'key' => $node->key,
                'image' => $node->image,
                'purpose' => $node->purpose->value,
                'address' => $node->address,
                'checkout' => $node->checkout,
                'roles' => $node->roles,
            ], $this->nodes),
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== ['id', 'nodes'] || ! is_string($value['id']) || ! is_array($value['nodes'])) {
            throw new InvalidArgumentException('The topology recipe schema is invalid.');
        }

        $nodes = [];
        foreach ($value['nodes'] as $node) {
            if (
                ! is_array($node)
                || array_keys($node) !== ['key', 'image', 'purpose', 'address', 'checkout', 'roles']
                || ! is_string($node['key'])
                || ! is_string($node['image'])
                || ! is_string($node['purpose'])
                || ! is_int($node['address'])
                || ! is_bool($node['checkout'])
                || ! is_array($node['roles'])
                || ! array_all($node['roles'], static fn (mixed $role): bool => is_string($role))
            ) {
                throw new InvalidArgumentException('A topology recipe Node schema is invalid.');
            }
            $purpose = TopologyNodePurpose::tryFrom($node['purpose']);
            if ($purpose === null) {
                throw new InvalidArgumentException('A topology recipe Node purpose is invalid.');
            }
            /** @var list<string> $roles */
            $roles = $node['roles'];
            $nodes[] = new TopologyNode(
                $node['key'],
                $node['image'],
                $purpose,
                $node['address'],
                $node['checkout'],
                $roles,
            );
        }

        return new self($value['id'], $nodes);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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

    public function node(string $key): TopologyNode
    {
        return $this->nodesByKey[$key] ?? throw new InvalidArgumentException("Topology recipe Node [{$key}] is absent.");
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

    /** @return list<TopologyNode> */
    public function nodesForRole(string $role): array
    {
        return array_values(array_filter(
            $this->nodes,
            static fn (TopologyNode $node): bool => in_array($role, $node->roles, true),
        ));
    }

    public function resolveNode(string $nodeOrRole): TopologyNode
    {
        return $this->hasNode($nodeOrRole) ? $this->node($nodeOrRole) : $this->nodeForRole($nodeOrRole);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return array<string, TopologyNode>
     */
    private function validateNodes(string $id, array $nodes): array
    {
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
        foreach ($nodes as $node) {
            foreach ($node->roles as $role) {
                if (
                    isset($nodesByKey[$role])
                    && $role !== $node->key
                    && ! in_array($role, $nodesByKey[$role]->roles, true)
                ) {
                    throw new InvalidArgumentException(
                        "Topology recipe Node key [{$role}] collides with a role assigned to another Node.",
                    );
                }
            }
        }

        return $nodesByKey;
    }
}
