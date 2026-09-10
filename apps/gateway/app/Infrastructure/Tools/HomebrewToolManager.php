<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use JsonException;
use stdClass;

final readonly class HomebrewToolManager implements ToolManager
{
    private const string BREW = '/home/linuxbrew/.linuxbrew/bin/brew';

    private const int MAX_PACKAGE_LENGTH = 255;

    private const int MAX_RESULT_LENGTH = 131_072;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9](?:[a-z0-9@+._-]*[a-z0-9])?\z/D';

    /** @var non-empty-list<string> */
    private const array PREFIX = [
        'env',
        'HOMEBREW_NO_AUTO_UPDATE=1',
        'HOMEBREW_NO_ANALYTICS=1',
        'HOMEBREW_NO_ENV_HINTS=1',
        'PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin',
        self::BREW,
    ];

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private SemverVersionNormalizer $versions,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Brew;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux';
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

        $program = <<<'BASH'
            managed_user=$1
            prefix=/home/linuxbrew/.linuxbrew
            repository=$prefix/Homebrew
            expected_origin=https://github.com/Homebrew/brew
            expected_revision=2b3683acbeac84c27669195235785694b72e253e
            expected_version='Homebrew 6.0.6'

            passwd_entry=$(getent passwd -- "$managed_user")
            test "$(printf '%s\n' "$passwd_entry" | wc -l)" -eq 1
            managed_group=$(id -gn -- "$managed_user")

            export DEBIAN_FRONTEND=noninteractive
            apt-get update
            apt-get install --yes --no-install-recommends --no-remove -- build-essential procps curl file git ca-certificates

            if { [ -e /home/linuxbrew ] || [ -L /home/linuxbrew ]; } \
                && { [ -L /home/linuxbrew ] || [ ! -d /home/linuxbrew ] || [ "$(stat -c %U:%G /home/linuxbrew)" != root:root ]; }; then
                printf 'Orbit Homebrew parent conflict: %s\n' /home/linuxbrew >&2
                exit 1
            fi
            install -d -m 0755 -o root -g root /home/linuxbrew

            if [ ! -e "$prefix" ] && [ ! -L "$prefix" ]; then
                stage=$(mktemp -d /home/linuxbrew/.orbit-homebrew.XXXXXX)
                cleanup_homebrew_stage() {
                    [ -z "${stage:-}" ] || rm -rf -- "$stage"
                }
                trap cleanup_homebrew_stage EXIT
                chown "$managed_user:$managed_group" "$stage"
                sudo -u "$managed_user" -H git clone --filter=blob:none --no-checkout \
                    "$expected_origin" "$stage/Homebrew"
                sudo -u "$managed_user" -H git -C "$stage/Homebrew" \
                    -c advice.detachedHead=false checkout --detach "$expected_revision"
                sudo -u "$managed_user" -H install -d -m 0775 \
                    "$stage/bin" "$stage/Cellar" "$stage/Caskroom" "$stage/Frameworks" \
                    "$stage/etc" "$stage/include" "$stage/lib" "$stage/opt" "$stage/sbin" \
                    "$stage/share" "$stage/var/homebrew/locks"
                sudo -u "$managed_user" -H ln -s ../Homebrew/bin/brew "$stage/bin/brew"
                mv -- "$stage" "$prefix"
                stage=
                trap - EXIT
            fi

            test ! -L "$prefix"
            test -d "$prefix"
            test "$(stat -c %U:%G "$prefix")" = "$managed_user:$managed_group"
            test ! -L "$repository"
            test -d "$repository/.git"
            test "$(stat -c %U:%G "$repository")" = "$managed_user:$managed_group"
            test "$(git -C "$repository" remote get-url origin)" = "$expected_origin"
            test "$(git -C "$repository" rev-parse HEAD)" = "$expected_revision"
            test -z "$(git -C "$repository" status --porcelain=v1 --untracked-files=all)"
            test -L "$prefix/bin/brew"
            test "$(readlink "$prefix/bin/brew")" = ../Homebrew/bin/brew
            test "$(stat -c %U:%G "$prefix/bin/brew")" = "$managed_user:$managed_group"
            test "$(sudo -u "$managed_user" -H env \
                HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_ANALYTICS=1 HOMEBREW_NO_ENV_HINTS=1 \
                PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin \
                "$prefix/bin/brew" --version | sed -n '1p')" = "$expected_version"
            sudo -u "$managed_user" -H env \
                HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_ANALYTICS=1 HOMEBREW_NO_ENV_HINTS=1 \
                PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin \
                "$prefix/bin/brew" config >/dev/null
            BASH;

        $result = $this->commands->execute($node, ['sudo', 'bash', '-seu', '--', $node->user], $program);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'materialize',
            message: 'The Homebrew manager could not be materialized.',
        );
    }

    public function managerVersion(Node $node): string
    {
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, [...self::PREFIX, '--version']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The Homebrew manager version probe failed.',
        );

        $version = $this->firstLine($result->stdout);

        if ($version !== 'Homebrew 6.0.6') {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The Homebrew manager version probe returned an unsupported version.',
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
                message: 'Homebrew does not provide a removal candidate version.',
            );
        }

        $architecture = $this->bottleArchitecture($node);
        $result = $this->commands->execute($node, [
            ...self::PREFIX,
            'info',
            '--json=v2',
            '--formula',
            $this->coordinate($package),
        ]);

        if (! $result->succeeded()) {
            if ($this->isKnownFormulaNotFound($result, $package)) {
                return null;
            }

            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The Homebrew formula metadata probe failed.',
                result: $result,
            );
        }

        return $this->parseCandidate($result, $package, $architecture);
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, [
            ...self::PREFIX,
            'list',
            '--versions',
            '--formula',
            $this->coordinate($package),
        ]);

        if (! $result->succeeded()) {
            if ($result->exitCode === 1 && $result->stdout === '' && $result->stderr === '') {
                return null;
            }

            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Homebrew installed version probe failed.',
                result: $result,
            );
        }

        $line = trim($result->stdout);
        $pattern = '/\A'.preg_quote($package, '/').' ([^\s]+)\z/D';
        $matched = preg_match($pattern, $line, $matches);
        $version = $matches[1] ?? null;

        if (
            strlen($line) > self::MAX_RESULT_LENGTH
            || $matched !== 1
            || ! is_string($version)
            || ! $this->isSafeVersion($version)
        ) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The Homebrew installed version probe returned malformed output.',
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
        $this->guardBottleEligibility($node, $package, ToolOperation::Install);
        $this->mutate($node, $package, 'install', [
            ...self::PREFIX,
            'install',
            '--formula',
            '--force-bottle',
            $this->coordinate($package),
        ]);
    }

    public function update(Node $node, string $package): void
    {
        $this->guardBottleEligibility($node, $package, ToolOperation::Update);
        $this->mutate($node, $package, 'update', [
            ...self::PREFIX,
            'upgrade',
            '--formula',
            '--force-bottle',
            $this->coordinate($package),
        ]);
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate($node, $package, 'remove', [
            ...self::PREFIX,
            'uninstall',
            '--formula',
            $this->coordinate($package),
        ]);
    }

    private function bottleArchitecture(Node $node): string
    {
        $result = $this->commands->execute($node, ['/usr/bin/uname', '-m']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The Homebrew bottle architecture probe failed.',
        );

        return match ($this->firstLine($result->stdout)) {
            'x86_64' => 'x86_64_linux',
            'aarch64', 'arm64' => 'arm64_linux',
            default => throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The node architecture has no supported Homebrew bottle.',
                result: $result,
            ),
        };
    }

    private function guardBottleEligibility(Node $node, string $package, ToolOperation $operation): void
    {
        $candidate = $this->candidateVersion($node, $package, $operation);

        if ($candidate !== null) {
            return;
        }

        throw new ToolManagerException(
            step: 'candidate-version',
            message: 'The Homebrew formula did not provide a compatible verified bottle.',
        );
    }

    private function parseCandidate(CommandResult $result, string $package, string $architecture): string
    {
        if (strlen($result->stdout) > self::MAX_RESULT_LENGTH) {
            throw $this->malformedCandidate($result);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($result->stdout, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The Homebrew formula metadata was malformed.',
                result: $result,
                previous: $exception,
            );
        }

        if (
            ! $decoded instanceof stdClass
            || ! is_array($decoded->formulae ?? null)
            || count($decoded->formulae) !== 1
            || ! is_array($decoded->casks ?? null)
            || $decoded->casks !== []
        ) {
            throw $this->malformedCandidate($result);
        }

        /** @var mixed $formula */
        $formula = $decoded->formulae[0];

        if (! $formula instanceof stdClass) {
            throw $this->malformedCandidate($result);
        }

        /** @var mixed $name */
        $name = $formula->name ?? null;
        /** @var mixed $fullName */
        $fullName = $formula->full_name ?? null;
        /** @var mixed $tap */
        $tap = $formula->tap ?? null;
        /** @var mixed $versions */
        $versions = $formula->versions ?? null;
        /** @var mixed $bottle */
        $bottle = $formula->bottle ?? null;

        if (
            $name !== $package
            || $fullName !== $package
            || $tap !== 'homebrew/core'
            || ! $versions instanceof stdClass
            || ! $bottle instanceof stdClass
            || ($formula->disabled ?? null) !== false
        ) {
            throw $this->malformedCandidate($result);
        }

        /** @var mixed $stableVersion */
        $stableVersion = $versions->stable ?? null;
        /** @var mixed $hasBottle */
        $hasBottle = $versions->bottle ?? null;
        /** @var mixed $stableBottle */
        $stableBottle = $bottle->stable ?? null;

        if (! is_string($stableVersion) || ! $this->isSafeVersion($stableVersion) || $hasBottle !== true) {
            throw $this->malformedCandidate($result);
        }

        /** @var mixed $files */
        $files = $stableBottle instanceof stdClass ? $stableBottle->files ?? null : null;

        if (! $files instanceof stdClass) {
            throw $this->malformedCandidate($result);
        }

        /** @var mixed $file */
        $file = $files->{$architecture} ?? null;

        if (! $file instanceof stdClass) {
            throw $this->malformedCandidate($result);
        }

        /** @var mixed $sha256 */
        $sha256 = $file->sha256 ?? null;
        /** @var mixed $url */
        $url = $file->url ?? null;

        if (
            ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || ! is_string($url)
            || ! str_starts_with($url, 'https://ghcr.io/v2/homebrew/core/')
            || ! str_ends_with($url, "sha256:{$sha256}")
        ) {
            throw $this->malformedCandidate($result);
        }

        return $stableVersion;
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The Homebrew formula name is invalid.',
        );
    }

    private function guardSupportedNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'Homebrew tools require a Linux node.',
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

    private function coordinate(string $package): string
    {
        return "homebrew/core/{$package}";
    }

    private function isKnownFormulaNotFound(CommandResult $result, string $package): bool
    {
        $output = implode("\n", [$result->stdout, $result->stderr]);

        if ($result->exitCode !== 1 || strlen($output) > self::MAX_RESULT_LENGTH) {
            return false;
        }

        return str_contains($output, "No available formula with the name \"homebrew/core/{$package}\"");
    }

    private function malformedCandidate(CommandResult $result): ToolManagerException
    {
        return new ToolManagerException(
            step: 'candidate-version',
            message: 'The Homebrew formula metadata was malformed or did not provide a compatible verified bottle.',
            result: $result,
        );
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
            message: "The Homebrew {$step} operation failed.",
        );
    }
}
