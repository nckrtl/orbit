<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * Resolves the enrolled account's existing Homebrew prefix and builds a fixed brew argv.
 * Formula and cask commands share this probe. It does not install, fetch, check out, or repin Homebrew.
 */
final readonly class HomebrewMacCommand
{
    public const int PREFIX_ABSENT = 42;

    public const int PREFIX_CONFLICT = 43;

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
    private const string PREFIX_SCRIPT = <<<'BASH'
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
            printf '%s\n%s\n' "$valid" "$home"
            exit 0
        fi
        if [ "$saw_conflict" -eq 1 ]; then
            printf 'Orbit Homebrew prefix conflict\n' >&2
            exit 43
        fi
        printf 'Orbit Homebrew prefix is absent\n' >&2
        exit 42
        BASH;

    public function __construct(private RemoteToolCommandRunner $commands) {}

    public function resolvePrefix(Node $node): string
    {
        return $this->resolveScope($node)->prefix;
    }

    /**
     * The prefix and the enrolled account home from one probe.
     * A one-line fixture leaves the home unknown. The live probe prints both.
     * An unsafe home is ignored so the prefix still works; classification stays conservative.
     */
    public function resolveScope(Node $node): HomebrewMacScope
    {
        $result = $this->commands->execute(
            $node,
            ['/bin/bash', '-su', '--', $node->user],
            self::PREFIX_SCRIPT,
        );

        if ($result->exitCode === self::PREFIX_ABSENT) {
            throw new ToolManagerException(
                step: 'manager-absent',
                message: 'The Homebrew prefix is absent for the enrolled account.',
                result: $result,
            );
        }

        if ($result->exitCode === self::PREFIX_CONFLICT) {
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
        $reportedHome = is_array($lines) && count($lines) === 2 ? $lines[1] : null;

        if (
            ! is_array($lines)
            || ! in_array(count($lines), [1, 2], true)
            || ! $this->isSafePrefix($prefix)
        ) {
            throw new ToolManagerException(
                step: 'manager-probe',
                message: 'The Homebrew prefix probe returned malformed output.',
                result: $result,
            );
        }

        $home = is_string($reportedHome) && $this->isSafeHome($reportedHome) ? $reportedHome : null;

        return new HomebrewMacScope($prefix, $home);
    }

    /**
     * The bottle tag formulae and casks share. arm64 prefixes the symbol; Intel uses the symbol alone.
     */
    public function bottleTag(Node $node): string
    {
        if ($node->architecture !== 'arm64' && $node->architecture !== 'x86_64') {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The node architecture has no supported Homebrew bottle.',
            );
        }

        $result = $this->commands->execute($node, ['/usr/bin/sw_vers', '-productVersion']);
        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The macOS product version probe failed.',
        );
        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? ($lines[0] ?? '') : '';

        if (
            $version === ''
            || strlen($version) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $version) === 1
            || preg_match('/\A(\d+)(?:\.\d+){0,2}\z/D', $version, $matches) !== 1
        ) {
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

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public function brew(Node $node, bool $refreshApi, array $arguments): array
    {
        return $this->command($this->resolvePrefix($node), $refreshApi, $arguments);
    }

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public function command(string $prefix, bool $refreshApi, array $arguments): array
    {
        $command = [
            'env',
            'HOMEBREW_NO_AUTO_UPDATE=1',
            'HOMEBREW_NO_ANALYTICS=1',
            'HOMEBREW_NO_ENV_HINTS=1',
            'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
            'HOMEBREW_NO_INSTALL_CLEANUP=1',
        ];

        if ($refreshApi) {
            $command[] = 'HOMEBREW_FORCE_API_AUTO_UPDATE=1';
        }

        $command[] = 'PATH='.$prefix.'/bin:/usr/bin:/bin';
        $command[] = $prefix.'/bin/brew';

        return [...$command, ...$arguments];
    }

    private function guardSuccessfulResult(CommandResult $result, string $step, string $message): void
    {
        if ($result->succeeded()) {
            return;
        }

        throw new ToolManagerException(step: $step, message: $message, result: $result);
    }

    private function isSafePrefix(string $prefix): bool
    {
        return preg_match(
            '/\A(?:\/opt\/homebrew|\/usr\/local|\/(?:[A-Za-z0-9._-]+\/)+homebrew|\/(?:[A-Za-z0-9._-]+\/)+\.homebrew)\z/D',
            $prefix,
        ) === 1 && ! str_contains($prefix, '..');
    }

    private function isSafeHome(string $home): bool
    {
        return $home !== '/'
            && ! str_contains($home, '..')
            && ! str_ends_with($home, '/')
            && preg_match('/\A\/[A-Za-z0-9._\/-]+\z/D', $home) === 1;
    }
}
