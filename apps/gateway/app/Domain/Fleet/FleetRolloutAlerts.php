<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * Raises the `rollout_halted` release alert for a halted fleet rollout: an Activity entry, a filed
 * problem, and the signed webhook ([Release alerts](/reference/gateway-recovery#release-alerts)).
 * The receipt is stored on the rollout. It runs after the halt is written, outside a transaction.
 */
final readonly class FleetRolloutAlerts
{
    public const string Target = 'fleet';

    /** How many visits in a row a Node may stay `incomplete` before the stalled alert: 6 catch-ups, 30 minutes. */
    public const int IncompleteVisits = 6;

    public function __construct(private ReleaseAlertNotifier $notifier) {}

    public function halted(FleetRollout $rollout, FleetRolloutNode $node): void
    {
        $summary = sprintf(
            'Fleet rollout %d halted on node %s at step %s: %s',
            $rollout->id,
            $node->node_name,
            $node->step ?? 'unknown',
            $node->message ?? (string) $node->error_code,
        );
        $stored = $this->raise(ReleaseAlertKind::RolloutHalted, $rollout, $summary);

        try {
            $rollout->forceFill(['alert' => $stored])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Raises `rollout_stalled` once for a Node whose `orbit self-update` stayed `incomplete` for
     * {@see self::IncompleteVisits} visits in a row. The rollout does not halt. Returns the receipt.
     *
     * @return array<string, mixed>
     */
    public function stalled(FleetRollout $rollout, FleetRolloutNode $node, int $visits): array
    {
        return $this->raise(ReleaseAlertKind::RolloutStalled, $rollout, sprintf(
            'Fleet rollout %d: orbit self-update on node %s stayed incomplete for %d visits: %s',
            $rollout->id,
            $node->node_name,
            $visits,
            $node->message ?? (string) $node->error_code,
        ));
    }

    /** Raises `rollout_stalled` once for a rollout that waited too long for its CLI release, and stores it. */
    public function waiting(FleetRollout $rollout, DesiredFleetStateData $state): void
    {
        $stored = $this->raise(ReleaseAlertKind::RolloutStalled, $rollout, sprintf(
            'Fleet rollout %d has waited since %s for CLI release %s of commit %s, which is not published.',
            $rollout->id,
            $rollout->started_at?->toIso8601String() ?? 'its start',
            $state->cli->version ?? 'unknown',
            substr($rollout->commit, 0, 12),
        ));

        try {
            $rollout->forceFill(['alert' => $stored])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Raises `rollout_caddy_skipped` for the first Node in a rollout whose Caddyfile was not published.
     *
     * @param  array<mixed>  $skipped  The footprint's reason and message for the skipped Caddyfile.
     * @return array<string, mixed>
     */
    public function caddySkipped(FleetRollout $rollout, FleetRolloutNode $node, array $skipped): array
    {
        return $this->raise(ReleaseAlertKind::RolloutCaddySkipped, $rollout, sprintf(
            'Fleet rollout %d kept the live Caddyfile on node %s: %s',
            $rollout->id,
            $node->node_name,
            is_string($skipped['message'] ?? null) ? $skipped['message'] : 'the Caddyfile was refused',
        ));
    }

    /** @return array<string, mixed> */
    private function raise(ReleaseAlertKind $kind, FleetRollout $rollout, string $summary): array
    {
        try {
            $alert = new ReleaseAlert(
                $kind,
                new ReleaseAlertSubject(self::Target, self::repository(), $rollout->commit, 'fleet-rollout-'.$rollout->id),
                $summary,
            );

            return ['kind' => $kind->value, ...$this->notifier->alert($alert)->toArray()];
        } catch (InvalidArgumentException $exception) {
            report($exception);

            return ['kind' => $kind->value, 'outcome' => 'skipped', 'reason' => 'subject_invalid'];
        }
    }

    /** The GitHub `owner/name` of the Orbit repository, from the CLI release repository URL. */
    public static function repository(): string
    {
        $configured = Config::get('orbit.cli_releases.repository');
        $path = is_string($configured) ? trim((string) parse_url($configured, PHP_URL_PATH), '/') : '';

        return preg_match('#\A[A-Za-z0-9-]+/[A-Za-z0-9._-]+\z#', $path) === 1 ? $path : 'nckrtl/orbit';
    }
}
