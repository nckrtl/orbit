<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Fleet\FleetReleaseAssetData;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

/**
 * Installs the Orbit CLI in the layout `orbit self-update` keeps: the release as `/usr/local/bin/orbit-<version>`
 * and `/usr/local/bin/orbit` as a link to it. The binary goes through a root-owned candidate, the SHA-256 from
 * the desired fleet state, a version check, and one rename, like the agent converge. The install holds the
 * Node's update lock ({@see NodeUpdateLock}), the lock `orbit self-update` holds.
 *
 * The link path decides what happens:
 *
 * | Found | Result |
 * | --- | --- |
 * | Nothing | The release is installed: `cli_installed`. |
 * | An Orbit CLI that has `self-update`: a root-owned ELF file that reports `Orbit 0.N.0` or `Orbit <commit>`, or a link to such an `orbit-0.N.0` beside it | Left alone: `cli_present`. `orbit self-update` replaces it. |
 * | Such a CLI without `self-update`, such as a pre-release build | An older CLI: the release is installed, the old file is kept as `orbit.orbit-previous`, and the link switched. |
 * | Another link, a script, another program, or a file another user owns | Refused with `cli.foreign_binary`; a script is never run. Orbit never replaces a CLI it did not install. |
 */
final readonly class SshNodeCliInstaller implements NodeCliInstaller
{
    /** How long the download may take to connect, and in total. */
    private const int DownloadConnectSeconds = 20;

    private const int DownloadSeconds = 180;

    /**
     * Prints `missing`, `foreign`, `present`, or `stale` for the link path. A link may name only an
     * `orbit-0.N.0` file beside it. The binary must be a root-owned ELF executable that reports
     * `Orbit 0.N.0` or a pre-release `Orbit <40-hex commit>`; anything else is foreign and never replaced.
     * A script, such as a wrapper around a source checkout, is never run. The checks run as the managed user.
     */
    public const string InspectScript = <<<'BASH'
        path=$1
        binary=$path
        if [ -L "$path" ]; then
          target=$(readlink -- "$path")
          if ! [[ "$target" =~ ^orbit-0\.[1-9][0-9]{0,9}\.0$ ]]; then echo foreign; exit 0; fi
          binary="${path%/*}/$target"
          if [ -L "$binary" ] || [ ! -f "$binary" ]; then echo foreign; exit 0; fi
        elif [ ! -e "$path" ]; then
          echo missing; exit 0
        fi
        if [ ! -f "$binary" ] || [ "$(stat -c %u -- "$binary")" != 0 ]; then echo foreign; exit 0; fi
        magic=$(head -c 4 -- "$binary" 2>/dev/null | od -An -tx1 | tr -d ' \n')
        if [ "$magic" != 7f454c46 ] || [ ! -x "$binary" ]; then echo foreign; exit 0; fi
        version=$(timeout 60 "$binary" --version 2>/dev/null | head -n 1 || true)
        if ! [[ "$version" =~ ^Orbit\ (0\.[1-9][0-9]{0,9}\.0|[0-9a-f]{40})$ ]]; then echo foreign; exit 0; fi
        if timeout 60 "$binary" self-update --help >/dev/null 2>&1; then
          echo present
        else
          echo stale
        fi
        BASH;

    /**
     * Switches the link to the installed release with one rename. A plain binary at the link path, such as a
     * pre-release build, is kept as `orbit.orbit-previous` first, as `orbit self-update` keeps it.
     */
    public const string LinkScript = <<<'BASH'
        link=$1
        release=$2
        candidate="$link.orbit-link"
        if [ -f "$link" ] && [ ! -L "$link" ]; then
          cp -p --remove-destination -- "$link" "${link%/*}/orbit.orbit-previous"
        fi
        rm -f -- "$candidate"
        ln -s -- "$release" "$candidate"
        mv -fT -- "$candidate" "$link"
        BASH;

    public function __construct(
        private NodeShell $shell,
        private NodeUpdateLock $updateLock,
    ) {}

    public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation
    {
        if ($node->platform !== 'linux') {
            throw new ResourceOperationException('cli.platform_unsupported', 'The Gateway installs the Orbit CLI on Linux Nodes only.', 422);
        }

        $version = null;
        $state = $this->inspect($node);

        if ($state === 'missing' || $state === 'stale') {
            $asset = $this->asset($node, $release);
            // Under the Node's update lock, so the install never runs inside an `orbit self-update`. The path is
            // read again under the lock, because a self-update may have changed it meanwhile.
            $version = $this->updateLock->run($node, function () use ($node, $asset, $release): ?string {
                $state = $this->inspect($node);

                if ($state === 'present') {
                    return null;
                }

                $this->guard($state);
                $this->install($node, $asset, (string) $release->version);

                return $release->version;
            });
        } else {
            $this->guard($state);
        }

        $configured = $this->configure($node);

        return new NodeCliInstallation(
            outcome: $version === null ? NodeCliInstallation::Present : NodeCliInstallation::Installed,
            configured: $configured,
            version: $version,
        );
    }

    public function inspect(Node $node): string
    {
        return trim($this->run($node, new RemoteCommand(
            ['bash', '-seu', '--', NodeCliFootprint::LinkPath],
            input: self::InspectScript,
        ), 'cli.inspection_failed')->stdout);
    }

    /** Refuses a foreign CLI and an unreadable inspection; lets `missing`, `stale`, and `present` through. */
    private function guard(string $state): void
    {
        if ($state === 'foreign') {
            throw new ResourceOperationException(
                'cli.foreign_binary',
                NodeCliFootprint::LinkPath.' on the Node is a link, a script, or a file Orbit did not install. Move it aside, then converge again.',
                409,
            );
        }

        if (! in_array($state, ['missing', 'stale', 'present'], true)) {
            throw new ResourceOperationException('cli.inspection_failed', 'The Orbit CLI on the Node could not be inspected.', 502);
        }
    }

    private function asset(Node $node, DesiredCliReleaseData $release): FleetReleaseAssetData
    {
        if (! $release->isAvailable() || $release->version === null) {
            throw new ResourceOperationException('cli.release_unavailable', 'The desired CLI release is not published yet.', 409);
        }

        $platform = NodeCliFootprint::platform(is_string($node->architecture) ? $node->architecture : '');

        foreach ($release->assets as $asset) {
            if ($asset->platform === $platform) {
                return $asset;
            }
        }

        throw new ResourceOperationException('cli.architecture_unsupported', "The CLI release has no binary for {$platform}.", 422);
    }

    /**
     * Downloads a candidate, checks its SHA-256 and that it reports the release version, moves it to
     * `orbit-<version>`, and switches the link. Any failure before the move removes the candidate and leaves
     * the link as it was.
     */
    private function install(Node $node, FleetReleaseAssetData $asset, string $version): void
    {
        $target = NodeCliFootprint::binaryPath($version);
        $candidate = $target.NodeCliFootprint::CandidateSuffix;
        $this->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]), 'cli.install_failed');
        $this->run($node, new RemoteCommand([
            'sudo', 'curl', '--fail', '--location', '--silent', '--show-error', '--proto', '=https', '--proto-redir', '=https',
            '--connect-timeout', (string) self::DownloadConnectSeconds, '--max-time', (string) self::DownloadSeconds,
            '--output', $candidate, '--', $asset->url,
        ]), 'cli.download_failed');
        $downloaded = $this->run($node, new RemoteCommand(['sudo', 'sha256sum', '--', $candidate]), 'cli.install_failed');

        if (! hash_equals($asset->sha256, $this->checksum($downloaded->stdout))) {
            $this->shell->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]));

            throw new ResourceOperationException('cli.checksum_mismatch', 'The downloaded Orbit CLI failed checksum verification.', 502);
        }

        $this->run($node, new RemoteCommand(['sudo', 'chown', 'root:root', '--', $candidate]), 'cli.install_failed');
        $this->run($node, new RemoteCommand(['sudo', 'chmod', '0755', '--', $candidate]), 'cli.install_failed');
        $reported = $this->shell->run($node, new RemoteCommand(['timeout', '60', $candidate, '--version']));

        if (! $reported->succeeded() || ! str_contains($reported->stdout, $version)) {
            $this->shell->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]));

            throw new ResourceOperationException('cli.candidate_invalid', "The downloaded Orbit CLI does not run or does not report version {$version}.", 502);
        }

        $this->run($node, new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, $target]), 'cli.install_failed');
        $this->run($node, new RemoteCommand(
            ['sudo', 'bash', '-seu', '--', NodeCliFootprint::LinkPath, basename($target)],
            input: self::LinkScript,
        ), 'cli.install_failed');
    }

    /** Writes root's CLI profile when it differs. Returns whether it wrote. */
    private function configure(Node $node): bool
    {
        $address = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
            ->value('wireguard_ip');

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('cli.configuration_failed', 'The active Gateway has no managed WireGuard address.', 409);
        }

        $contents = NodeCliFootprint::configuration($address);
        $path = NodeCliFootprint::ConfigurationPath;
        $current = $this->shell->run($node, new RemoteCommand(['sudo', 'cat', '--', $path]));
        $link = $this->shell->run($node, new RemoteCommand(['sudo', 'test', '-L', $path]));

        if ($current->succeeded() && $current->stdout === $contents && ! $link->succeeded()) {
            return false;
        }

        $candidate = $path.NodeCliFootprint::CandidateSuffix;
        $this->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]), 'cli.configuration_failed');
        $this->run($node, new RemoteCommand(['sudo', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', NodeCliFootprint::ConfigurationDirectory]), 'cli.configuration_failed');
        $this->run($node, new RemoteCommand(
            ['sudo', 'install', '-o', 'root', '-g', 'root', '-m', '0600', '/dev/stdin', $candidate],
            protectedInput: ProtectedInput::fromString($contents),
        ), 'cli.configuration_failed');
        $this->run($node, new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, $path]), 'cli.configuration_failed');

        return true;
    }

    private function checksum(string $output): string
    {
        return preg_split('/\s+/', trim($output), 2)[0] ?? '';
    }

    private function run(Node $node, RemoteCommand $command, string $errorCode): CommandResult
    {
        $result = $this->shell->run($node, $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, 'The Orbit CLI could not be installed or configured on the Node.', 502);
        }

        return $result;
    }
}
