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

    private const string EXPECTED_REVISION = 'd79ef822ab8136e393ed5f86e2b56afc68d04874';

    private const string EXPECTED_VERSION = 'Homebrew 7.0.0';

    private const int MAX_PACKAGE_LENGTH = 255;

    private const int MAX_RESULT_LENGTH = 131_072;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A[a-z0-9](?:[a-z0-9@+._-]*[a-z0-9])?\z/D';

    private const int MAC_PREFIX_ABSENT = 42;

    private const int MAC_PREFIX_CONFLICT = 43;

    /** @var array<int, string> */
    private const array MACOS_BOTTLE_SYMBOLS = [
        27 => 'golden_gate',
        26 => 'tahoe',
        15 => 'sequoia',
        14 => 'sonoma',
        13 => 'ventura',
        12 => 'monterey',
        11 => 'big_sur',
    ];

    /**
     * Verifies one existing macOS prefix owned by the enrolled account.
     * It does not install, fetch, check out, or repin Homebrew.
     */
    private const string MAC_PREFIX_SCRIPT = <<<'BASH'
        account=$1
        if [ -z "${account:-}" ]; then
            printf 'Orbit Homebrew account is missing\n' >&2
            exit 1
        fi
        current=$(/usr/bin/id -un)
        if [ "$current" != "$account" ]; then
            printf 'Orbit Homebrew account mismatch\n' >&2
            exit 1
        fi
        home=$(/usr/bin/dscacheutil -q user -a name "$account" | /usr/bin/awk '/^dir: / { print substr($0, 6); exit }')
        if [ -z "${home:-}" ] || [ ! -d "$home" ]; then
            printf 'Orbit Homebrew account home is unreadable\n' >&2
            exit 1
        fi
        case "$home" in
            *..*|*[!/A-Za-z0-9._-]*)
                printf 'Orbit Homebrew account home is unreadable\n' >&2
                exit 1
                ;;
        esac

        valid=
        saw_conflict=0
        consider() {
            prefix=$1
            brew_link="$prefix/bin/brew"
            nested="$prefix/Homebrew"
            if [ ! -e "$brew_link" ] && [ ! -L "$brew_link" ] \
                && [ ! -e "$prefix/.git" ] && [ ! -L "$prefix/.git" ] \
                && [ ! -e "$nested" ] && [ ! -L "$nested" ]; then
                return 0
            fi
            if [ -L "$prefix" ] || [ ! -d "$prefix" ]; then
                saw_conflict=1
                return 0
            fi
            owner=$(/usr/bin/stat -f '%Su' "$prefix" 2>/dev/null || true)
            if [ "$owner" != "$account" ]; then
                saw_conflict=1
                return 0
            fi
            prefix_repo=0
            nested_repo=0
            if [ -d "$prefix/.git" ] && [ ! -L "$prefix/.git" ]; then
                prefix_repo=1
            fi
            if [ -d "$nested/.git" ] && [ ! -L "$nested" ] && [ ! -L "$nested/.git" ]; then
                nested_repo=1
            fi
            if [ "$prefix_repo" -eq "$nested_repo" ]; then
                saw_conflict=1
                return 0
            fi
            if [ "$prefix_repo" -eq 1 ]; then
                repo_owner=$(/usr/bin/stat -f '%Su' "$prefix/.git" 2>/dev/null || true)
                origin=$(/usr/bin/git -C "$prefix" config --get remote.origin.url 2>/dev/null || true)
                brew_owner=$(/usr/bin/stat -f '%Su' "$brew_link" 2>/dev/null || true)
                if [ "$repo_owner" != "$account" ] \
                    || [ "$origin" != "https://github.com/Homebrew/brew" ] \
                    || [ -L "$brew_link" ] || [ ! -f "$brew_link" ] || [ ! -x "$brew_link" ] \
                    || [ "$brew_owner" != "$account" ]; then
                    saw_conflict=1
                    return 0
                fi
            else
                repo_owner=$(/usr/bin/stat -f '%Su' "$nested" 2>/dev/null || true)
                origin=$(/usr/bin/git -C "$nested" config --get remote.origin.url 2>/dev/null || true)
                link_owner=$(/usr/bin/stat -f '%Su' "$brew_link" 2>/dev/null || true)
                target=$(/usr/bin/readlink "$brew_link" 2>/dev/null || true)
                if [ "$repo_owner" != "$account" ] \
                    || [ "$origin" != "https://github.com/Homebrew/brew" ] \
                    || [ ! -L "$brew_link" ] || [ "$link_owner" != "$account" ] \
                    || [ "$target" != "../Homebrew/bin/brew" ]; then
                    saw_conflict=1
                    return 0
                fi
            fi
            if [ -n "$valid" ]; then
                saw_conflict=1
                valid=
                return 0
            fi
            valid=$prefix
        }

        consider /opt/homebrew
        consider /usr/local
        consider "$home/homebrew"
        consider "$home/.homebrew"

        if [ -n "$valid" ] && [ "$saw_conflict" -eq 0 ]; then
            printf '%s\n' "$valid"
            exit 0
        fi
        if [ "$saw_conflict" -eq 1 ]; then
            printf 'Orbit Homebrew prefix conflict\n' >&2
            exit 43
        fi
        printf 'Orbit Homebrew prefix is absent\n' >&2
        exit 42
        BASH;

    /** @var non-empty-list<string> */
    private const array LINUX_PREFIX = [
        'env',
        'HOMEBREW_NO_AUTO_UPDATE=1',
        'HOMEBREW_NO_ANALYTICS=1',
        'HOMEBREW_NO_ENV_HINTS=1',
        'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
        'HOMEBREW_NO_INSTALL_CLEANUP=1',
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
        return $node->platform === 'linux' || $node->platform === 'macos';
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

        if ($node->platform === 'macos') {
            $this->resolveMacPrefix($node);

            return;
        }

        $program = strtr(<<<'BASH'
            managed_user=$1
            prefix=/home/linuxbrew/.linuxbrew
            repository=$prefix/Homebrew
            expected_origin=https://github.com/Homebrew/brew
            expected_revision=__ORBIT_HOMEBREW_REVISION__
            expected_version='__ORBIT_HOMEBREW_VERSION__'
            expected_tag=${expected_version#Homebrew }

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

            current_revision=$(git -C "$repository" rev-parse HEAD)
            if [ "$current_revision" != "$expected_revision" ]; then
                test ! -L "$prefix"
                test -d "$prefix"
                test "$(stat -c %U:%G "$prefix")" = "$managed_user:$managed_group"
                test ! -L "$repository"
                test -d "$repository/.git"
                test "$(stat -c %U:%G "$repository")" = "$managed_user:$managed_group"
                test "$(git -C "$repository" config --get remote.origin.url)" = "$expected_origin"
                test -z "$(git -C "$repository" status --porcelain=v1 --untracked-files=all)"
                test -L "$prefix/bin/brew"
                test "$(readlink "$prefix/bin/brew")" = ../Homebrew/bin/brew
                test "$(stat -c %U:%G "$prefix/bin/brew")" = "$managed_user:$managed_group"
                sudo -u "$managed_user" -H git -C "$repository" fetch --filter=blob:none origin "$expected_revision"
                sudo -u "$managed_user" -H git -C "$repository" \
                    -c advice.detachedHead=false checkout --detach "$expected_revision"
            fi

            test ! -L "$prefix"
            test -d "$prefix"
            test "$(stat -c %U:%G "$prefix")" = "$managed_user:$managed_group"
            test ! -L "$repository"
            test -d "$repository/.git"
            test "$(stat -c %U:%G "$repository")" = "$managed_user:$managed_group"
            test "$(git -C "$repository" config --get remote.origin.url)" = "$expected_origin"
            test "$(git -C "$repository" rev-parse HEAD)" = "$expected_revision"
            test -z "$(git -C "$repository" status --porcelain=v1 --untracked-files=all)"
            test -L "$prefix/bin/brew"
            test "$(readlink "$prefix/bin/brew")" = ../Homebrew/bin/brew
            test "$(stat -c %U:%G "$prefix/bin/brew")" = "$managed_user:$managed_group"
            if ! git -C "$repository" show-ref --verify --quiet "refs/tags/$expected_tag"; then
                sudo -u "$managed_user" -H git -C "$repository" fetch --filter=blob:none origin tag "$expected_tag"
                sudo -u "$managed_user" -H rm -rf -- "$repository/.git/describe-cache"
            fi
            test "$(git -C "$repository" rev-parse --verify "$expected_tag^{commit}")" = "$expected_revision"
            test "$(sudo -u "$managed_user" -H env \
                HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_ANALYTICS=1 HOMEBREW_NO_ENV_HINTS=1 \
                HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1 HOMEBREW_NO_INSTALL_CLEANUP=1 \
                PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin \
                "$prefix/bin/brew" --version | sed -n '1p')" = "$expected_version"
            sudo -u "$managed_user" -H env \
                HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_ANALYTICS=1 HOMEBREW_NO_ENV_HINTS=1 \
                HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1 HOMEBREW_NO_INSTALL_CLEANUP=1 \
                PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin \
                "$prefix/bin/brew" config >/dev/null
            BASH, [
            '__ORBIT_HOMEBREW_REVISION__' => self::EXPECTED_REVISION,
            '__ORBIT_HOMEBREW_VERSION__' => self::EXPECTED_VERSION,
        ]);

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

        $result = $this->commands->execute($node, $this->brewArguments($node, false, ['--version']));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The Homebrew manager version probe failed.',
        );

        $version = $this->firstLine($result->stdout);

        if ($node->platform === 'macos') {
            if (preg_match('/\AHomebrew \d+\.\d+\.\d+\z/D', $version) !== 1) {
                throw new ToolManagerException(
                    step: 'manager-version',
                    message: 'The Homebrew manager version probe returned malformed output.',
                    result: $result,
                );
            }

            return $version;
        }

        if ($version !== self::EXPECTED_VERSION) {
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

        if ($node->platform === 'macos') {
            $this->guardMacCpu($node);
            $prefix = $this->resolveMacPrefix($node);
            $bottleTag = $this->macOsBottleTag($node);
            $command = [
                ...$this->macCommandPrefix($prefix, true),
                'info',
                '--json=v2',
                '--formula',
                $this->coordinate($package),
            ];
        } else {
            $bottleTag = $this->linuxBottleTag($node);
            $command = [
                ...self::LINUX_PREFIX,
                'info',
                '--json=v2',
                '--formula',
                $this->coordinate($package),
            ];
        }

        $result = $this->commands->execute($node, $command);

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

        return $this->parseCandidate($result, $package, $bottleTag);
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardPackage($package);
        $this->guardSupportedNode($node);

        $result = $this->commands->execute($node, $this->brewArguments($node, false, [
            'list',
            '--versions',
            '--formula',
            $this->coordinate($package),
        ]));

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
        $this->mutate($node, $package, 'install', $this->brewArguments($node, $node->platform === 'macos', [
            'install',
            '--formula',
            '--force-bottle',
            $this->coordinate($package),
        ]));
    }

    public function update(Node $node, string $package): void
    {
        $this->guardBottleEligibility($node, $package, ToolOperation::Update);
        $this->mutate($node, $package, 'update', $this->brewArguments($node, $node->platform === 'macos', [
            'upgrade',
            '--formula',
            '--force-bottle',
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
        $this->mutate($node, $package, 'remove', $this->brewArguments($node, false, [
            'uninstall',
            '--formula',
            $this->coordinate($package),
        ]));
    }

    private function linuxBottleTag(Node $node): string
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

    private function macOsBottleTag(Node $node): string
    {
        $this->guardMacCpu($node);
        $result = $this->commands->execute($node, ['/usr/bin/sw_vers', '-productVersion']);
        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The macOS product version probe failed.',
        );
        $version = $this->firstLine($result->stdout);

        if (preg_match('/\A(\d+)(?:\.\d+){0,2}\z/D', $version, $matches) !== 1) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The macOS product version probe returned malformed output.',
                result: $result,
            );
        }

        $symbol = self::MACOS_BOTTLE_SYMBOLS[(int) $matches[1]] ?? null;

        if (! is_string($symbol)) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The macOS product version has no compatible Homebrew bottle.',
                result: $result,
            );
        }

        return $node->architecture === 'arm64' ? 'arm64_'.$symbol : $symbol;
    }

    private function guardMacCpu(Node $node): void
    {
        if ($node->architecture === 'arm64' || $node->architecture === 'x86_64') {
            return;
        }

        throw new ToolManagerException(
            step: 'candidate-version',
            message: 'The node architecture has no supported Homebrew bottle.',
        );
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

        $formula = $decoded->formulae[0];

        if (! $formula instanceof stdClass) {
            throw $this->malformedCandidate($result);
        }

        $name = $formula->name ?? null;
        $fullName = $formula->full_name ?? null;
        $tap = $formula->tap ?? null;
        $versions = $formula->versions ?? null;
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

        $stableVersion = $versions->stable ?? null;
        $hasBottle = $versions->bottle ?? null;
        $stableBottle = $bottle->stable ?? null;

        if (! is_string($stableVersion) || ! $this->isSafeVersion($stableVersion) || $hasBottle !== true) {
            throw $this->malformedCandidate($result);
        }

        $files = $stableBottle instanceof stdClass ? $stableBottle->files ?? null : null;

        if (! $files instanceof stdClass) {
            throw $this->malformedCandidate($result);
        }

        if (property_exists($files, $architecture)) {
            $file = $files->{$architecture};
        } else {
            $file = $files->all ?? null;
        }

        if (! $file instanceof stdClass) {
            throw $this->malformedCandidate($result);
        }

        $sha256 = $file->sha256 ?? null;
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
            message: 'Homebrew tools require a Linux or macOS node.',
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

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    private function brewArguments(Node $node, bool $refreshApi, array $arguments): array
    {
        if ($node->platform !== 'macos') {
            return [...self::LINUX_PREFIX, ...$arguments];
        }

        return [
            ...$this->macCommandPrefix($this->resolveMacPrefix($node), $refreshApi),
            ...$arguments,
        ];
    }

    /**
     * @return non-empty-list<string>
     */
    private function macCommandPrefix(string $prefix, bool $refreshApi): array
    {
        $arguments = [
            'env',
            'HOMEBREW_NO_AUTO_UPDATE=1',
            'HOMEBREW_NO_ANALYTICS=1',
            'HOMEBREW_NO_ENV_HINTS=1',
            'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
            'HOMEBREW_NO_INSTALL_CLEANUP=1',
        ];

        if ($refreshApi) {
            $arguments[] = 'HOMEBREW_FORCE_API_AUTO_UPDATE=1';
        }

        $arguments[] = 'PATH='.$prefix.'/bin:/usr/bin:/bin';
        $arguments[] = $prefix.'/bin/brew';

        return $arguments;
    }

    private function resolveMacPrefix(Node $node): string
    {
        $result = $this->commands->execute(
            $node,
            ['/bin/bash', '-su', '--', $node->user],
            self::MAC_PREFIX_SCRIPT,
        );

        if ($result->exitCode === self::MAC_PREFIX_ABSENT) {
            throw new ToolManagerException(
                step: 'manager-absent',
                message: 'The Homebrew prefix is absent for the enrolled account.',
                result: $result,
            );
        }

        if ($result->exitCode === self::MAC_PREFIX_CONFLICT) {
            throw new ToolManagerException(
                step: 'manager-conflict',
                message: 'The Homebrew prefix ownership or origin conflicts with the enrolled account.',
                result: $result,
            );
        }

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-probe',
            message: 'The Homebrew prefix probe failed.',
        );

        $lines = preg_split('/\R/', rtrim($result->stdout, "\r\n"));
        $prefix = is_array($lines) ? ($lines[0] ?? '') : '';

        if (! is_array($lines) || count($lines) !== 1 || ! $this->isSafeMacPrefix($prefix)) {
            throw new ToolManagerException(
                step: 'manager-probe',
                message: 'The Homebrew prefix probe returned malformed output.',
                result: $result,
            );
        }

        return $prefix;
    }

    private function isSafeMacPrefix(string $prefix): bool
    {
        return preg_match(
            '/\A(?:\/opt\/homebrew|\/usr\/local|\/(?:[A-Za-z0-9._-]+\/)+homebrew|\/(?:[A-Za-z0-9._-]+\/)+\.homebrew)\z/D',
            $prefix,
        ) === 1 && ! str_contains($prefix, '..');
    }
}
