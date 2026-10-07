<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeFootprint as NodeFootprintRecord;

/**
 * The Gateway-rendered Orbit footprint of a managed Node (ADR 0202): its Caddyfile, Node agent
 * configuration, private-DNS listener on the `vpn` Node, ProxyCli collector, and annotator server
 * files. Re-applying it never changes an Instance, a Process unit, or a role, and never runs
 * `node:add`.
 */
final readonly class NodeFootprint
{
    /** @param iterable<NodeFootprintArtifact> $artifacts */
    public function __construct(private iterable $artifacts) {}

    /** What the Gateway renders for the Node now. It never reads the Node. */
    public function expected(Node $node): NodeFootprintPlan
    {
        $digests = [];

        foreach ($this->artifacts as $artifact) {
            try {
                if ($artifact->applies($node)) {
                    $digests[$artifact->name()] = $artifact->digest($node);
                }
            } catch (\Throwable $exception) {
                // A digest that cannot be computed never matches, so the next converge reports the failure.
                $digests[$artifact->name()] = 'unavailable:'.($exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class);
            }
        }

        return NodeFootprintPlan::of($digests);
    }

    /** The footprint the Node last received, or null when it never received one. */
    public function recorded(Node $node): ?NodeFootprintRecord
    {
        return NodeFootprintRecord::query()->where('node_id', $node->id)->first();
    }

    /** Whether the Node's recorded footprint differs from what the Gateway renders now. */
    public function drifted(Node $node): bool
    {
        return $this->recorded($node)?->digest !== $this->expected($node)->digest();
    }

    /**
     * Re-applies every artifact whose digest differs from the one the Node last received. With
     * `$force`, it re-applies every artifact, or the named ones; each still leaves a matching live copy alone.
     * The digests cover only what Orbit renders from its own code and pins, never a user's sites or records.
     *
     * @param  bool|list<string>  $force
     *
     * @throws ResourceOperationException with the failed artifact in `details.artifact`.
     */
    public function converge(Node $node, bool|array $force = false): NodeFootprintResult
    {
        $skipped = [];
        $record = $this->recorded($node);
        $recorded = $record instanceof NodeFootprintRecord ? ($record->artifacts ?? []) : [];
        $applied = [];
        $outcomes = [];

        foreach ($this->artifacts as $artifact) {
            if (! $artifact->applies($node)) {
                continue;
            }

            $name = $artifact->name();
            $digest = $artifact->digest($node);

            $forced = $force === true || (is_array($force) && in_array($name, $force, true));

            if (! $forced && ($recorded[$name] ?? null) === $digest) {
                $outcomes[$name] = NodeFootprintResult::Unchanged;
                $applied[$name] = $digest;

                continue;
            }

            try {
                $changed = $artifact->apply($node);
            } catch (FootprintArtifactSkipped $exception) {
                $outcomes[$name] = NodeFootprintResult::Skipped;
                // `repair`: the digest matched, and the artifact ran only because it was forced to repair live drift.
                $skipped[$name] = [
                    'reason' => $exception->reason,
                    'message' => $exception->getMessage(),
                    'repair' => ($recorded[$name] ?? null) === $digest,
                ];
                $applied[$name] = $digest;

                continue;
            } catch (ResourceOperationException $exception) {
                $this->recordFailure($node, $recorded, $applied, $name);

                throw new ResourceOperationException(
                    $exception->errorCode,
                    "Footprint artifact [{$name}] failed: ".$exception->getMessage(),
                    $exception->status,
                    $exception,
                    ['artifact' => $name, ...$exception->details],
                );
            } catch (\Throwable $exception) {
                $this->recordFailure($node, $recorded, $applied, $name);

                throw new ResourceOperationException(
                    'node.footprint_failed',
                    "Footprint artifact [{$name}] failed: ".$exception->getMessage(),
                    502,
                    $exception,
                    ['artifact' => $name],
                );
            }

            $outcomes[$name] = $changed === false ? NodeFootprintResult::Unchanged : NodeFootprintResult::Applied;
            $applied[$name] = $digest;
        }

        $plan = NodeFootprintPlan::of($applied);
        $this->record($node, $plan->artifacts);
        ksort($outcomes);

        return new NodeFootprintResult($outcomes, $plan->digest(), $skipped);
    }

    /**
     * Keeps what this run applied and what it did not reach, and forgets the failed artifact, so the
     * next converge applies it again.
     *
     * @param  array<string, string>  $recorded
     * @param  array<string, string>  $applied
     */
    private function recordFailure(Node $node, array $recorded, array $applied, string $failed): void
    {
        $artifacts = [...$recorded, ...$applied];
        unset($artifacts[$failed]);
        $this->record($node, $artifacts);
    }

    /** @param array<string, string> $artifacts */
    private function record(Node $node, array $artifacts): void
    {
        $plan = NodeFootprintPlan::of($artifacts);
        NodeFootprintRecord::query()->updateOrCreate(
            ['node_id' => $node->id],
            ['digest' => $plan->digest(), 'artifacts' => $plan->artifacts, 'converged_at' => now()],
        );
    }
}
