<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Models\GatewayRelease;
use Carbon\CarbonImmutable;

/**
 * The durable state of automatic Gateway releases, kept in the Gateway settings
 * ([Automatic releases](/reference/gateway-recovery#automatic-releases)):
 *
 * - whether automatic releases are enabled. They are disabled until an operator enables them;
 * - whether they are paused, and why. A pause is durable state with a reason: a release that
 *   failed after its migrations ran (`migration_failure`), a verified rollback (`rollback`), a manual
 *   deploy of a commit that is not the newest green one (`manual_deploy`), or a release that died
 *   after it touched the schema or the current link (`interrupted`). The pause marker
 *   `ORBIT_HOME/gateway-release.paused` alone pauses too (`marker`). Only `resume` clears a pause,
 *   apart from a manual or automatic release that goes live;
 * - the last tick of the runner and a stall: how long a transient cause has kept releases from
 *   making progress, and whether that stall already alerted;
 * - the branch head the runner last read, and since when it differs from the deployed commit.
 *
 * @phpstan-type Tick array{checked_at: string, result: string, sha: string|null, record: int|null, error_code: string|null, message: string|null}
 * @phpstan-type Pause array{reason: string, since: string|null, record: int|null, release: string|null, sha: string|null, error_code: string|null, snapshot: string|null}
 * @phpstan-type Stall array{since: string, alerted: bool}
 * @phpstan-type BranchHead array{head: string, deployed: string, checked_at: string, behind_since: string|null, alerted: bool}
 */
final readonly class GatewayReleaseAutomation
{
    public const string EnabledKey = 'gateway.release.auto.enabled';

    public const string ResumedAfterKey = 'gateway.release.auto.resumed_after';

    public const string LastTickKey = 'gateway.release.auto.last_tick';

    public const string StallKey = 'gateway.release.auto.stall';

    public const string BranchHeadKey = 'gateway.release.auto.branch_head';

    public const string PauseKey = 'gateway.release.auto.pause';

    /** @var list<string> */
    public const array PauseReasons = ['migration_failure', 'rollback', 'manual_deploy', 'interrupted', 'marker'];

    public function __construct(
        private SettingRepository $settings,
        private string $pauseMarker,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->get($this->scope(), self::EnabledKey) === '1';
    }

    public function enable(): void
    {
        $this->settings->put($this->scope(), self::EnabledKey, '1');
    }

    public function disable(): void
    {
        $this->settings->put($this->scope(), self::EnabledKey, '0');
    }

    /**
     * Reads the stored pause each time: a settled release can pause between two calls.
     *
     * @return Pause|null
     *
     * @phpstan-impure
     */
    public function pause(): ?array
    {
        $pause = $this->json(self::PauseKey);

        if ($pause !== null && is_string($pause['reason'] ?? null)) {
            return [
                'reason' => $pause['reason'],
                'since' => $this->text($pause, 'since'),
                'record' => is_int($pause['record'] ?? null) ? $pause['record'] : null,
                'release' => $this->text($pause, 'release'),
                'sha' => $this->text($pause, 'sha'),
                'error_code' => $this->text($pause, 'error_code'),
                'snapshot' => $this->text($pause, 'snapshot'),
            ];
        }

        $marker = $this->marker();

        if ($marker === null) {
            return null;
        }

        return [
            'reason' => 'marker',
            'since' => null,
            'record' => null,
            'release' => $this->text($marker, 'release'),
            'sha' => $this->text($marker, 'sha'),
            'error_code' => $this->text($marker, 'error_code'),
            'snapshot' => $this->text($marker, 'snapshot'),
        ];
    }

    /**
     * Pauses automatic releases until `resume`, naming the record that caused it.
     *
     * @return Pause
     */
    public function pauseFor(string $reason, ?GatewayRelease $record = null): array
    {
        $pause = [
            'reason' => in_array($reason, self::PauseReasons, true) ? $reason : 'marker',
            'since' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'record' => $record?->id,
            'release' => $record?->release_id,
            'sha' => $record?->sha,
            'error_code' => $record?->error_code,
            'snapshot' => $record?->snapshot_path,
        ];
        $this->settings->put($this->scope(), self::PauseKey, json_encode($pause, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $pause;
    }

    /** Ends a pause without a resume point, because a release went live. */
    public function clearPause(): void
    {
        if ($this->settings->get($this->scope(), self::PauseKey) !== null) {
            $this->settings->delete($this->scope(), self::PauseKey);
        }

        if (is_file($this->pauseMarker)) {
            @unlink($this->pauseMarker);
        }
    }

    /**
     * Clears a pause after the operator decided what to do. Returns the pause it cleared, or null
     * when automatic releases were not paused. Records up to now no longer count as manual
     * releases, so the next tick releases the newest green commit.
     *
     * @return Pause|null
     */
    public function resume(): ?array
    {
        $pause = $this->pause();

        if ($pause === null) {
            return null;
        }

        $this->clearPause();
        $newest = GatewayRelease::query()->max('id');
        $this->settings->put($this->scope(), self::ResumedAfterKey, is_numeric($newest) ? (string) $newest : '0');

        return $pause;
    }

    /** The newest record id that a resume accepted, or 0. */
    public function resumedAfter(): int
    {
        $value = $this->settings->get($this->scope(), self::ResumedAfterKey);

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return Tick|null */
    public function lastTick(): ?array
    {
        $tick = $this->json(self::LastTickKey);

        if ($tick === null || ! is_string($tick['checked_at'] ?? null) || ! is_string($tick['result'] ?? null)) {
            return null;
        }

        return [
            'checked_at' => $tick['checked_at'],
            'result' => $tick['result'],
            'sha' => is_string($tick['sha'] ?? null) ? $tick['sha'] : null,
            'record' => is_int($tick['record'] ?? null) ? $tick['record'] : null,
            'error_code' => is_string($tick['error_code'] ?? null) ? $tick['error_code'] : null,
            'message' => is_string($tick['message'] ?? null) ? $tick['message'] : null,
        ];
    }

    /** @return Tick */
    public function recordTick(string $result, ?string $sha = null, ?int $record = null, ?string $errorCode = null, ?string $message = null): array
    {
        $tick = [
            'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'result' => $result,
            'sha' => $sha,
            'record' => $record,
            'error_code' => $errorCode,
            'message' => $message,
        ];
        $this->settings->put($this->scope(), self::LastTickKey, json_encode($tick, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $tick;
    }

    /** @return Stall|null */
    public function stall(): ?array
    {
        $stall = $this->json(self::StallKey);

        if ($stall === null || ! is_string($stall['since'] ?? null)) {
            return null;
        }

        return ['since' => $stall['since'], 'alerted' => ($stall['alerted'] ?? false) === true];
    }

    /**
     * Starts a stall, or keeps the one that already runs.
     *
     * @return Stall
     */
    public function stalled(): array
    {
        $stall = $this->stall();

        if ($stall !== null) {
            return $stall;
        }

        $stall = ['since' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'alerted' => false];
        $this->settings->put($this->scope(), self::StallKey, json_encode($stall, JSON_THROW_ON_ERROR));

        return $stall;
    }

    public function stallAlerted(): void
    {
        $stall = $this->stalled();
        $this->settings->put($this->scope(), self::StallKey, json_encode([...$stall, 'alerted' => true], JSON_THROW_ON_ERROR));
    }

    public function clearStall(): void
    {
        if ($this->stall() !== null) {
            $this->settings->delete($this->scope(), self::StallKey);
        }
    }

    /** @return BranchHead|null */
    public function branchHead(): ?array
    {
        $state = $this->json(self::BranchHeadKey);

        if ($state === null || ! is_string($state['head'] ?? null) || ! is_string($state['deployed'] ?? null) || ! is_string($state['checked_at'] ?? null)) {
            return null;
        }

        return [
            'head' => $state['head'],
            'deployed' => $state['deployed'],
            'checked_at' => $state['checked_at'],
            'behind_since' => is_string($state['behind_since'] ?? null) ? $state['behind_since'] : null,
            'alerted' => ($state['alerted'] ?? false) === true,
        ];
    }

    /**
     * Records a branch head read. A head that differs from the deployed commit keeps the time it
     * first differed, as long as the same commit stays deployed.
     *
     * @return BranchHead
     */
    public function recordBranchHead(string $head, string $deployed): array
    {
        $previous = $this->branchHead();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $continues = $previous !== null && $previous['deployed'] === $deployed && $previous['behind_since'] !== null;
        $state = [
            'head' => $head,
            'deployed' => $deployed,
            'checked_at' => $now,
            'behind_since' => $head === $deployed ? null : ($continues ? $previous['behind_since'] : $now),
            'alerted' => $head !== $deployed && $continues && $previous['alerted'],
        ];
        $this->settings->put($this->scope(), self::BranchHeadKey, json_encode($state, JSON_THROW_ON_ERROR));

        return $state;
    }

    public function branchHeadAlerted(): void
    {
        $state = $this->branchHead();

        if ($state !== null) {
            $this->settings->put($this->scope(), self::BranchHeadKey, json_encode([...$state, 'alerted' => true], JSON_THROW_ON_ERROR));
        }
    }

    /** @return array<string, mixed>|null */
    private function marker(): ?array
    {
        if (! is_file($this->pauseMarker)) {
            return null;
        }

        return $this->decode((string) @file_get_contents($this->pauseMarker)) ?? [];
    }

    /** @return array<string, mixed>|null */
    private function json(string $key): ?array
    {
        $value = $this->settings->get($this->scope(), $key);

        return is_string($value) ? $this->decode($value) : null;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        $values = [];

        foreach ($decoded as $name => $item) {
            $values[(string) $name] = $item;
        }

        return $values;
    }

    /** @param array<string, mixed>|null $values */
    private function text(?array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
