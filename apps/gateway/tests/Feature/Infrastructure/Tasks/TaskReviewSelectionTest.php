<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Tasks\TaskReviewSelection;
use App\Domain\Tasks\TaskReviewTrust;
use Tests\Feature\GitHub\GitHubTestSupport;

function selection_review(int $id, GitHubReviewState $state, string $head = 'abc123', int $account = 42, string $time = '2026-09-02T22:13:41Z', string $login = 'reviewer-renamed'): GitHubReview
{
    $source = GitHubTestSupport::review();

    return new GitHubReview($id, $account, $login, $state, $head,
        $state === GitHubReviewState::Pending ? null : new DateTimeImmutable($time), $source['html_url'], $source['body']);
}

function selection_trust(): TaskReviewTrust
{
    return TaskReviewTrust::fromConfig(GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), ['acme/orbit' => [42, 7, 8]]);
}

it('selects the latest decisive record across heads regardless of list arrival order', function (bool $reverse, GitHubReviewState $latest, string $head, array $requests, array $approvals): void {
    $reviews = [
        selection_review(100, GitHubReviewState::ChangesRequested, time: '2026-09-01T22:13:41Z'),
        selection_review(101, $latest, $head),
        selection_review(102, GitHubReviewState::Commented, time: '2026-09-03T22:13:41Z'),
        selection_review(103, GitHubReviewState::Pending, ''),
    ];
    $selection = TaskReviewSelection::select($reverse ? array_reverse($reviews) : $reviews, selection_trust(), 'abc123', true);

    expect(array_column($selection->effective, 'id'))->toBe([101]);
    expect(array_column($selection->requests, 'id'))->toBe($requests);
    expect(array_column($selection->approvals, 'id'))->toBe($approvals);
})->with([false, true])->with([
    'request survives comment and pending' => [GitHubReviewState::ChangesRequested, 'abc123', [101], []],
    'approval is informational' => [GitHubReviewState::Approved, 'abc123', [], [101]],
    'dismissal is neutral without fallback' => [GitHubReviewState::Dismissed, 'abc123', [], []],
    'new stale request prevents fallback' => [GitHubReviewState::ChangesRequested, 'old-head', [], []],
    'new stale approval prevents fallback' => [GitHubReviewState::Approved, 'old-head', [], []],
]);

it('breaks decision ties by numeric ID and orders independent requests oldest first', function (): void {
    $selection = TaskReviewSelection::select([
        selection_review(201, GitHubReviewState::ChangesRequested, account: 8),
        selection_review(202, GitHubReviewState::Approved, account: 7),
        selection_review(103, GitHubReviewState::ChangesRequested, time: '2026-09-01T22:13:41Z'),
        selection_review(102, GitHubReviewState::Approved, time: '2026-09-01T22:13:41Z'),
        selection_review(999, GitHubReviewState::ChangesRequested, account: 99),
    ], selection_trust(), 'abc123', true);

    expect(array_column($selection->requests, 'id'))->toBe([103, 201]);
    expect(array_column($selection->approvals, 'id'))->toBe([202]);
});

it('orders requests tied on time by numeric review ID rather than account order', function (): void {
    $selection = TaskReviewSelection::select([
        selection_review(300, GitHubReviewState::ChangesRequested, account: 7),
        selection_review(200, GitHubReviewState::ChangesRequested, account: 8),
    ], selection_trust(), 'abc123', true);

    expect(array_column($selection->requests, 'id'))->toBe([200, 300]);
});

it('keeps numeric authority after a rename but rejects another account with the old login', function (): void {
    $selection = TaskReviewSelection::select([
        selection_review(101, GitHubReviewState::ChangesRequested, login: 'new-name'),
        selection_review(102, GitHubReviewState::ChangesRequested, account: 99, login: 'reviewer-renamed'),
    ], selection_trust(), 'abc123', true);

    expect(array_column($selection->requests, 'id'))->toBe([101]);
});

it('does not make decisions eligible on a closed PR or missing head', function (bool $open, ?string $head): void {
    $selection = TaskReviewSelection::select([selection_review(101, GitHubReviewState::ChangesRequested)], selection_trust(), $head, $open);

    expect($selection->requests)->toBe([]);
})->with(['closed' => [false, 'abc123'], 'no head' => [true, null]]);

it('rejects malformed decisive records and duplicate identities rather than falling back', function (string $problem): void {
    $valid = selection_review(101, GitHubReviewState::Approved);
    $bad = match ($problem) {
        'time' => new GitHubReview(102, 42, 'name', GitHubReviewState::ChangesRequested, 'abc123', null, $valid->url, ''),
        'head' => selection_review(102, GitHubReviewState::Dismissed, ''),
        'identity' => selection_review(0, GitHubReviewState::Approved),
        default => $valid,
    };

    expect(fn () => TaskReviewSelection::select([$valid, $bad], selection_trust(), 'abc123', true))->toThrow(InvalidArgumentException::class);
})->with(['time', 'head', 'identity', 'duplicate']);
