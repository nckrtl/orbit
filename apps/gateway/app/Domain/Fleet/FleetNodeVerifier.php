<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorIssueKind;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use Closure;

/**
 * Verifies a Node after its rollout steps (ADR 0202).
 *
 * - **Doctor:** the `node` and `role` families of the Node report no issue that was not there before
 *   the steps, and none of the issues the rollout owns: a missing, mismatched, or inactive agent, an
 *   agent secret mismatch, or Caddy build drift. An issue that predates the rollout and that the
 *   rollout does not own, such as low disk space, is kept as evidence and does not fail the Node.
 * - **Presence:** the agent reports the pinned version when it joins its presence channel. The check
 *   waits for a restarted agent to join again. Without an active `websocket` role, or without any report
 *   of the Node's agent, the Doctor binary check stands in for it. Another reported version fails.
 */
final readonly class FleetNodeVerifier implements FleetNodeVerification
{
    /** @var list<string> */
    public const array OwnedIssues = [
        'node.ssh_unreachable',
        'node.agent_missing',
        'node.agent_binary_mismatch',
        'node.agent_inactive',
        'node.agent_secret_mismatch',
        'role.caddy_build_drift',
        'node.cli_foreign',
    ];

    /**
     * The footprint artifact that repairs each owned issue. When the baseline already shows one, the step
     * re-applies that artifact even though its digest matches, so the verify does not fail on a Node that a
     * re-apply would fix.
     *
     * @var array<string, string>
     */
    public const array RepairingArtifacts = [
        'node.agent_missing' => 'agent',
        'node.agent_binary_mismatch' => 'agent',
        'node.agent_inactive' => 'agent',
        'node.agent_secret_mismatch' => 'agent',
        'role.caddy_build_drift' => 'caddy',
    ];

    /**
     * The artifacts to force for the owned issues in a baseline.
     *
     * @param  list<string>  $baseline
     * @return list<string>
     */
    public static function repairs(array $baseline): array
    {
        $artifacts = [];

        foreach ($baseline as $key) {
            $code = explode(':', $key, 2)[0];

            if (isset(self::RepairingArtifacts[$code])) {
                $artifacts[] = self::RepairingArtifacts[$code];
            }
        }

        return array_values(array_unique($artifacts));
    }

    public const int DoctorAttempts = 3;

    public const int DoctorRetrySeconds = 10;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @param (Closure(int): void)|null $sleep */
    public function __construct(
        private RunDoctorAction $doctor,
        private AgentReportedVersions $versions,
        private RealtimeConnection $realtime,
        private int $presenceWaitSeconds = 90,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * The Node's issue keys before the rollout changes it.
     *
     * @return list<string>
     */
    public function baseline(Node $node): array
    {
        return array_keys($this->issues($node));
    }

    /**
     * @param  list<string>  $baseline
     * @return array{passed: bool, new_issues: list<array<string, mixed>>, preexisting_issues: list<string>, agent_version: ?string, presence: string}
     */
    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array
    {
        [$presence, $agentVersion] = $this->presence($node, $pinnedAgentVersion);
        $attempt = 1;

        while (true) {
            [$new, $preexisting] = $this->compare($this->issues($node), $baseline, $tolerated);

            // A restarted agent or a reloaded Caddy settles within seconds; check again before failing.
            if ($new === [] || $attempt >= self::DoctorAttempts) {
                break;
            }

            $attempt++;
            ($this->sleep)(self::DoctorRetrySeconds);
        }

        return [
            'passed' => $new === [] && $presence !== 'mismatch',
            'new_issues' => $new,
            'preexisting_issues' => $preexisting,
            'agent_version' => $agentVersion,
            'presence' => $presence,
        ];
    }

    /**
     * @param  array<string, array{code: string, resource_type: string, resource_id: int|string|null, summary: string}>  $issues
     * @param  list<string>  $baseline
     * @param  list<string>  $tolerated  Issue codes a skipped artifact explains, such as Caddy drift from a user's Route.
     * @return array{list<array{code: string, resource_type: string, resource_id: int|string|null, summary: string}>, list<string>}
     */
    private function compare(array $issues, array $baseline, array $tolerated = []): array
    {
        $new = [];
        $preexisting = [];

        foreach ($issues as $key => $issue) {
            // The lag clears when the rollout records this Node, after the verify.
            if ($issue['code'] === 'node.release_lag') {
                continue;
            }

            if (in_array($issue['code'], $tolerated, true)) {
                $preexisting[] = $key;

                continue;
            }

            if (in_array($issue['code'], self::OwnedIssues, true) || ! in_array($key, $baseline, true)) {
                $new[] = $issue;
            } else {
                $preexisting[] = $key;
            }
        }

        return [$new, $preexisting];
    }

    /** @return array{string, ?string} `matched`, `mismatch`, or `unavailable`, and the reported version. */
    private function presence(Node $node, string $pinned): array
    {
        if ($this->realtime->resolve() === null) {
            return ['unavailable', $this->versions->get($node->id)['version'] ?? null];
        }

        $deadline = time() + $this->presenceWaitSeconds;

        while (true) {
            $reported = $this->versions->get($node->id)['version'] ?? null;

            if ($reported === $pinned) {
                return ['matched', $reported];
            }

            // No report at all, such as after the Gateway's cache was cleared: Doctor's binary check stands in.
            if (time() >= $deadline) {
                return [$reported === null ? 'unavailable' : 'mismatch', $reported];
            }

            ($this->sleep)(2);
        }
    }

    /** @return array<string, array{code: string, resource_type: string, resource_id: int|string|null, summary: string}> */
    private function issues(Node $node): array
    {
        $issues = [];
        $report = $this->doctor->executeForNode($node, [DoctorFamily::Node, DoctorFamily::Role]);

        foreach ($report->nodes as $nodeReport) {
            foreach ($nodeReport->families as $family) {
                foreach ($family->issues as $issue) {
                    if ($issue->kind === DoctorIssueKind::Informational) {
                        continue;
                    }

                    $key = $issue->code.':'.$issue->resourceType.':'.(string) $issue->resourceId;
                    $issues[$key] = [
                        'code' => $issue->code,
                        'resource_type' => $issue->resourceType,
                        'resource_id' => $issue->resourceId,
                        'summary' => $issue->summary,
                    ];
                }
            }
        }

        return $issues;
    }
}
