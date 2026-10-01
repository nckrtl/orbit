<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;
use App\Models\Tool;
use JsonException;
use stdClass;

/**
 * Reads the enrolled account's Vite+ global root packages.
 * The read takes no manager lock, refreshes no metadata, and creates no manager or Tool rows.
 */
final readonly class VpInventoryInspector
{
    public const int MAX_INVENTORY_BYTES = 1_048_576;

    private const int JSON_DEPTH = 64;

    private const int MAX_NAME_LENGTH = 255;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PROTECTED = 'pnpm';

    private BoundedJsonObject $json;

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private VpToolManager $vp,
        private SemverVersionNormalizer $versions,
    ) {
        $this->json = new BoundedJsonObject(self::MAX_INVENTORY_BYTES, self::JSON_DEPTH);
    }

    public function inspect(Node $node): ToolInventoryScan
    {
        if ($node->platform !== 'linux' && $node->platform !== 'macos') {
            return $this->blank(ToolInventoryScanState::Unsupported);
        }

        $toolIds = $this->toolIds($node);

        try {
            $binary = $this->vp->existingBinary($node);
        } catch (ToolManagerException $exception) {
            return $this->blank($this->stateFromScope($exception));
        }

        try {
            $result = $this->commands->execute(
                $node,
                $this->listCommand($binary),
                maxOutputBytes: self::MAX_INVENTORY_BYTES,
            );
            $packages = $this->packages($result->stdout, $result->succeeded(), $toolIds);
        } catch (ToolManagerException|JsonException) {
            return $this->blank(ToolInventoryScanState::Incomplete);
        }

        return new ToolInventoryScan(ToolManagerName::Vp, ToolInventoryScanState::Complete, $packages);
    }

    /** @return non-empty-list<string> */
    private function listCommand(string $binary): array
    {
        return [
            'env',
            'VP_HOME='.dirname($binary, 2),
            $binary,
            'list',
            '-g',
            '--json',
        ];
    }

    /**
     * @param  array<string, int>  $toolIds
     * @return list<ToolInventoryPackage>
     */
    private function packages(string $stdout, bool $succeeded, array $toolIds): array
    {
        if (! $succeeded) {
            throw new JsonException('The Vite+ global inventory probe failed.');
        }

        $decoded = $this->json->decodeList($stdout);
        $packages = [];
        $seen = [];

        foreach ($decoded as $entry) {
            if (! $entry instanceof stdClass) {
                throw new JsonException('The Vite+ global inventory was malformed.');
            }

            $name = $entry->name ?? null;
            $version = $entry->version ?? null;

            if (! is_string($name) || ! $this->isSafeName($name) || isset($seen[$name])) {
                throw new JsonException('The Vite+ global inventory was malformed.');
            }

            if (! is_string($version) || ! $this->isSafeVersion($version)) {
                throw new JsonException('The Vite+ global inventory was malformed.');
            }

            $seen[$name] = true;
            $manageable = $this->vp->validatePackage($name);
            $normalized = $this->versions->normalize($version);
            $toolId = $manageable ? ($toolIds[$name] ?? null) : null;
            $block = $this->block($name, $normalized, $manageable);
            $packages[] = new ToolInventoryPackage(
                manager: ToolManagerName::Vp,
                package: $name,
                packageKind: ToolInventoryPackageKind::Global,
                installedVersion: $normalized,
                dependency: false,
                registered: $toolId !== null,
                toolId: $toolId,
                adoption: $block === null ? ToolInventoryPackage::SUPPORTED : ToolInventoryPackage::UNSUPPORTED,
                adoptionBlock: $block,
            );
        }

        usort(
            $packages,
            static fn (ToolInventoryPackage $left, ToolInventoryPackage $right): int => $left->package <=> $right->package,
        );

        return $packages;
    }

    private function block(string $package, ?string $version, bool $manageable): ?string
    {
        if (! $manageable) {
            return ToolInventoryPackage::BLOCK_SOURCE;
        }

        if ($package === self::PROTECTED) {
            return ToolInventoryPackage::BLOCK_PROTECTED;
        }

        if ($version === null) {
            return ToolInventoryPackage::BLOCK_VERSION;
        }

        return null;
    }

    private function isSafeName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
    }

    private function isSafeVersion(string $version): bool
    {
        return $version !== ''
            && strlen($version) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1;
    }

    private function stateFromScope(ToolManagerException $exception): ToolInventoryScanState
    {
        return match ($exception->step) {
            'manager-absent' => ToolInventoryScanState::Absent,
            'manager-conflict' => ToolInventoryScanState::Conflicting,
            default => ToolInventoryScanState::Incomplete,
        };
    }

    private function blank(ToolInventoryScanState $state): ToolInventoryScan
    {
        return new ToolInventoryScan(ToolManagerName::Vp, $state, []);
    }

    /**
     * Existing Vite+ Tool rows for this Node. Discoveries are not inserted.
     *
     * @return array<string, int>
     */
    private function toolIds(Node $node): array
    {
        if (! $node->exists) {
            return [];
        }

        $tools = Tool::query()
            ->where('node_id', $node->id)
            ->whereHas('manager', static function ($query): void {
                $query->where('name', ToolManagerName::Vp->value);
            })
            ->with('manager')
            ->get();

        $ids = [];

        foreach ($tools as $tool) {
            $manager = $tool->manager;

            if ($manager->node_id !== $node->id || $manager->name !== ToolManagerName::Vp->value) {
                continue;
            }

            $ids[$tool->package] = $tool->id;
        }

        return $ids;
    }
}
