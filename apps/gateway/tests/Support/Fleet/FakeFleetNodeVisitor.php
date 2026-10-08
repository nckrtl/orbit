<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetNodeResult;
use App\Domain\Fleet\FleetNodeVisitor;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Fleet\NodeFootprint;
use App\Models\Node;

/** Answers each Node with a scripted outcome, `converged` by default, and records the visit order. */
final class FakeFleetNodeVisitor implements FleetNodeVisitor
{
    /** @var list<string> */
    public array $visited = [];

    /** @param array<string, FleetNodeOutcome> $outcomes */
    public string $waitingCode = 'fleet.release_pending';

    public function __construct(public array $outcomes = []) {}

    /** @var list<bool> */
    public array $downgrades = [];

    /** @var array<string, bool> Node name => whether its foreign CLI is gone. */
    public array $cliFixed = [];

    public function recheckCli(Node $node): bool
    {
        if (! ($this->cliFixed[$node->name] ?? false)) {
            return false;
        }

        new NodeCliState()->clear($node);

        return true;
    }

    /** @var (\Closure(Node): void)|null Runs after each visit, such as a release going current meanwhile. */
    public ?\Closure $afterVisit = null;

    /** @var list<bool> */
    public array $firstVisits = [];

    /** @var array<string, array<string, mixed>> Evidence a visit to the named Node reports. */
    public array $evidence = [];

    public function converge(Node $node, DesiredFleetStateData $desired, bool $allowDowngrade = false, bool $firstVisit = false): FleetNodeResult
    {
        $this->firstVisits[] = $firstVisit;
        $this->visited[] = $node->name;
        $this->downgrades[] = $allowDowngrade;

        if ($this->afterVisit !== null) {
            ($this->afterVisit)($node);
        }
        $outcome = $this->outcomes[$node->name] ?? FleetNodeOutcome::Converged;

        return match ($outcome) {
            FleetNodeOutcome::Failed => new FleetNodeResult($outcome, step: 'self-update', errorCode: 'fleet.self_update_failed', message: "self-update failed on {$node->name}", evidence: ['exit_code' => 1]),
            FleetNodeOutcome::Unreachable => new FleetNodeResult($outcome, step: 'ssh', errorCode: 'node.ssh_unreachable', message: 'unreachable'),
            FleetNodeOutcome::Skipped => (static function () use ($node, $outcome): FleetNodeResult {
                new NodeCliState()->markForeign($node);

                return new FleetNodeResult($outcome, step: 'cli', errorCode: 'cli.foreign_binary', message: 'foreign');
            })(),
            FleetNodeOutcome::Waiting => new FleetNodeResult($outcome, step: 'self-update', errorCode: $this->waitingCode, message: 'waiting', evidence: ['self_update' => ['outcome' => 'waiting']]),
            // Like the real visitor, a reachable Node receives its footprint.
            default => new FleetNodeResult($outcome, evidence: $this->evidence[$node->name] ?? [], cliVersion: $desired->cli->version, agentVersion: $desired->agent->version, footprintDigest: app(NodeFootprint::class)->converge($node)->digest),
        };
    }
}
