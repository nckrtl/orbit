<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskReviewCandidate;
use App\Domain\Tasks\TaskReviewCandidateResult;
use App\Domain\Tasks\TaskReviewObservation;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

final class FakeTaskPullRequestReviewWatcher implements TaskPullRequestReviewWatcher
{
    public int $reads = 0;

    public int $candidates = 0;

    public int $validations = 0;

    public ?TaskReviewReadStatus $validationStatus = null;

    public ?Closure $beforeAppend = null;

    public TaskReviewReadStatus $candidateStatus = TaskReviewReadStatus::Complete;

    public function __construct(public TaskReviewObservation $observation, private int $transactionLevel) {}

    private function assertOutsideLock(): void
    {
        if (DB::transactionLevel() !== $this->transactionLevel) {
            throw new LogicException('Review I/O under a scheduler transaction.');
        }
    }

    public function reviews(Task $group, bool $fresh = false): TaskReviewObservation
    {
        $this->assertOutsideLock();
        $this->reads++;
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), $this->observation);

        return $this->observation;
    }

    public function reviewCandidate(Task $group, int $reviewId, bool $fresh = false): TaskReviewCandidateResult
    {
        $this->assertOutsideLock();
        $this->candidates++;
        $review = array_find($this->observation->selection?->requests ?? [], static fn ($review): bool => $review->id === $reviewId);
        if ($review === null || $this->candidateStatus !== TaskReviewReadStatus::Complete) {
            return new TaskReviewCandidateResult($this->candidateStatus);
        }
        if ($this->observation->repository === null || $this->observation->number === null || $this->observation->pullRequest?->headSha === null || $this->observation->trust === null) {
            throw new LogicException('Incomplete fixture.');
        }

        return new TaskReviewCandidateResult(TaskReviewReadStatus::Complete, new TaskReviewCandidate(
            $this->observation->repository, $this->observation->number, $this->observation->pullRequest->headSha,
            $this->observation->trust->revision, $review, [],
        ));
    }

    public function revalidateReviewCandidate(Task $group, TaskReviewCandidate $candidate): TaskReviewCandidateResult
    {
        $this->assertOutsideLock();
        $this->validations++;
        $callback = $this->beforeAppend;
        $this->beforeAppend = null;
        $callback?->__invoke();

        $status = $this->validationStatus ?? $this->candidateStatus;

        return new TaskReviewCandidateResult($status, $status === TaskReviewReadStatus::Complete ? $candidate : null);
    }
}
