<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\NodeRole;
use JsonException;
use stdClass;

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps each fail-closed Composer parsing branch explicit.
 * @mago-expect lint:kan-defect The score reflects explicit private-root verification and failure gates.
 * @mago-expect lint:too-many-methods The closed manager contract requires every lifecycle method on one adapter.
 */
final readonly class ComposerToolManager implements ToolManager
{
    private const int MAX_PACKAGE_LENGTH = 255;

    private const int MAX_RESULT_LENGTH = 32_768;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\z/D';

    /** @var non-empty-list<string> */
    private const array PREFIX = ['env', 'COMPOSER_HOME=/opt/orbit/composer', '/usr/bin/composer', 'global'];

    /** @var non-empty-list<string> */
    private const array OPERATION_FLAGS = [
        '--no-interaction',
        '--no-ansi',
        '--no-progress',
        '--no-audit',
        '--with-all-dependencies',
    ];

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private ComposerDryRunVersionParser $parser,
        private SemverVersionNormalizer $versions,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Composer;
    }

    public function supportsNode(Node $node): bool
    {
        $node->loadMissing('roles');

        if ($node->platform !== 'linux') {
            return false;
        }

        return $node->roles->contains(
            static fn (NodeRole $role): bool => (
                in_array($role->role, [RoleName::AppDev, RoleName::AppProd], strict: true)
                && in_array($role->status, [LifecycleStatus::Provisioning, LifecycleStatus::Active], strict: true)
            ),
        );
    }

    public function validatePackage(string $package): bool
    {
        return (
            $package !== ''
            && strlen($package) <= self::MAX_PACKAGE_LENGTH
            && preg_match(self::PACKAGE_PATTERN, $package) === 1
        );
    }

    public function managerVersion(Node $node): string
    {
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, ['/usr/bin/composer', '--version', '--no-ansi']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The Composer manager version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? $lines[0] ?? '' : '';

        if (! $this->isSafeText($version)) {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The Composer manager version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        $arguments = match ($operation) {
            ToolOperation::Install => $this->requireArguments($package, dryRun: true),
            ToolOperation::Update => $this->updateArguments($package, dryRun: true),
            ToolOperation::Remove => throw new ToolManagerException(
                step: 'candidate-version',
                message: 'Composer does not provide a removal candidate version.',
            ),
        };
        $result = $this->commands->execute($node, $arguments);

        if (! $result->succeeded()) {
            if ($this->isKnownPackageNotFound($result, $package)) {
                return null;
            }

            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The Composer candidate version probe failed.',
                result: $result,
            );
        }

        $output = $this->combinedOutput($result);

        return $this->parser->parse(
            output: $output,
            package: $package,
            installedFallback: fn (): ?string => $this->installedVersion($node, $package),
        );
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, $this->showArguments());

        $this->guardSuccessfulResult(
            result: $result,
            step: 'installed-version',
            message: 'The Composer installed version probe failed.',
        );

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($result->stdout, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Composer installed version probe returned malformed output.',
                result: $result,
                previous: $exception,
            );
        }

        if (
            ! $decoded instanceof stdClass
            || ! property_exists($decoded, 'installed')
            || ! is_array($decoded->installed)
        ) {
            throw $this->malformedInstalledResult($result);
        }

        $installed = $decoded->installed;

        $versions = [];

        /** @var mixed $entry */
        foreach ($installed as $entry) {
            if (! $entry instanceof stdClass) {
                throw $this->malformedInstalledResult($result);
            }

            /** @var mixed $name */
            $name = $entry->name ?? null;
            /** @var mixed $version */
            $version = $entry->version ?? null;

            if (
                ! is_string($name)
                || ! is_string($version)
                || ! $this->validatePackage($name)
                || ! $this->isSafePackageVersion($version)
            ) {
                throw $this->malformedInstalledResult($result);
            }

            if ($name === $package) {
                $versions[] = $version;
            }
        }

        if ($versions === []) {
            return null;
        }

        if (count($versions) !== 1) {
            throw $this->malformedInstalledResult($result);
        }

        return $versions[0];
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize($rawVersion);
    }

    public function install(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'install',
            arguments: $this->requireArguments($package),
        );
    }

    public function update(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'update',
            arguments: $this->updateArguments($package),
        );
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, $this->removeArguments($package, dryRun: true));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'removal-plan',
            message: 'The Composer removal plan failed.',
        );

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'remove',
            arguments: $this->removeArguments($package),
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The Composer package coordinate is invalid.',
        );
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Composer tools require a provisioning or active Linux app node.',
        );
    }

    private function guardSuccessfulResult(CommandResult $result, string $step, string $message): void
    {
        if ($result->succeeded()) {
            return;
        }

        throw new ToolManagerException(
            step: $step,
            message: $message,
            result: $result,
        );
    }

    private function isSafeText(string $value): bool
    {
        return (
            $value !== ''
            && strlen($value) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
        );
    }

    private function isSafePackageVersion(string $version): bool
    {
        return $this->isSafeText($version) && preg_match('/\s/', $version) !== 1;
    }

    private function combinedOutput(CommandResult $result): string
    {
        return implode(
            "\n",
            array_values(array_filter(
                [$result->stdout, $result->stderr],
                static fn (string $output): bool => $output !== '',
            )),
        );
    }

    private function isKnownPackageNotFound(CommandResult $result, string $package): bool
    {
        $output = $this->combinedOutput($result);

        if ($output === '' || strlen($output) > self::MAX_RESULT_LENGTH) {
            return false;
        }

        $normalized = preg_replace(pattern: '/\s+/', replacement: ' ', subject: trim($output));

        if (! is_string($normalized)) {
            return false;
        }

        $packagePattern = preg_quote(str: $package, delimiter: '~');

        return (
            preg_match(
                '~Could not find a matching version of package '
                .$packagePattern
                .'\. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability~',
                $normalized,
            ) === 1
        );
    }

    private function malformedInstalledResult(CommandResult $result): ToolManagerException
    {
        return new ToolManagerException(
            step: 'installed-version',
            message: 'The Composer installed version probe returned malformed output.',
            result: $result,
        );
    }

    /** @return non-empty-list<string> */
    private function showArguments(): array
    {
        return [...self::PREFIX, 'show', '--format=json', '--no-ansi'];
    }

    /** @return non-empty-list<string> */
    private function requireArguments(string $package, bool $dryRun = false): array
    {
        return $this->operationArguments('require', "{$package}:*", $dryRun);
    }

    /** @return non-empty-list<string> */
    private function updateArguments(string $package, bool $dryRun = false): array
    {
        return $this->operationArguments('update', $package, $dryRun);
    }

    /** @return non-empty-list<string> */
    private function removeArguments(string $package, bool $dryRun = false): array
    {
        return $this->operationArguments('remove', $package, $dryRun);
    }

    /**
     * @return non-empty-list<string>
     * @mago-expect lint:no-boolean-flag-parameter The flag selects the fixed live or dry-run command form.
     */
    private function operationArguments(string $operation, string $package, bool $dryRun): array
    {
        $arguments = [...self::PREFIX, $operation, $package];

        if ($dryRun) {
            $arguments[] = '--dry-run';
        }

        return [...$arguments, ...self::OPERATION_FLAGS];
    }

    /** @param non-empty-list<string> $arguments */
    private function mutate(Node $node, string $package, string $step, array $arguments): void
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, $arguments);

        $this->guardSuccessfulResult(
            result: $result,
            step: $step,
            message: "The Composer {$step} operation failed.",
        );
    }
}
