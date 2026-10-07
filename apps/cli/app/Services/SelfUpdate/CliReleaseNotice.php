<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Closure;
use JsonException;

/**
 * Tells the operator, at most once per interval, that the Gateway's fleet runs a newer CLI release (ADR 0202).
 * The Gateway names that release in the `X-Orbit-Cli-Version` response header. The time of the last notice is
 * kept in `$ORBIT_HOME/self-update-notice.json`. Only a release binary compares versions: a source checkout or
 * another build never gets the notice. It goes only to a terminal on standard error, and never under CI.
 */
final class CliReleaseNotice
{
    public const string StateFile = 'self-update-notice.json';

    public const int IntervalSeconds = 86_400;

    private const string ReleaseVersion = '/\A0\.([1-9][0-9]{0,9})\.0\z/D';

    private ?string $desiredVersion = null;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var Closure(mixed): bool */
    private readonly Closure $isTerminal;

    /**
     * @param  (Closure(): int)|null  $clock
     * @param  (Closure(mixed): bool)|null  $isTerminal  Whether a stream is a terminal.
     */
    public function __construct(
        private readonly string $statePath,
        private readonly string $currentVersion,
        private readonly int $intervalSeconds = self::IntervalSeconds,
        ?Closure $clock = null,
        ?Closure $isTerminal = null,
    ) {
        $this->clock = $clock ?? time(...);
        $this->isTerminal = $isTerminal ?? static fn (mixed $stream): bool => is_resource($stream) && stream_isatty($stream);
    }

    /** Remembers the newest release a Gateway response named. */
    public function observe(?string $desiredVersion): void
    {
        $desired = self::number($desiredVersion);

        if ($desired !== null && $desired > (self::number($this->desiredVersion) ?? 0)) {
            $this->desiredVersion = $desiredVersion;
        }
    }

    /**
     * The notice to print now on `$stream`, or null. It is printed only to a terminal and never under CI. A
     * returned notice is recorded, so the next one waits a full interval.
     *
     * @param  resource|null  $stream
     */
    public function due(mixed $stream): ?string
    {
        $current = self::number($this->currentVersion);
        $desired = self::number($this->desiredVersion);
        $ci = getenv('CI');

        if ($current === null || $desired === null || $desired <= $current || ! ($this->isTerminal)($stream) || ($ci !== false && $ci !== '')) {
            return null;
        }

        if (! $this->intervalElapsed()) {
            return null;
        }

        if (! $this->record()) {
            return null;
        }

        return "Orbit {$this->desiredVersion} is available (this is {$this->currentVersion}). Run orbit self-update.";
    }

    private static function number(?string $version): ?int
    {
        return is_string($version) && preg_match(self::ReleaseVersion, $version, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function intervalElapsed(): bool
    {
        if (! is_file($this->statePath)) {
            return true;
        }

        try {
            $state = json_decode((string) @file_get_contents($this->statePath, length: 4096), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return true;
        }

        $notifiedAt = is_array($state) ? ($state['notified_at'] ?? null) : null;

        return ! is_int($notifiedAt) || ($this->clock)() - $notifiedAt >= $this->intervalSeconds || $notifiedAt > ($this->clock)();
    }

    /** Writes the notice time privately through a rename. Without a record no notice is printed, so it never repeats on every command. */
    private function record(): bool
    {
        $directory = dirname($this->statePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true)) {
            return false;
        }

        $candidate = $this->statePath.'.'.bin2hex(random_bytes(6)).'.tmp';
        $contents = json_encode(['notified_at' => ($this->clock)(), 'version' => $this->desiredVersion], JSON_THROW_ON_ERROR)."\n";

        if (@file_put_contents($candidate, $contents) === false || ! @chmod($candidate, 0600) || ! @rename($candidate, $this->statePath)) {
            @unlink($candidate);

            return false;
        }

        return true;
    }
}
