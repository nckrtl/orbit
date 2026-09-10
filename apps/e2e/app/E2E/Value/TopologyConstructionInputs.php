<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class TopologyConstructionInputs
{
    public const int SCHEMA = 2;

    public const int LEGACY_SCHEMA = 1;

    public const string GENERIC_BASE = 'generic-base';

    /**
     * @param  array<string, array{source:string,instance:string,incus_address:string,wireguard_address:string}>  $nodes
     */
    private function __construct(
        public TopologyTarget $target,
        public string $sourceGeneration,
        public int $slot,
        public ?TopologyExtension $extension,
        public bool $snapshotReplacement,
        public ?string $imageAlias,
        public ?string $imageFingerprint,
        public array $nodes,
        public int $schema,
    ) {
        if (! in_array($schema, [self::LEGACY_SCHEMA, self::SCHEMA], true)) {
            throw new InvalidArgumentException('The topology construction input schema is invalid.');
        }
        if ($snapshotReplacement && $schema !== self::SCHEMA) {
            throw new InvalidArgumentException('Legacy construction inputs cannot declare snapshot replacement.');
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $sourceGeneration) !== 1) {
            throw new InvalidArgumentException('The topology construction source generation is invalid.');
        }
        if ($slot < 1 || $slot > 200) {
            throw new InvalidArgumentException('The topology construction slot is invalid.');
        }
        $usesBaseImage = $extension !== null || $snapshotReplacement;
        if ($usesBaseImage !== ($imageAlias !== null) || $usesBaseImage !== ($imageFingerprint !== null)) {
            throw new InvalidArgumentException('The topology construction image inputs are incomplete.');
        }
        if (
            $usesBaseImage
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) $imageFingerprint) !== 1
        ) {
            throw new InvalidArgumentException('The topology construction image input is invalid.');
        }
        if (
            ($extension !== null && $imageAlias !== TopologyRecipe::BASE_IMAGE)
            || ($snapshotReplacement
                && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', (string) $imageAlias) !== 1)
        ) {
            throw new InvalidArgumentException('The topology construction image input is invalid.');
        }
        if (
            $snapshotReplacement
            && ($extension !== null
            || $sourceGeneration !== self::GENERIC_BASE
            || $target->recipe->id !== TopologyProfile::NAME
            || $target->recipe->nodeKeys() !== TopologyProfile::ROLES
            || ! array_all(
                $target->recipe->nodes,
                static fn (TopologyNode $node): bool => $node->image === $imageAlias,
            ))
        ) {
            throw new InvalidArgumentException('The snapshot replacement construction declaration is invalid.');
        }
        if (! $snapshotReplacement && $sourceGeneration === self::GENERIC_BASE) {
            throw new InvalidArgumentException('Generic-base construction requires a snapshot replacement declaration.');
        }
        $this->assertNodes($target, $slot, $nodes);
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
            false,
            $extension === null ? null : TopologyRecipe::BASE_IMAGE,
            $imageFingerprint,
            $nodes,
            self::SCHEMA,
        );
    }

    public static function forSnapshotReplacement(
        TopologyTarget $target,
        int $slot,
        string $imageAlias,
        string $imageFingerprint,
    ): self {
        $nodes = [];
        foreach ($target->recipe->nodes as $node) {
            $nodes[$node->key] = [
                'source' => 'image',
                'instance' => $target->instance($node->key),
                'incus_address' => TopologyTarget::ipv4For($slot, $node->address),
                'wireguard_address' => $node->wireGuardAddress(),
            ];
        }

        return new self(
            $target,
            self::GENERIC_BASE,
            $slot,
            null,
            true,
            $imageAlias,
            $imageFingerprint,
            $nodes,
            self::SCHEMA,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = [
            'schema' => $this->schema,
            'issue' => $this->target->issue,
            'attempt_id' => $this->target->requireAttempt()->value,
            'extension' => $this->extension?->value,
        ];
        if ($this->schema === self::SCHEMA) {
            $value['snapshot_replacement'] = $this->snapshotReplacement;
        }

        return [
            ...$value,
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
        $schema = $value['schema'] ?? null;
        $expectedKeys = $schema === self::LEGACY_SCHEMA
            ? [
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
            : [
                'schema',
                'issue',
                'attempt_id',
                'extension',
                'snapshot_replacement',
                'source_generation',
                'slot',
                'image_alias',
                'image_fingerprint',
                'nodes',
            ];
        if (
            array_keys($value) !== $expectedKeys
            || ! in_array($schema, [self::LEGACY_SCHEMA, self::SCHEMA], true)
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || $value['extension'] !== null
            && ! is_string($value['extension'])
            || $schema === self::SCHEMA
            && ! is_bool($value['snapshot_replacement'])
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
        $snapshotReplacement = $schema === self::SCHEMA && $value['snapshot_replacement'];
        $recipe = $extension?->recipe()
            ?? ($snapshotReplacement && is_string($value['image_alias'])
                ? TopologyRecipe::registered($value['image_alias'])
                : TopologyRecipe::registered());
        $target = TopologyTarget::feature(
            $value['issue'],
            new AttemptId($value['attempt_id']),
            $recipe,
        );
        /** @var array<string, array{source:string,instance:string,incus_address:string,wireguard_address:string}> $nodes */
        $nodes = $value['nodes'];

        return new self(
            $target,
            $value['source_generation'],
            $value['slot'],
            $extension,
            $snapshotReplacement,
            $value['image_alias'],
            $value['image_fingerprint'],
            $nodes,
            $schema,
        );
    }

    /** @param array<string, array<array-key, mixed>> $nodes */
    private function assertNodes(TopologyTarget $target, int $slot, array $nodes): void
    {
        if (array_keys($nodes) !== $target->recipe->nodeKeys()) {
            throw new InvalidArgumentException('The topology construction Node inventory is incomplete.');
        }
        foreach ($nodes as $key => $node) {
            $recipeNode = $target->recipe->node($key);
            if (
                array_keys($node) !== ['source', 'instance', 'incus_address', 'wireguard_address']
                || ! in_array($node['source'], ['snapshot', 'image'], true)
                || $node['source'] !== ($this->snapshotReplacement || $key === 'app-prod-2' ? 'image' : 'snapshot')
                || $node['instance'] !== $target->instance($key)
                || $node['incus_address'] !== TopologyTarget::ipv4For($slot, $recipeNode->address)
                || $node['wireguard_address'] !== $recipeNode->wireGuardAddress()
            ) {
                throw new InvalidArgumentException("Topology construction Node [{$key}] is invalid.");
            }
        }
    }
}
