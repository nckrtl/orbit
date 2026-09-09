<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity The persisted construction schema validates every bound input. */
final readonly class TopologyConstructionInputs
{
    public const int SCHEMA = 1;

    /**
     * @param array<string, array{source:string,instance:string,incus_address:string,wireguard_address:string}> $nodes
     * @mago-expect lint:excessive-parameter-list Each persisted construction input is an independent proof binding.
     */
    private function __construct(
        public TopologyTarget $target,
        public string $sourceGeneration,
        public int $slot,
        public ?TopologyExtension $extension,
        public ?string $imageAlias,
        public ?string $imageFingerprint,
        public array $nodes,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $sourceGeneration) !== 1) {
            throw new InvalidArgumentException('The topology construction source generation is invalid.');
        }
        if ($slot < 1 || $slot > 200) {
            throw new InvalidArgumentException('The topology construction slot is invalid.');
        }
        if (
            ($extension === null) !== ($imageAlias === null)
            || ($extension === null) !== ($imageFingerprint === null)
        ) {
            throw new InvalidArgumentException('The topology construction image inputs are incomplete.');
        }
        if (
            $extension !== null
            && ($imageAlias !== TopologyRecipe::BASE_IMAGE
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $imageFingerprint) !== 1)
        ) {
            throw new InvalidArgumentException('The topology construction image input is invalid.');
        }
        if (array_keys($nodes) !== $target->recipe->nodeKeys()) {
            throw new InvalidArgumentException('The topology construction Node inventory is incomplete.');
        }
        foreach ($nodes as $key => $node) {
            $recipeNode = $target->recipe->node($key);
            if (
                array_keys($node) !== ['source', 'instance', 'incus_address', 'wireguard_address']
                || ! in_array($node['source'], ['snapshot', 'image'], true)
                || $node['source'] !== ($key === 'app-prod-2' ? 'image' : 'snapshot')
                || $node['instance'] !== $target->instance($key)
                || $node['incus_address'] !== TopologyTarget::ipv4For($slot, $recipeNode->address)
                || $node['wireguard_address'] !== $recipeNode->wireGuardAddress()
            ) {
                throw new InvalidArgumentException("Topology construction Node [{$key}] is invalid.");
            }
        }
    }

    public static function create(
        TopologyTarget $target,
        TopologySnapshotGeneration $generation,
        int $slot,
        ?TopologyExtension $extension = null,
        ?string $imageFingerprint = null,
    ): self {
        return self::forGeneration(
            $target,
            $generation->id,
            $slot,
            $extension,
            $imageFingerprint,
        );
    }

    public static function forGeneration(
        TopologyTarget $target,
        string $generationId,
        int $slot,
        ?TopologyExtension $extension = null,
        ?string $imageFingerprint = null,
    ): self {
        $nodes = [];
        foreach ($target->recipe->nodes as $node) {
            $nodes[$node->key] = [
                'source' => $node->key === 'app-prod-2' ? 'image' : 'snapshot',
                'instance' => $target->instance($node->key),
                'incus_address' => TopologyTarget::ipv4For($slot, $node->address),
                'wireguard_address' => $node->wireGuardAddress(),
            ];
        }

        return new self(
            $target,
            $generationId,
            $slot,
            $extension,
            $extension === null ? null : TopologyRecipe::BASE_IMAGE,
            $imageFingerprint,
            $nodes,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->target->issue,
            'attempt_id' => $this->target->requireAttempt()->value,
            'extension' => $this->extension?->value,
            'source_generation' => $this->sourceGeneration,
            'slot' => $this->slot,
            'image_alias' => $this->imageAlias,
            'image_fingerprint' => $this->imageFingerprint,
            'nodes' => $this->nodes,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'issue',
                'attempt_id',
                'extension',
                'source_generation',
                'slot',
                'image_alias',
                'image_fingerprint',
                'nodes',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || $value['extension'] !== null
            && ! is_string($value['extension'])
            || ! is_string($value['source_generation'])
            || ! is_int($value['slot'])
            || $value['image_alias'] !== null
            && ! is_string($value['image_alias'])
            || $value['image_fingerprint'] !== null
            && ! is_string($value['image_fingerprint'])
            || ! is_array($value['nodes'])
        ) {
            throw new InvalidArgumentException('The topology construction input schema is invalid.');
        }
        $extension = is_string($value['extension']) ? TopologyExtension::tryFrom($value['extension']) : null;
        if ($value['extension'] !== null && $extension === null) {
            throw new InvalidArgumentException('The topology construction extension is invalid.');
        }
        $target = TopologyTarget::feature(
            $value['issue'],
            new AttemptId($value['attempt_id']),
            $extension?->recipe() ?? TopologyRecipe::registered(),
        );
        /** @var array<string, array{source:string,instance:string,incus_address:string,wireguard_address:string}> $nodes */
        $nodes = $value['nodes'];

        return new self(
            $target,
            $value['source_generation'],
            $value['slot'],
            $extension,
            $value['image_alias'],
            $value['image_fingerprint'],
            $nodes,
        );
    }
}
