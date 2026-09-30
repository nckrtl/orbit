<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\HomebrewCaskAssessment;
use App\Domain\Tools\HomebrewCaskDiscovery;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use JsonException;
use LogicException;
use stdClass;

/**
 * Official Homebrew casks on macOS. Names stay unqualified. The fixed coordinate is homebrew/cask/<token>.
 * Zap, autoremove, bulk upgrades, and Homebrew services are never run.
 */
final readonly class HomebrewCaskToolManager implements ToolManager
{
    private const int MAX_PACKAGE_LENGTH = 255;

    private const int MAX_RESULT_LENGTH = 131_072;

    private const int MAX_INVENTORY_LENGTH = 1_048_576;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9](?:[a-z0-9@+._-]*[a-z0-9])?\z/D';

    /** @var list<string> */
    private const array PREFIX_KINDS = [
        'binary',
        'manpage',
        'bash_completion',
        'fish_completion',
        'zsh_completion',
        'generate_completions_from_executable',
    ];

    /** @var list<string> */
    private const array HOME_KINDS = [
        'font',
        'colorpicker',
        'dictionary',
        'internet_plugin',
        'prefpane',
        'qlplugin',
        'mdimporter',
        'screen_saver',
        'service',
        'audio_unit_plugin',
        'vst_plugin',
        'vst3_plugin',
    ];

    /** @var list<string> */
    private const array APP_KINDS = ['app', 'suite'];

    /** @var list<string> */
    private const array PRIVILEGED_KINDS = ['pkg', 'installer', 'keyboard_layout', 'input_method'];

    /** @var list<string> */
    private const array ARBITRARY_KINDS = [
        'preflight',
        'postflight',
        'preflight_steps',
        'postflight_steps',
        'uninstall_preflight',
        'uninstall_postflight',
        'stage_only',
    ];

    /** @var list<string> */
    private const array UNINSTALL_KEYS = [
        'quit',
        'signal',
        'login_item',
        'trash',
        'delete',
        'rmdir',
        'pkgutil',
        'kext',
        'launchctl',
    ];

    private HomebrewMacCommand $mac;

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private SemverVersionNormalizer $versions,
        ?HomebrewMacCommand $mac = null,
    ) {
        $this->mac = $mac ?? new HomebrewMacCommand($commands);
    }

    public function name(): ToolManagerName
    {
        return ToolManagerName::BrewCask;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'macos';
    }

    public function validatePackage(string $package): bool
    {
        return
            $package !== ''
            && strlen($package) <= self::MAX_PACKAGE_LENGTH
            && preg_match(self::PACKAGE_PATTERN, $package) === 1;
    }

    public function materialize(Node $node): void
    {
        $this->guardSupportedNode($node);
        $this->mac->resolvePrefix($node);
    }

    public function managerVersion(Node $node): string
    {
        $this->guardSupportedNode($node);
        $result = $this->commands->execute($node, $this->caskArguments($node, false, ['--version']));
        $this->guardSuccessfulResult($result, 'manager-version', 'The Homebrew manager version probe failed.');
        $version = $this->firstLine($result->stdout);

        if (preg_match('/\AHomebrew \d+\.\d+\.\d+\z/D', $version) !== 1) {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The Homebrew manager version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        if ($operation === ToolOperation::Remove) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'Homebrew casks do not provide a removal candidate version.',
            );
        }

        $metadata = $this->metadata($node, $package, true);

        if ($metadata === null) {
            return null;
        }

        [$prefix, $bottleTag, $result] = $metadata;
        $assessment = $this->assess(
            $this->forBottleTag($this->soleCask($result), $bottleTag, $result, 'candidate-version'),
            $package,
            $prefix,
            $result,
            'candidate-version',
        );

        if (! $assessment->supported()) {
            throw new ToolManagerException($assessment->step, $assessment->message, $result);
        }

        return $assessment->version;
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $prefix = $this->mac->resolvePrefix($node);
        $result = $this->commands->execute($node, $this->mac->command($prefix, false, [
            'list',
            '--versions',
            '--cask',
            $package,
        ]));

        if ($result->succeeded()) {
            return $this->listedVersion($result, $package);
        }

        if (! $this->isSilentListMiss($result)) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Homebrew cask installed version probe failed.',
                result: $result,
            );
        }

        return $this->versionFromInstalledIndex($node, $prefix, $package);
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize($rawVersion);
    }

    public function install(Node $node, string $package): void
    {
        $this->requireSupported($node, $package, true);
        $this->mutate($node, $package, 'install', $this->caskArguments($node, true, [
            'install',
            '--cask',
            $this->coordinate($package),
        ]));
    }

    public function update(Node $node, string $package): void
    {
        $this->requireSupported($node, $package, true);
        $this->mutate($node, $package, 'update', $this->caskArguments($node, true, [
            'upgrade',
            '--cask',
            $this->coordinate($package),
        ]));
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $target = $this->requireRemovalAllowed($node, $package);
        $this->mutate($node, $package, 'remove', $this->caskArguments($node, false, [
            'uninstall',
            '--cask',
            $target,
        ]));
    }

    /** @return list<HomebrewCaskDiscovery> */
    public function discoverInstalled(Node $node): array
    {
        $this->guardSupportedNode($node);
        $this->checksumArchitecture($node);
        $prefix = $this->mac->resolvePrefix($node);
        $bottleTag = $this->mac->bottleTag($node);
        $result = $this->commands->execute($node, $this->mac->command($prefix, false, [
            'info',
            '--json=v2',
            '--cask',
            '--installed',
        ]));
        $this->guardSuccessfulResult($result, 'inventory', 'The Homebrew cask inventory probe failed.');
        $decoded = $this->decode($result, 'inventory', 'The Homebrew cask inventory was malformed.', self::MAX_INVENTORY_LENGTH);

        if (! is_array($decoded->casks ?? null) || ! is_array($decoded->formulae ?? null)) {
            throw $this->malformed($result, 'inventory', 'The Homebrew cask inventory was malformed.');
        }

        $discoveries = [];
        $seen = [];

        foreach ($decoded->casks as $cask) {
            if (! $cask instanceof stdClass || ! is_string($cask->token ?? null) || ! $this->validatePackage($cask->token)) {
                throw $this->malformed($result, 'inventory', 'The Homebrew cask inventory was malformed.');
            }

            if (isset($seen[$cask->token])) {
                throw $this->malformed($result, 'inventory', 'The Homebrew cask inventory was malformed.');
            }

            $seen[$cask->token] = true;
            $resolved = $this->forBottleTag($cask, $bottleTag, $result, 'inventory');
            $assessment = $this->assess($resolved, $cask->token, $prefix, $result, 'inventory');
            $installed = $this->readableInstalledVersion($cask);

            if ($assessment->supported() && $installed === null) {
                $assessment = $this->refusal('version_unreadable');
            }

            $discoveries[] = new HomebrewCaskDiscovery(
                package: $cask->token,
                installedVersion: $installed,
                adoption: $assessment->supported()
                    ? HomebrewCaskDiscovery::SUPPORTED
                    : HomebrewCaskDiscovery::UNSUPPORTED,
                adoptionBlock: $assessment->discoveryBlock,
            );
        }

        return $discoveries;
    }

    private function requireSupported(Node $node, string $package, bool $refreshApi): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $metadata = $this->metadata($node, $package, $refreshApi);

        if ($metadata === null) {
            throw new ToolManagerException(
                step: 'cask-source',
                message: 'The Homebrew cask is not an official Homebrew cask.',
            );
        }

        [$prefix, $bottleTag, $result] = $metadata;
        $assessment = $this->assess(
            $this->forBottleTag($this->soleCask($result), $bottleTag, $result, 'candidate-version'),
            $package,
            $prefix,
            $result,
            'candidate-version',
        );

        if ($assessment->supported()) {
            return;
        }

        throw new ToolManagerException($assessment->step, $assessment->message, $result);
    }

    /** @return array{string, string, CommandResult}|null */
    private function metadata(Node $node, string $package, bool $refreshApi): ?array
    {
        $this->checksumArchitecture($node);
        $prefix = $this->mac->resolvePrefix($node);
        $bottleTag = $this->mac->bottleTag($node);
        $result = $this->commands->execute($node, $this->mac->command($prefix, $refreshApi, [
            'info',
            '--json=v2',
            '--cask',
            $this->coordinate($package),
        ]));

        if (! $result->succeeded()) {
            if ($this->isKnownUnavailable($result, $package)) {
                return null;
            }

            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The Homebrew cask metadata probe failed.',
                result: $result,
            );
        }

        return [$prefix, $bottleTag, $result];
    }

    /**
     * Removal reads uninstall safety only. Disabled, checksum, and version gates stay on install and update.
     *
     * @return string The cask name passed to uninstall. A delisted official cask uses the plain token.
     */
    private function requireRemovalAllowed(Node $node, string $package): string
    {
        $this->checksumArchitecture($node);
        $prefix = $this->mac->resolvePrefix($node);
        $bottleTag = $this->mac->bottleTag($node);
        $official = $this->commands->execute($node, $this->mac->command($prefix, false, [
            'info',
            '--json=v2',
            '--cask',
            $this->coordinate($package),
        ]));

        if ($official->succeeded()) {
            $cask = $this->forBottleTag($this->soleCask($official), $bottleTag, $official, 'remove');
            $result = $official;
            $target = $this->coordinate($package);
        } elseif ($this->isKnownUnavailable($official, $package)) {
            $installed = $this->commands->execute($node, $this->mac->command($prefix, false, [
                'info',
                '--json=v2',
                '--cask',
                '--installed',
            ]));
            $this->guardSuccessfulResult($installed, 'remove', 'The installed Homebrew cask metadata could not be read.');
            $cask = $this->installedCask($installed, $package);

            if (! $cask instanceof stdClass) {
                throw new ToolManagerException(
                    step: 'remove',
                    message: 'The installed Homebrew cask metadata could not be read.',
                    result: $installed,
                );
            }

            $cask = $this->forBottleTag($cask, $bottleTag, $installed, 'remove');
            $result = $installed;

            if (($cask->tap ?? null) !== 'homebrew/cask') {
                throw new ToolManagerException(
                    step: 'cask-source',
                    message: 'The Homebrew cask is not an official Homebrew cask.',
                    result: $installed,
                );
            }

            $target = $package;
        } else {
            throw new ToolManagerException(
                step: 'remove',
                message: 'The Homebrew cask metadata probe failed.',
                result: $official,
            );
        }

        $refusal = $this->removalRefusal($cask, $prefix, $result);

        if ($refusal !== null) {
            throw new ToolManagerException($refusal->step, $refusal->message, $result);
        }

        return $target;
    }

    private function installedCask(CommandResult $result, string $package): ?stdClass
    {
        $decoded = $this->decode($result, 'remove', 'The installed Homebrew cask metadata was malformed.', self::MAX_INVENTORY_LENGTH);

        if (! is_array($decoded->casks ?? null)) {
            throw $this->malformed($result, 'remove', 'The installed Homebrew cask metadata was malformed.');
        }

        $found = null;

        foreach ($decoded->casks as $cask) {
            if (! $cask instanceof stdClass || ($cask->token ?? null) !== $package) {
                continue;
            }

            if ($found instanceof stdClass) {
                throw $this->malformed($result, 'remove', 'The installed Homebrew cask metadata was malformed.');
            }

            $found = $cask;
        }

        return $found;
    }

    private function removalRefusal(stdClass $cask, string $prefix, CommandResult $result): ?HomebrewCaskAssessment
    {
        if (! is_array($cask->artifacts ?? null)) {
            throw $this->malformed($result, 'remove', 'The installed Homebrew cask metadata was malformed.');
        }

        foreach ($cask->artifacts as $artifact) {
            if (! $artifact instanceof stdClass) {
                throw $this->malformed($result, 'remove', 'The installed Homebrew cask metadata was malformed.');
            }

            if (property_exists($artifact, 'uninstall') && $this->uninstallIsArbitrary($artifact->uninstall)) {
                return $this->refusal(HomebrewCaskDiscovery::BLOCK_ARTIFACT);
            }
        }

        if ($this->authorizationBlock($cask, $prefix)) {
            return $this->refusal(HomebrewCaskDiscovery::BLOCK_AUTHORIZATION);
        }

        return null;
    }

    private function forBottleTag(stdClass $cask, string $bottleTag, CommandResult $result, string $step): stdClass
    {
        if (! property_exists($cask, 'variations')) {
            return $cask;
        }

        $variations = $cask->variations;

        if (! $variations instanceof stdClass) {
            throw $this->malformed($result, $step, 'The Homebrew cask metadata was malformed.');
        }

        if (! property_exists($variations, $bottleTag)) {
            return $cask;
        }

        $variation = $variations->{$bottleTag};

        if (! $variation instanceof stdClass) {
            throw $this->malformed($result, $step, 'The Homebrew cask metadata was malformed.');
        }

        $resolved = clone $cask;

        foreach (get_object_vars($variation) as $key => $value) {
            $resolved->{$key} = $value;
        }

        return $resolved;
    }

    private function soleCask(CommandResult $result): stdClass
    {
        $decoded = $this->decode($result, 'candidate-version', 'The Homebrew cask metadata was malformed.', self::MAX_RESULT_LENGTH);

        if (
            ! is_array($decoded->formulae ?? null)
            || $decoded->formulae !== []
            || ! is_array($decoded->casks ?? null)
            || count($decoded->casks) !== 1
            || ! $decoded->casks[0] instanceof stdClass
        ) {
            throw $this->malformed($result, 'candidate-version', 'The Homebrew cask metadata was malformed.');
        }

        return $decoded->casks[0];
    }

    private function assess(
        stdClass $cask,
        string $package,
        string $prefix,
        CommandResult $result,
        string $malformedStep,
    ): HomebrewCaskAssessment {
        if (! property_exists($cask, 'disabled') || ! is_bool($cask->disabled) || ! is_array($cask->artifacts ?? null)) {
            throw $this->malformed($result, $malformedStep, 'The Homebrew cask metadata was malformed.');
        }

        $source = $this->sourceBlock($cask, $package);

        if ($source !== null) {
            return $this->refusal($source);
        }

        $artifact = $this->artifactBlock($cask, $prefix);

        if ($artifact !== null) {
            return $this->refusal($artifact);
        }

        if ($this->authorizationBlock($cask, $prefix)) {
            return $this->refusal('authorization_required');
        }

        if (! $this->checksumSatisfied($cask->sha256 ?? null)) {
            return $this->refusal('checksum');
        }

        $version = $cask->version ?? null;

        if (! $this->isCaskVersion($version)) {
            return $this->refusal('version_unreadable');
        }

        return new HomebrewCaskAssessment(null, null, '', '', $version);
    }

    private function sourceBlock(stdClass $cask, string $package): ?string
    {
        $token = $cask->token ?? null;
        $fullToken = $cask->full_token ?? null;
        $tap = $cask->tap ?? null;
        $url = $cask->url ?? null;

        if (! is_string($token) || ! is_string($fullToken) || ! is_string($tap) || ! is_string($url)) {
            return HomebrewCaskDiscovery::BLOCK_SOURCE;
        }

        if ($token !== $package || $fullToken !== $package || $tap !== 'homebrew/cask') {
            return HomebrewCaskDiscovery::BLOCK_SOURCE;
        }

        if (preg_match('#\Ahttps://[^\s]+\z#', $url) !== 1) {
            return HomebrewCaskDiscovery::BLOCK_SOURCE;
        }

        return null;
    }

    private function artifactBlock(stdClass $cask, string $prefix): ?string
    {
        if ($cask->disabled === true) {
            return HomebrewCaskDiscovery::BLOCK_ARTIFACT;
        }

        $installKinds = 0;

        foreach ($cask->artifacts as $artifact) {
            if (! $artifact instanceof stdClass) {
                return HomebrewCaskDiscovery::BLOCK_ARTIFACT;
            }

            foreach (array_keys(get_object_vars($artifact)) as $key) {
                if ($key === 'target' || $key === 'zap') {
                    continue;
                }

                if ($key === 'uninstall') {
                    if ($this->uninstallIsArbitrary($artifact->uninstall)) {
                        return HomebrewCaskDiscovery::BLOCK_ARTIFACT;
                    }

                    continue;
                }

                if (! $this->knownKind($key) || in_array($key, self::ARBITRARY_KINDS, true)) {
                    return HomebrewCaskDiscovery::BLOCK_ARTIFACT;
                }

                $installKinds++;

                if (in_array($key, self::PRIVILEGED_KINDS, true)) {
                    continue;
                }

                foreach ($this->destinations($key, $artifact) as $path) {
                    if ($this->pathClass($path, $prefix) === 'unsupported') {
                        return HomebrewCaskDiscovery::BLOCK_ARTIFACT;
                    }
                }
            }
        }

        return $installKinds === 0 ? HomebrewCaskDiscovery::BLOCK_ARTIFACT : null;
    }

    private function authorizationBlock(stdClass $cask, string $prefix): bool
    {
        foreach ($cask->artifacts as $artifact) {
            if (! $artifact instanceof stdClass) {
                continue;
            }

            foreach (array_keys(get_object_vars($artifact)) as $key) {
                if ($key === 'target' || $key === 'zap') {
                    continue;
                }

                if ($key === 'uninstall') {
                    if ($this->uninstallNeedsAuthorization($artifact->uninstall, $prefix)) {
                        return true;
                    }

                    continue;
                }

                if (in_array($key, self::PRIVILEGED_KINDS, true)) {
                    return true;
                }

                if (! $this->knownKind($key) || in_array($key, self::ARBITRARY_KINDS, true)) {
                    continue;
                }

                foreach ($this->destinations($key, $artifact) as $path) {
                    if ($this->pathClass($path, $prefix) === 'authorization') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function uninstallIsArbitrary(mixed $stanza): bool
    {
        if (! is_array($stanza)) {
            return true;
        }

        foreach ($stanza as $directive) {
            if (! $directive instanceof stdClass) {
                return true;
            }

            foreach (array_keys(get_object_vars($directive)) as $key) {
                if (! in_array($key, self::UNINSTALL_KEYS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function uninstallNeedsAuthorization(mixed $stanza, string $prefix): bool
    {
        if (! is_array($stanza)) {
            return false;
        }

        foreach ($stanza as $directive) {
            if (! $directive instanceof stdClass) {
                continue;
            }

            $values = get_object_vars($directive);

            foreach (['pkgutil', 'kext', 'launchctl'] as $key) {
                if (array_key_exists($key, $values)) {
                    return true;
                }
            }

            foreach (['trash', 'delete', 'rmdir'] as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $paths = $this->stringList($values[$key]);

                if ($paths === null) {
                    return true;
                }

                foreach ($paths as $path) {
                    if ($this->pathClass($path, $prefix) !== 'user') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function destinations(string $kind, stdClass $artifact): array
    {
        $targets = [];

        if (is_string($artifact->target ?? null)) {
            $targets[] = $artifact->target;
        }

        $value = $artifact->{$kind} ?? null;

        if (is_array($value)) {
            foreach ($value as $entry) {
                if ($entry instanceof stdClass && is_string($entry->target ?? null)) {
                    $targets[] = $entry->target;
                }
            }
        }

        if ($targets !== []) {
            return $targets;
        }

        if (in_array($kind, self::APP_KINDS, true)) {
            return ['/Applications'];
        }

        if (in_array($kind, self::HOME_KINDS, true)) {
            return ['/$HOME'];
        }

        if (in_array($kind, self::PREFIX_KINDS, true)) {
            return ['$HOMEBREW_PREFIX'];
        }

        return ['relative/missing-target'];
    }

    private function pathClass(string $path, string $prefix): string
    {
        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return 'unsupported';
        }

        $userOwned = str_starts_with($path, '~/')
            || $path === '~'
            || str_starts_with($path, '$HOME/')
            || $path === '$HOME'
            || str_starts_with($path, '/$HOME/')
            || $path === '/$HOME'
            || str_starts_with($path, '$HOMEBREW_PREFIX/')
            || $path === '$HOMEBREW_PREFIX'
            || str_starts_with($path, $prefix.'/')
            || $path === $prefix
            || ! str_contains($path, '/');

        if ($userOwned) {
            return 'user';
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '$APPDIR')) {
            return 'authorization';
        }

        return 'unsupported';
    }

    private function checksumSatisfied(mixed $sha): bool
    {
        return is_string($sha) && $this->isSha256($sha);
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function isCaskVersion(mixed $version): bool
    {
        return is_string($version) && $version !== 'latest' && $this->isSafeVersion($version);
    }

    private function readableInstalledVersion(stdClass $cask): ?string
    {
        $installed = $cask->installed ?? null;

        if (! is_string($installed) || $installed === 'latest' || ! $this->isSafeVersion($installed)) {
            return null;
        }

        return $installed;
    }

    private function knownKind(string $kind): bool
    {
        return in_array($kind, [
            ...self::PREFIX_KINDS,
            ...self::HOME_KINDS,
            ...self::APP_KINDS,
            ...self::PRIVILEGED_KINDS,
            'artifact',
        ], true);
    }

    private function refusal(string $block): HomebrewCaskAssessment
    {
        [$step, $message, $discovery] = match ($block) {
            HomebrewCaskDiscovery::BLOCK_SOURCE => [
                'cask-source',
                'The Homebrew cask is not an official Homebrew cask.',
                HomebrewCaskDiscovery::BLOCK_SOURCE,
            ],
            HomebrewCaskDiscovery::BLOCK_ARTIFACT => [
                'cask-artifact',
                'The Homebrew cask artifact is not supported.',
                HomebrewCaskDiscovery::BLOCK_ARTIFACT,
            ],
            'checksum' => [
                'cask-checksum',
                'The Homebrew cask checksum policy was not satisfied.',
                HomebrewCaskDiscovery::BLOCK_ARTIFACT,
            ],
            HomebrewCaskDiscovery::BLOCK_AUTHORIZATION => [
                'cask-authorization',
                'The Homebrew cask requires interactive or administrator authorization.',
                HomebrewCaskDiscovery::BLOCK_AUTHORIZATION,
            ],
            HomebrewCaskDiscovery::BLOCK_VERSION => [
                'cask-version',
                'The Homebrew cask version cannot be read.',
                HomebrewCaskDiscovery::BLOCK_VERSION,
            ],
            default => throw new LogicException("Unknown Homebrew cask block [{$block}]."),
        };

        return new HomebrewCaskAssessment($block, $discovery, $step, $message, null);
    }

    private function decode(CommandResult $result, string $step, string $message, int $maxLength): stdClass
    {
        if (strlen($result->stdout) > $maxLength) {
            throw $this->malformed($result, $step, $message);
        }

        try {
            $decoded = json_decode($result->stdout, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ToolManagerException(
                step: $step,
                message: $message,
                result: $result,
                previous: $exception,
            );
        }

        if (! $decoded instanceof stdClass) {
            throw $this->malformed($result, $step, $message);
        }

        return $decoded;
    }

    private function malformed(CommandResult $result, string $step, string $message): ToolManagerException
    {
        return new ToolManagerException(step: $step, message: $message, result: $result);
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return null;
        }

        $paths = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }

            $paths[] = $item;
        }

        return $paths;
    }

    private function coordinate(string $package): string
    {
        return "homebrew/cask/{$package}";
    }

    private function isKnownUnavailable(CommandResult $result, string $package): bool
    {
        if ($result->exitCode !== 1 || $result->truncated) {
            return false;
        }

        $output = $result->stdout."\n".$result->stderr;

        if (strlen($output) > self::MAX_RESULT_LENGTH) {
            return false;
        }

        $coordinate = $this->coordinate($package);

        if (
            str_contains($output, "Cask '{$coordinate}' is unavailable.")
            && str_contains($output, 'This command requires the tap homebrew/cask.')
        ) {
            return true;
        }

        if (! str_contains($output, 'No Cask with this name exists')) {
            return false;
        }

        return str_contains($output, "Cask '{$coordinate}'")
            || str_contains($output, "Cask \"{$coordinate}\"")
            || str_contains($output, "Cask '{$package}'")
            || str_contains($output, "Cask \"{$package}\"");
    }

    private function listedVersion(CommandResult $result, string $package): string
    {
        $line = trim($result->stdout);
        $matched = preg_match('/\A'.preg_quote($package, '/').' ([^\s]+)\z/D', $line, $matches);

        if ($matched !== 1 || strlen($line) > self::MAX_RESULT_LENGTH || ! $this->isSafeVersion($matches[1])) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Homebrew cask installed version probe returned malformed output.',
                result: $result,
            );
        }

        return $matches[1];
    }

    /**
     * A silent exit 1 is what Homebrew prints for an absent cask, but it is not absence by itself.
     */
    private function isSilentListMiss(CommandResult $result): bool
    {
        return $result->exitCode === 1
            && ! $result->truncated
            && $result->stdout === ''
            && $result->stderr === '';
    }

    private function versionFromInstalledIndex(Node $node, string $prefix, string $package): ?string
    {
        $result = $this->commands->execute($node, $this->mac->command($prefix, false, [
            'info',
            '--json=v2',
            '--cask',
            '--installed',
        ]));
        $this->guardSuccessfulResult($result, 'installed-version', 'The Homebrew cask installed version probe failed.');
        $decoded = $this->decode(
            $result,
            'installed-version',
            'The Homebrew cask installed version probe returned malformed output.',
            self::MAX_INVENTORY_LENGTH,
        );

        if (! is_array($decoded->casks ?? null)) {
            throw $this->malformed($result, 'installed-version', 'The Homebrew cask installed version probe returned malformed output.');
        }

        $found = null;

        foreach ($decoded->casks as $cask) {
            if (! $cask instanceof stdClass || ($cask->token ?? null) !== $package) {
                continue;
            }

            if ($found instanceof stdClass) {
                throw $this->malformed($result, 'installed-version', 'The Homebrew cask installed version probe returned malformed output.');
            }

            $found = $cask;
        }

        if (! $found instanceof stdClass || ($found->tap ?? null) !== 'homebrew/cask') {
            return null;
        }

        $installed = $this->readableInstalledVersion($found);

        if ($installed === null) {
            throw $this->malformed($result, 'installed-version', 'The Homebrew cask installed version probe returned malformed output.');
        }

        return $installed;
    }

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    private function caskArguments(Node $node, bool $refreshApi, array $arguments): array
    {
        return $this->mac->brew($node, $refreshApi, $arguments);
    }

    /** @param non-empty-list<string> $arguments */
    private function mutate(Node $node, string $package, string $step, array $arguments): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);
        $result = $this->commands->execute($node, $arguments);
        $this->guardSuccessfulResult($result, $step, "The Homebrew cask {$step} operation failed.");
    }

    private function checksumArchitecture(Node $node): string
    {
        if ($node->architecture === 'arm64' || $node->architecture === 'x86_64') {
            return $node->architecture;
        }

        throw new ToolManagerException(
            step: 'candidate-version',
            message: 'The node architecture has no supported Homebrew cask checksum.',
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The Homebrew cask name is invalid.',
        );
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Homebrew casks require a macOS node.',
        );
    }

    private function guardSuccessfulResult(CommandResult $result, string $step, string $message): void
    {
        if ($result->succeeded()) {
            return;
        }

        throw new ToolManagerException(step: $step, message: $message, result: $result);
    }

    private function firstLine(string $output): string
    {
        $lines = preg_split('/\R/', $output, limit: 2);
        $line = is_array($lines) ? $lines[0] ?? '' : '';

        return $this->isSafeText($line) ? $line : '';
    }

    private function isSafeVersion(string $version): bool
    {
        return $this->isSafeText($version) && preg_match('/\s/', $version) !== 1;
    }

    private function isSafeText(string $value): bool
    {
        return
            $value !== ''
            && strlen($value) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
