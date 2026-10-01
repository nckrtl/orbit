<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\HomebrewCaskDiscovery;
use App\Domain\Tools\HomebrewPackageName;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\Tool;
use JsonException;
use stdClass;

/**
 * Reads installed Homebrew formulae and casks for the enrolled account.
 * The read takes no manager lock, refreshes no metadata, and creates no manager or Tool rows.
 */
final readonly class HomebrewInventoryInspector
{
    private const int JSON_DEPTH = 64;

    private const int MAX_NAME_LIST_BYTES = 131_072;

    private const int MAX_VERSION_LENGTH = 255;

    /** @var list<string> */
    private const array PROTECTED = ['wireguard-go', 'wireguard-tools'];

    private HomebrewMacCommand $mac;

    private BoundedJsonObject $json;

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private SemverVersionNormalizer $versions,
        private HomebrewToolManager $formulae,
        private HomebrewCaskToolManager $casks,
        ?HomebrewMacCommand $mac = null,
    ) {
        $this->mac = $mac ?? new HomebrewMacCommand($commands);
        $this->json = new BoundedJsonObject(HomebrewCaskToolManager::MAX_INVENTORY_LENGTH, self::JSON_DEPTH);
    }

    /**
     * @return list<ToolInventoryScan>
     */
    public function inspect(Node $node): array
    {
        $toolIds = $this->toolIds($node);

        if ($node->platform !== 'linux' && $node->platform !== 'macos') {
            return [
                $this->blank(ToolManagerName::Brew, ToolInventoryScanState::Unsupported),
                $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Unsupported),
            ];
        }

        if ($node->platform === 'linux') {
            return [
                $this->linuxFormulae($node, $toolIds),
                $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Unsupported),
            ];
        }

        return $this->macInventories($node, $toolIds);
    }

    /**
     * @param  array<string, int>  $toolIds
     */
    private function linuxFormulae(Node $node, array $toolIds): ToolInventoryScan
    {
        try {
            $prefix = $this->formulae->ownedPrefix($node);
        } catch (ToolManagerException $exception) {
            return $this->blank(ToolManagerName::Brew, $this->stateFromPrefix($exception));
        }

        try {
            $bottleTag = $this->linuxBottleTag($node);
        } catch (ToolManagerException) {
            return $this->blank(ToolManagerName::Brew, ToolInventoryScanState::Incomplete);
        }

        return $this->formulaScan($node, $prefix, $bottleTag, $toolIds);
    }

    /**
     * @param  array<string, int>  $toolIds
     * @return list<ToolInventoryScan>
     */
    private function macInventories(Node $node, array $toolIds): array
    {
        try {
            $scope = $this->mac->resolveScope($node);
            $prefix = $scope->prefix;
            $home = $scope->home;
        } catch (ToolManagerException $exception) {
            $state = $this->stateFromPrefix($exception);

            return [
                $this->blank(ToolManagerName::Brew, $state),
                $this->blank(ToolManagerName::BrewCask, $state),
            ];
        }

        try {
            $bottleTag = $this->mac->bottleTag($node);
        } catch (ToolManagerException) {
            return [
                $this->blank(ToolManagerName::Brew, ToolInventoryScanState::Incomplete),
                $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Incomplete),
            ];
        }

        return [
            $this->formulaScan($node, $prefix, $bottleTag, $toolIds),
            $this->caskScan($node, $prefix, $home, $bottleTag, $toolIds),
        ];
    }

    /**
     * @param  array<string, int>  $toolIds
     */
    private function formulaScan(Node $node, string $prefix, string $bottleTag, array $toolIds): ToolInventoryScan
    {
        try {
            $result = $this->commands->execute(
                $node,
                $this->formulae->commandForPrefix($node, $prefix, false, [
                    'info',
                    '--json=v2',
                    '--formula',
                    '--installed',
                ]),
                maxOutputBytes: HomebrewCaskToolManager::MAX_INVENTORY_LENGTH,
            );
            $packages = $this->formulaPackages($result, $bottleTag, $toolIds);
            $packages = $this->reconcileListedNames($node, $prefix, true, $packages, $toolIds);
        } catch (ToolManagerException) {
            return $this->blank(ToolManagerName::Brew, ToolInventoryScanState::Incomplete);
        }

        return new ToolInventoryScan(ToolManagerName::Brew, ToolInventoryScanState::Complete, $packages);
    }

    /**
     * @param  array<string, int>  $toolIds
     */
    private function caskScan(Node $node, string $prefix, ?string $home, string $bottleTag, array $toolIds): ToolInventoryScan
    {
        try {
            $result = $this->commands->execute(
                $node,
                $this->mac->command($prefix, false, [
                    'info',
                    '--json=v2',
                    '--cask',
                    '--installed',
                ]),
                maxOutputBytes: HomebrewCaskToolManager::MAX_INVENTORY_LENGTH,
            );

            if (! $result->succeeded()) {
                return $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Incomplete);
            }

            $discoveries = $this->casks->interpretInstalledInventory($prefix, $home, $bottleTag, $result);
        } catch (ToolManagerException) {
            return $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Incomplete);
        }

        $packages = [];

        foreach ($discoveries as $discovery) {
            $packages[] = $this->caskPackage($discovery, $toolIds);
        }

        try {
            $packages = $this->reconcileListedNames($node, $prefix, false, $packages, $toolIds);
        } catch (ToolManagerException) {
            return $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Incomplete);
        }

        return new ToolInventoryScan(ToolManagerName::BrewCask, ToolInventoryScanState::Complete, $packages);
    }

    /**
     * @param  array<string, int>  $toolIds
     * @return list<ToolInventoryPackage>
     */
    private function formulaPackages(CommandResult $result, string $bottleTag, array $toolIds): array
    {
        if (! $result->succeeded() || $result->truncated) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew formula inventory probe failed.',
                result: $result,
            );
        }

        try {
            $decoded = $this->json->decode($result->stdout);
        } catch (JsonException $exception) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew formula inventory was malformed.',
                result: $result,
                previous: $exception,
            );
        }

        if (! is_array($decoded->formulae ?? null) || ($decoded->casks ?? null) !== []) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew formula inventory was malformed.',
                result: $result,
            );
        }

        $packages = [];
        $seen = [];

        foreach ($decoded->formulae as $formula) {
            if (! $formula instanceof stdClass || ! is_string($formula->name ?? null) || ! $this->validPackage($formula->name)) {
                throw new ToolManagerException(
                    step: 'inventory',
                    message: 'The Homebrew formula inventory was malformed.',
                    result: $result,
                );
            }

            if (isset($seen[$formula->name])) {
                throw new ToolManagerException(
                    step: 'inventory',
                    message: 'The Homebrew formula inventory was malformed.',
                    result: $result,
                );
            }

            $seen[$formula->name] = true;
            $packages[] = $this->formulaPackage(
                $formula,
                $formula->name,
                $bottleTag,
                $toolIds[$this->key(ToolManagerName::Brew, $formula->name)] ?? null,
            );
        }

        usort(
            $packages,
            static fn (ToolInventoryPackage $left, ToolInventoryPackage $right): int => $left->package <=> $right->package,
        );

        return $packages;
    }

    /**
     * @param  array<string, int>  $toolIds
     */
    private function caskPackage(HomebrewCaskDiscovery $discovery, array $toolIds): ToolInventoryPackage
    {
        $toolId = $toolIds[$this->key(ToolManagerName::BrewCask, $discovery->package)] ?? null;

        return new ToolInventoryPackage(
            manager: ToolManagerName::BrewCask,
            package: $discovery->package,
            packageKind: ToolInventoryPackageKind::Cask,
            installedVersion: $this->normalized($discovery->installedVersion),
            dependency: false,
            registered: $toolId !== null,
            toolId: $toolId,
            adoption: $discovery->adoption,
            adoptionBlock: $discovery->adoptionBlock,
        );
    }

    private function formulaPackage(stdClass $formula, string $name, string $bottleTag, ?int $toolId): ToolInventoryPackage
    {
        $kegs = $this->kegs($formula);
        $explicitRoot = $this->explicitRoot($kegs);
        $readableVersion = $this->readableVersion($kegs);
        $block = $this->formulaBlock($formula, $name, $bottleTag, $explicitRoot, $readableVersion);

        return new ToolInventoryPackage(
            manager: ToolManagerName::Brew,
            package: $name,
            packageKind: ToolInventoryPackageKind::Formula,
            installedVersion: $this->normalized($readableVersion),
            dependency: ! $explicitRoot,
            registered: $toolId !== null,
            toolId: $toolId,
            adoption: $block === null ? ToolInventoryPackage::SUPPORTED : ToolInventoryPackage::UNSUPPORTED,
            adoptionBlock: $block,
        );
    }

    /** @return list<stdClass> */
    private function kegs(stdClass $formula): array
    {
        $installed = $formula->installed ?? null;

        if (! is_array($installed) || $installed === []) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew formula inventory was malformed.',
            );
        }

        $kegs = [];

        foreach ($installed as $keg) {
            if (! $keg instanceof stdClass) {
                throw new ToolManagerException(
                    step: 'inventory',
                    message: 'The Homebrew formula inventory was malformed.',
                );
            }

            if (property_exists($keg, 'installed_on_request') && ! is_bool($keg->installed_on_request)) {
                throw new ToolManagerException(
                    step: 'inventory',
                    message: 'The Homebrew formula inventory was malformed.',
                );
            }

            $kegs[] = $keg;
        }

        return $kegs;
    }

    /** @param  list<stdClass>  $kegs */
    private function explicitRoot(array $kegs): bool
    {
        return array_any(
            $kegs,
            static fn (stdClass $keg): bool => ($keg->installed_on_request ?? false) === true,
        );
    }

    /** @param  list<stdClass>  $kegs */
    private function readableVersion(array $kegs): ?string
    {
        $versions = [];

        foreach ($kegs as $keg) {
            $version = $keg->version ?? null;

            if (! is_string($version) || $version === 'latest' || ! $this->isSafeVersion($version)) {
                return null;
            }

            $versions[$version] = true;
        }

        $unique = array_keys($versions);

        return count($unique) === 1 ? $unique[0] : null;
    }

    private function formulaBlock(
        stdClass $formula,
        string $name,
        string $bottleTag,
        bool $explicitRoot,
        ?string $readableVersion,
    ): ?string {
        $fullName = $formula->full_name ?? null;
        $tap = $formula->tap ?? null;

        if ($fullName !== $name || $tap !== 'homebrew/core') {
            return ToolInventoryPackage::BLOCK_SOURCE;
        }

        if (in_array($name, self::PROTECTED, true)) {
            return ToolInventoryPackage::BLOCK_PROTECTED;
        }

        if (! $explicitRoot) {
            return ToolInventoryPackage::BLOCK_DEPENDENCY;
        }

        if (property_exists($formula, 'disabled') && ! is_bool($formula->disabled)) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew formula inventory was malformed.',
            );
        }

        if (($formula->disabled ?? false) === true) {
            return ToolInventoryPackage::BLOCK_ARTIFACT;
        }

        if (! $this->bottleAvailable($formula, $bottleTag)) {
            return ToolInventoryPackage::BLOCK_BOTTLE;
        }

        if ($readableVersion === null) {
            return ToolInventoryPackage::BLOCK_VERSION;
        }

        return null;
    }

    private function bottleAvailable(stdClass $formula, string $bottleTag): bool
    {
        $versions = $formula->versions ?? null;
        $bottle = $formula->bottle ?? null;

        if (
            ! $versions instanceof stdClass
            || ! $bottle instanceof stdClass
            || ($versions->bottle ?? null) !== true
        ) {
            return false;
        }

        $stable = $bottle->stable ?? null;
        $files = $stable instanceof stdClass ? $stable->files ?? null : null;

        if (! $files instanceof stdClass) {
            return false;
        }

        $file = property_exists($files, $bottleTag) ? $files->{$bottleTag} : ($files->all ?? null);

        if (! $file instanceof stdClass) {
            return false;
        }

        $sha256 = $file->sha256 ?? null;
        $url = $file->url ?? null;

        return is_string($sha256)
            && preg_match('/\A[a-f0-9]{64}\z/D', $sha256) === 1
            && is_string($url)
            && str_starts_with($url, 'https://ghcr.io/v2/homebrew/core/')
            && str_ends_with($url, "sha256:{$sha256}");
    }

    private function normalized(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return $this->versions->normalize($raw);
    }

    private function linuxBottleTag(Node $node): string
    {
        $result = $this->commands->execute($node, ['/usr/bin/uname', '-m']);

        if (! $result->succeeded() || $result->truncated) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew bottle architecture probe failed.',
                result: $result,
            );
        }

        $lines = preg_split('/\R/', rtrim($result->stdout, "\r\n"));
        $architecture = is_array($lines) ? ($lines[0] ?? '') : '';

        if (! is_array($lines) || count($lines) !== 1) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew bottle architecture probe returned malformed output.',
                result: $result,
            );
        }

        return match ($architecture) {
            'x86_64' => 'x86_64_linux',
            'aarch64', 'arm64' => 'arm64_linux',
            default => throw new ToolManagerException(
                step: 'inventory',
                message: 'The node architecture has no supported Homebrew bottle.',
                result: $result,
            ),
        };
    }

    private function stateFromPrefix(ToolManagerException $exception): ToolInventoryScanState
    {
        return match ($exception->step) {
            'manager-absent' => ToolInventoryScanState::Absent,
            'manager-conflict' => ToolInventoryScanState::Conflicting,
            default => ToolInventoryScanState::Incomplete,
        };
    }

    private function blank(ToolManagerName $manager, ToolInventoryScanState $state): ToolInventoryScan
    {
        return new ToolInventoryScan($manager, $state, []);
    }

    /**
     * Existing Tool rows for this Node. Discoveries are not inserted.
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
                $query->whereIn('name', [
                    ToolManagerName::Brew->value,
                    ToolManagerName::BrewCask->value,
                ]);
            })
            ->with('manager')
            ->get();

        $ids = [];

        foreach ($tools as $tool) {
            $manager = $tool->manager;

            if ($manager->node_id !== $node->id) {
                continue;
            }

            $name = ToolManagerName::tryFrom($manager->name);

            if ($name !== ToolManagerName::Brew && $name !== ToolManagerName::BrewCask) {
                continue;
            }

            $ids[$this->key($name, $tool->package)] = $tool->id;
        }

        return $ids;
    }

    private function key(ToolManagerName $manager, string $package): string
    {
        return $manager->value.'|'.$package;
    }

    /**
     * Names from list that info omitted stay in the inventory.
     * A name in info that list does not return makes the read incomplete.
     *
     * @param  list<ToolInventoryPackage>  $packages
     * @param  array<string, int>  $toolIds
     * @return list<ToolInventoryPackage>
     */
    private function reconcileListedNames(
        Node $node,
        string $prefix,
        bool $formula,
        array $packages,
        array $toolIds,
    ): array {
        $arguments = ['list', $formula ? '--formula' : '--cask', '-1'];
        $command = $node->platform === 'macos'
            ? $this->mac->command($prefix, false, $arguments)
            : $this->formulae->commandForPrefix($node, $prefix, false, $arguments);
        $names = $this->parseNameList($this->commands->execute(
            $node,
            $command,
            maxOutputBytes: self::MAX_NAME_LIST_BYTES,
        ));
        $listed = array_fill_keys($names, true);
        $seen = [];

        foreach ($packages as $package) {
            if (! isset($listed[$package->package])) {
                throw new ToolManagerException(
                    step: 'inventory',
                    message: 'The Homebrew name list did not include every metadata package.',
                );
            }

            $seen[$package->package] = true;
        }

        $manager = $formula ? ToolManagerName::Brew : ToolManagerName::BrewCask;

        foreach ($names as $name) {
            if (isset($seen[$name])) {
                continue;
            }

            $toolId = $toolIds[$this->key($manager, $name)] ?? null;
            $packages[] = new ToolInventoryPackage(
                manager: $manager,
                package: $name,
                packageKind: $formula ? ToolInventoryPackageKind::Formula : ToolInventoryPackageKind::Cask,
                installedVersion: null,
                dependency: false,
                registered: $toolId !== null,
                toolId: $toolId,
                adoption: ToolInventoryPackage::UNSUPPORTED,
                adoptionBlock: $formula && in_array($name, self::PROTECTED, true)
                    ? ToolInventoryPackage::BLOCK_PROTECTED
                    : ToolInventoryPackage::BLOCK_SOURCE,
            );
        }

        usort(
            $packages,
            static fn (ToolInventoryPackage $left, ToolInventoryPackage $right): int => $left->package <=> $right->package,
        );

        return $packages;
    }

    /** @return list<string> */
    private function parseNameList(CommandResult $result): array
    {
        if (! $result->succeeded() || $result->truncated || strlen($result->stdout) > self::MAX_NAME_LIST_BYTES) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew installed-name list probe failed.',
                result: $result,
            );
        }

        $stdout = rtrim($result->stdout, "\r\n");

        if ($stdout === '') {
            return [];
        }

        $lines = preg_split('/\R/', $stdout);

        if (! is_array($lines)) {
            throw new ToolManagerException(
                step: 'inventory',
                message: 'The Homebrew installed-name list was malformed.',
                result: $result,
            );
        }

        $names = [];

        foreach ($lines as $line) {
            if (! HomebrewPackageName::valid($line) || isset($names[$line])) {
                throw new ToolManagerException(
                    step: 'inventory',
                    message: 'The Homebrew installed-name list was malformed.',
                    result: $result,
                );
            }

            $names[$line] = true;
        }

        return array_keys($names);
    }

    private function validPackage(string $package): bool
    {
        return HomebrewPackageName::valid($package);
    }

    private function isSafeVersion(string $version): bool
    {
        return $version !== ''
            && strlen($version) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1
            && preg_match('/\s/', $version) !== 1;
    }
}
