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

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps each fail-closed VP parsing branch explicit.
 * @mago-expect lint:too-many-methods The closed manager contract requires every lifecycle method on one adapter.
 */
final readonly class VpToolManager implements ToolManager
{
    private const string VP_HOME = '/opt/orbit/vite-plus';

    private const string VP_BINARY = '/usr/local/bin/vp';

    private const int MAX_PACKAGE_LENGTH = 214;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*\/[a-z0-9][a-z0-9._~-]*)\z/D';

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private SemverVersionNormalizer $versions,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Vp;
    }

    public function supportsNode(Node $node): bool
    {
        $node->loadMissing('roles');

        if ($node->platform !== 'linux') {
            return false;
        }

        foreach ($node->roles as $role) {
            if (
                ($role->role === RoleName::AppDev
                || $role->role === RoleName::AppProd)
                && ($role->status === LifecycleStatus::Provisioning
                || $role->status === LifecycleStatus::Active)
            ) {
                return true;
            }
        }

        return false;
    }

    public function validatePackage(string $package): bool
    {
        $length = strlen($package);

        return $length >= 1 && $length <= self::MAX_PACKAGE_LENGTH && preg_match(self::PACKAGE_PATTERN, $package) === 1;
    }

    public function managerVersion(Node $node): string
    {
        $this->guardNode($node);

        $result = $this->commands->execute($node, [self::VP_BINARY, '--version']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The VP manager version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? $lines[0] ?? '' : '';

        if (! $this->isSafeString($version)) {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The VP manager version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments('info', $package, 'version', '--json'));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The VP candidate version probe failed.',
        );

        return $this->decodeJsonString($result, 'candidate-version');
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments('list', '-g', $package, '--json'));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'installed-version',
            message: 'The VP installed version probe failed.',
        );

        /** @var mixed $decoded */
        $decoded = json_decode($result->stdout);

        if (! is_array($decoded)) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The VP installed version probe returned malformed output.',
                result: $result,
            );
        }

        if ($decoded === []) {
            return null;
        }

        $matchedVersion = null;

        /** @var mixed $entry */
        foreach ($decoded as $entry) {
            if (! is_object($entry)) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned malformed output.',
                    result: $result,
                );
            }

            /** @var mixed $name */
            $name = $entry->name ?? null;
            /** @var mixed $version */
            $version = $entry->version ?? null;

            if (
                ! is_string($name)
                || ! is_string($version)
                || ! $this->isSafeString($name)
                || ! $this->isSafeString($version)
            ) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned malformed output.',
                    result: $result,
                );
            }

            if ($name !== $package) {
                continue;
            }

            if ($matchedVersion !== null) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned ambiguous output.',
                    result: $result,
                );
            }

            $matchedVersion = $version;
        }

        return $matchedVersion;
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
            arguments: $this->vpArguments('install', '-g', $package, '--node', 'lts'),
        );
    }

    public function update(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'update',
            arguments: $this->vpArguments('update', '-g', $package, '--reinstall-node-mismatch'),
        );
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments('remove', '-g', '--dry-run', $package));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'removal-plan',
            message: 'The VP removal plan failed.',
        );

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'remove',
            arguments: $this->vpArguments('remove', '-g', $package),
        );
    }

    private function guardNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'The VP tool manager does not support this node.',
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The VP package coordinate is invalid.',
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

    private function decodeJsonString(CommandResult $result, string $step): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($result->stdout, associative: true);

        if (! is_string($decoded) || ! $this->isSafeString($decoded)) {
            throw new ToolManagerException(
                step: $step,
                message: 'The VP candidate version probe returned malformed output.',
                result: $result,
            );
        }

        return $decoded;
    }

    private function isSafeString(string $value): bool
    {
        return (
            $value !== ''
            && strlen($value) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
        );
    }

    /** @param non-empty-list<string> $arguments */
    private function mutate(Node $node, string $package, string $step, array $arguments): void
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $arguments);

        $this->guardSuccessfulResult(
            result: $result,
            step: $step,
            message: "The VP {$step} operation failed.",
        );
    }

    /** @return non-empty-list<string> */
    private function vpArguments(string ...$arguments): array
    {
        return [
            'env',
            'VP_HOME='.self::VP_HOME,
            self::VP_BINARY,
            ...$arguments,
        ];
    }
}
