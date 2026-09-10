<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofReviewAction;
use App\E2E\Value\ProofReviewEvaluation;
use App\E2E\Value\ProofReviewRecord;
use App\E2E\Value\TopologyRequest;
use Closure;
use RuntimeException;

/** Record interactive access without changing immutable captured proof evidence. */
final readonly class ProofReviewService
{
    private AtomicJsonStore $archive;

    public function __construct(
        private IncusHost $host,
        private StatePaths $hostPaths,
        private OperationId $operation,
        private SecretRedactor $redactor = new SecretRedactor,
        private ?Closure $clock = null,
    ) {
        $this->archive = new AtomicJsonStore($hostPaths);
    }

    /**
     * @param  list<string>  $argv
     */
    public function execute(
        TopologyRequest $request,
        string $actionId,
        string $node,
        array $argv,
        bool $required,
        ?string $stdin = null,
    ): GuestCommandResult {
        return $this->locked($request, function (IssueState $state) use (
            $request,
            $actionId,
            $node,
            $argv,
            $required,
            $stdin,
        ): GuestCommandResult {
            [$capture, $topology] = $this->reviewTarget($request, $state);
            $startedAt = $this->now();
            $record = $this->record($state, $capture, $startedAt)->withAction(
                ProofReviewAction::incomplete(
                    $actionId,
                    'exec',
                    $node,
                    $required,
                    $this->redactor->redactArgv($argv),
                    $stdin === null ? null : hash('sha256', $stdin),
                    $startedAt,
                ),
                $startedAt,
            );
            $this->persist($state, $record, $startedAt);

            $instance = $this->ownedInstance($topology, $node);
            $result = $this->host->exec($instance, GuestCommand::asOrbitUser($argv, stdin: $stdin));
            $completedAt = $this->now();
            $action = $record->action($actionId) ?? throw new RuntimeException(
                'The proof review action disappeared before completion.',
            );
            $record = $record->withAction($action->complete(
                $result->successful() ? 'passed' : 'failed',
                $result->exitCode,
                ProofResult::tail($this->redactor->redact($result->stdout)),
                ProofResult::tail($this->redactor->redact($result->stderr)),
                null,
                $completedAt,
            ), $completedAt);
            $this->persist($state, $record, $completedAt);

            return $result;
        });
    }

    /** Record an incomplete shell before returning its exact retained proof instance. */
    public function beginShell(
        TopologyRequest $request,
        string $actionId,
        string $node,
        bool $required,
    ): string {
        return $this->locked($request, function (IssueState $state) use (
            $request,
            $actionId,
            $node,
            $required,
        ): string {
            [$capture, $topology] = $this->reviewTarget($request, $state);
            $startedAt = $this->now();
            $record = $this->record($state, $capture, $startedAt)->withAction(
                ProofReviewAction::incomplete($actionId, 'shell', $node, $required, [], null, $startedAt),
                $startedAt,
            );
            $this->persist($state, $record, $startedAt);

            return $this->ownedInstance($topology, $node);
        });
    }

    public function complete(
        TopologyRequest $request,
        string $actionId,
        string $result,
        ?string $finding = null,
    ): ProofReviewEvaluation {
        return $this->locked($request, function (IssueState $state) use (
            $request,
            $actionId,
            $result,
            $finding,
        ): ProofReviewEvaluation {
            [$capture] = $this->reviewTarget($request, $state);
            $record = $this->record($state, $capture, $this->now());
            $action = $record->action($actionId) ?? throw new RuntimeException(
                "Proof review action {$actionId} does not exist.",
            );
            if ($action->type !== 'shell') {
                throw new RuntimeException('Only an incomplete proof review shell is completed explicitly.');
            }
            if (! in_array($result, ['passed', 'failed'], true)) {
                throw new RuntimeException('The proof review result must be passed or failed.');
            }
            $completedAt = $this->now();
            $record = $record->withAction($action->complete(
                $result,
                null,
                '',
                '',
                $finding === null ? null : $this->redactor->redact($finding),
                $completedAt,
            ), $completedAt);

            return $this->persist($state, $record, $completedAt);
        });
    }

    public function evaluate(TopologyRequest $request): ProofReviewEvaluation
    {
        return $this->locked($request, function (IssueState $state) use ($request): ProofReviewEvaluation {
            [$capture] = $this->reviewTarget($request, $state);
            $evaluatedAt = $this->now();
            $record = $this->record($state, $capture, $evaluatedAt);

            return $this->persist($state, $record, $evaluatedAt);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(IssueState): T  $callback
     * @return T
     */
    private function locked(TopologyRequest $request, Closure $callback): mixed
    {
        DeliveryFlow::requireProof($request->worktree);
        $lock = new OperationLock($this->hostPaths);
        if (! $lock->acquire('topology-'.$request->issue, $this->operation)) {
            throw new RuntimeException('The issue topology is locked by another harness command.');
        }

        try {
            return $callback(IssueState::forWorktree($request->issue, $request->worktree));
        } finally {
            $lock->release();
        }
    }

    /** @return array{CapturedProof, FeatureTopology} */
    private function reviewTarget(TopologyRequest $request, IssueState $state): array
    {
        if (! $state->isProved()) {
            throw new RuntimeException('Interactive review requires an active successful proof topology.');
        }
        $topology = $state->requireTopology(AttemptPurpose::Proof);
        $capture = $state->capturedProof($topology->attempt) ?? throw new RuntimeException(
            'Interactive review requires captured proof evidence.',
        );
        $archived = $this->archive->read($this->capturePath($capture));
        if ($archived === null || CapturedProof::fromStoredArray($archived)->toArray() !== $capture->toArray()) {
            throw new RuntimeException('The retained proof archive and worktree capture differ.');
        }
        if (
            $capture->issue !== $request->issue
            || $capture->attempt->value !== $topology->attempt->value
            || $capture->proof !== $state->proof()
            || $capture->topology->toArray() !== $topology->toArray()
        ) {
            throw new RuntimeException('The active proof topology does not match its immutable capture.');
        }

        return [$capture, $topology];
    }

    private function ownedInstance(FeatureTopology $topology, string $node): string
    {
        $instance = $topology->instances[$node] ?? null;
        if (! is_string($instance) || $instance !== $topology->target->instance($node)) {
            throw new RuntimeException("Node {$node} does not belong to the retained proof topology.");
        }
        $owned = $this->host->instance($instance);
        if (
            $owned === null
            || ! $owned->isRunning()
            || ($owned->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($owned->metadata['user.orbit.e2e.issue'] ?? null) !== $topology->target->issue
            || ($owned->metadata['user.orbit.e2e.attempt'] ?? null) !== $topology->attempt->value
            || $owned->network !== $topology->network
        ) {
            throw new RuntimeException('Incus instance identity does not match the retained proof topology.');
        }

        return $instance;
    }

    private function record(IssueState $state, CapturedProof $capture, string $updatedAt): ProofReviewRecord
    {
        $local = $state->reviewRecord($capture->attempt);
        $path = $this->recordPath($capture);
        $archived = $this->archive->read($path);
        $host = $archived === null ? null : ProofReviewRecord::fromArray($archived);
        if ($local === null && $host === null) {
            return ProofReviewRecord::empty(
                $capture->issue,
                $capture->candidateSha,
                $capture->attempt,
                $updatedAt,
            );
        }
        if ($local === null) {
            $state->writeReviewRecord($host);

            return $host;
        }
        if ($host === null) {
            $this->archive->write($path, $local->toArray());

            return $local;
        }
        if ($host->toArray() === $local->toArray()) {
            return $local;
        }
        if ($host->canReplace($local)) {
            $state->writeReviewRecord($host);

            return $host;
        }
        if ($local->canReplace($host)) {
            $this->archive->write($path, $local->toArray());

            return $local;
        }

        throw new RuntimeException('The retained proof review archive and worktree record differ.');
    }

    private function persist(
        IssueState $state,
        ProofReviewRecord $record,
        string $evaluatedAt,
    ): ProofReviewEvaluation {
        $this->archive->write($this->recordPathFor($record->issue, $record->attempt->value), $record->toArray());
        $state->writeReviewRecord($record);
        $evaluation = ProofReviewEvaluation::forRecord($record, $evaluatedAt);
        $this->archive->write(
            $this->evaluationPath($record->issue, $record->attempt->value),
            $evaluation->toArray(),
        );
        $state->writeReviewEvaluation($evaluation);

        return $evaluation;
    }

    private function capturePath(CapturedProof $capture): string
    {
        return 'proof-evidence/'.$capture->issue.'/'.$capture->attempt->value.'.json';
    }

    private function recordPath(CapturedProof $capture): string
    {
        return $this->recordPathFor($capture->issue, $capture->attempt->value);
    }

    private function recordPathFor(string $issue, string $attempt): string
    {
        return 'proof-review/'.$issue.'/'.$attempt.'.json';
    }

    private function evaluationPath(string $issue, string $attempt): string
    {
        return 'proof-review-evaluation/'.$issue.'/'.$attempt.'.json';
    }

    private function now(): string
    {
        $time = $this->clock === null ? gmdate('Y-m-d\TH:i:s\Z') : ($this->clock)();
        if (! is_string($time)) {
            throw new RuntimeException('The proof review clock returned an invalid time.');
        }

        return $time;
    }
}
