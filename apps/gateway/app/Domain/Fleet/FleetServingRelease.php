<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Throwable;

/**
 * What the Gateway serves right now, read from disk on every call rather than from this process's own
 * configuration. A rollout process started from an older release would otherwise keep rolling out the
 * older desired state after a newer release went current.
 *
 * - `adopted`: the current path links to a release, and `commit` is that release's full commit.
 * - `in_place`: the current path is a checkout; it has no release records, and `commit` is null.
 * - `unreadable`: the configured layout is invalid, or the link names no complete release. The rollout
 *   fails closed and rolls out nothing.
 */
final readonly class FleetServingRelease
{
    /** @param string|null $ownApplicationPath  The `apps/gateway` directory this process runs from; the base path by default. */
    public function __construct(private ?string $ownApplicationPath = null) {}

    /**
     * Whether the current link serves another release directory than the one this process runs from. Only a
     * Gateway in the release layout can be superseded; an unreadable layout is not, and the gate refuses it.
     */
    public function supersedes(): bool
    {
        try {
            $layout = GatewayReleaseLayout::fromConfig();

            if (! is_link($layout->currentPath())) {
                return false;
            }

            $serving = realpath($layout->currentPath().'/apps/gateway');
            $own = realpath($this->ownApplicationPath ?? base_path());
        } catch (Throwable) {
            return false;
        }

        return is_string($serving) && is_string($own) && $serving !== $own;
    }

    public const string Adopted = 'adopted';

    public const string InPlace = 'in_place';

    public const string Unreadable = 'unreadable';

    /** @return array{state: string, commit: ?string} */
    public function read(): array
    {
        try {
            $layout = GatewayReleaseLayout::fromConfig();
            $current = $layout->currentPath();

            if (! is_link($current)) {
                return is_dir($current)
                    ? ['state' => self::InPlace, 'commit' => null]
                    : ['state' => self::Unreadable, 'commit' => null];
            }

            $id = $layout->currentReleaseId();
            $commit = $id === null ? null : $layout->preparedCommit($id);
        } catch (Throwable) {
            return ['state' => self::Unreadable, 'commit' => null];
        }

        return $commit === null
            ? ['state' => self::Unreadable, 'commit' => null]
            : ['state' => self::Adopted, 'commit' => $commit];
    }
}
