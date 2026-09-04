<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps each fail-closed APT parsing branch explicit.
 * @mago-expect lint:kan-defect The score reflects explicit package-state parsing and failure gates.
 * @mago-expect lint:too-many-methods The closed manager contract requires every lifecycle method on one adapter.
 */
final readonly class AptToolManager implements ToolManager
{
    private const int MAX_PACKAGE_LENGTH = 128;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9][a-z0-9+.-]*\z/D';

    private const string PLANNED_PACKAGE_PATTERN = '/\ARemv\s+([a-z0-9][a-z0-9+.-]*(?::[a-z0-9][a-z0-9-]*)?)(?:\s|$)/D';

    private const array REMOVED_STATUSES = [
        'deinstall ok config-files',
        'deinstall ok not-installed',
        'purge ok config-files',
        'purge ok not-installed',
    ];

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private DebianVersionNormalizer $versions,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Apt;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux';
    }

    public function validatePackage(string $package): bool
    {
        $length = strlen($package);

        return (
            $length >= 2
            && $length <= self::MAX_PACKAGE_LENGTH
            && preg_match(self::PACKAGE_PATTERN, $package) === 1
            && preg_match('/[a-z]/', $package) === 1
        );
    }

    public function managerVersion(Node $node): string
    {
        $result = $this->commands->execute($node, ['apt-get', '--version']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The APT manager version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? $lines[0] ?? '' : '';

        if (! $this->isSafeVersion($version)) {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The APT manager version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, ['apt-cache', 'policy', '--', $package]);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The APT candidate version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout);
        $candidateLines = array_values(array_filter(
            is_array($lines) ? $lines : [],
            static fn (string $line): bool => preg_match('/\A\s*Candidate:/', $line) === 1,
        ));

        if (count($candidateLines) !== 1) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The APT candidate version probe returned ambiguous output.',
                result: $result,
            );
        }

        $matches = [];

        if (preg_match('/\A\s*Candidate:\s+(\S+)\s*\z/D', $candidateLines[0], $matches) !== 1) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The APT candidate version probe returned malformed output.',
                result: $result,
            );
        }

        $version = $matches[1];

        if ($version === '(none)') {
            return null;
        }

        if (! $this->isSafeVersion($version)) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The APT candidate version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, [
            'dpkg-query',
            '--show',
            '--showformat=${Status}\n${Version}\n',
            '--',
            $package,
        ]);

        if (
            $result->exitCode === 1
            && $result->stdout === ''
            && $result->stderr === "dpkg-query: no packages found matching {$package}\n"
        ) {
            return null;
        }

        $this->guardSuccessfulResult(
            result: $result,
            step: 'installed-version',
            message: 'The APT installed version probe failed.',
        );

        $splitLines = preg_split('/\R/', $result->stdout);
        $lines = is_array($splitLines) ? $splitLines : [];

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        if ($lines === ['unknown ok not-installed']) {
            return null;
        }

        if (count($lines) !== 2) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The APT installed version probe returned malformed output.',
                result: $result,
            );
        }

        $version = $lines[1];

        if (! $this->isSafeVersion($version)) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The APT installed version probe returned malformed output.',
                result: $result,
            );
        }

        if (in_array($lines[0], self::REMOVED_STATUSES, strict: true)) {
            return null;
        }

        if ($lines[0] !== 'install ok installed') {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The APT installed version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
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
            arguments: ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', $package],
        );
    }

    public function update(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'update',
            arguments: ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', $package],
        );
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, ['apt-get', '--simulate', 'remove', '--', $package]);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'removal-plan',
            message: 'The APT removal plan failed.',
        );

        $packages = [];
        $lines = preg_split('/\R/', $result->stdout);

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (preg_match('/\A\s*Remv(?:\s|\z)/', $line) !== 1) {
                continue;
            }

            $matches = [];

            if (preg_match(self::PLANNED_PACKAGE_PATTERN, $line, $matches) !== 1) {
                throw new ToolManagerException(
                    step: 'removal-plan',
                    message: 'The APT removal plan returned a malformed package record.',
                    result: $result,
                );
            }

            $plannedPackage = $this->normalizeRequestedArchitecture($matches[1], $package);
            $packages[$plannedPackage] = true;
        }

        return new ToolRemovalPlan(array_keys($packages));
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'remove',
            arguments: ['sudo', 'apt-get', 'remove', '--yes', '--', $package],
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The APT package coordinate is invalid.',
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

    private function isSafeVersion(string $version): bool
    {
        return (
            $version !== ''
            && strlen($version) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1
        );
    }

    /** @param non-empty-list<string> $arguments */
    private function mutate(Node $node, string $package, string $step, array $arguments): void
    {
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $arguments);

        $this->guardSuccessfulResult(
            result: $result,
            step: $step,
            message: "The APT {$step} operation failed.",
        );
    }

    private function normalizeRequestedArchitecture(string $plannedPackage, string $requestedPackage): string
    {
        $prefix = "{$requestedPackage}:";

        if (! str_starts_with($plannedPackage, $prefix)) {
            return $plannedPackage;
        }

        return $requestedPackage;
    }
}
