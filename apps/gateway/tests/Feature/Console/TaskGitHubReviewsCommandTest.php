<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Domain\Tasks\TaskReviewObservation;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domain\Tasks\ApprovalObservationFixtures as Fixtures;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
});

describe('local GitHub approval report', function (): void {
    it('prints sorted provenance and scan status without I/O or task or evidence writes', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([
            Fixtures::review(201, login: '<info>literal display data</info>'), Fixtures::review(102, reviewer: 7), Fixtures::review(101),
        ]));
        $tasks = DB::table('tasks')->get()->toJson();
        $scans = DB::table('task_github_review_scans')->get()->toJson();
        $sources = DB::table('task_github_review_observations')->get()->toJson();
        expect(Artisan::call('orbit:tasks:github-reviews', ['group-id' => $group->id, '--json' => true]))->toBe(0);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json)->toBe($records->report($group))
            ->and(array_column(array_column($json['records'], 'source'), 'review_id'))->toBe([102, 101, 201])
            ->and($json['scan']['read_status'])->toBe('complete')
            ->and($json['scan']['age_seconds'])->toBe(0)
            ->and($json['empty'])->toBeFalse()
            ->and(array_keys($json))->toBe(['group_id', 'scan', 'empty', 'records'])
            ->and(DB::table('tasks')->get()->toJson())->toBe($tasks)
            ->and(DB::table('task_github_review_scans')->get()->toJson())->toBe($scans)
            ->and(DB::table('task_github_review_observations')->get()->toJson())->toBe($sources);
        Http::assertNothingSent();
    });

    it('distinguishes empty disabled unreadable and never observed results', function (string $state, string $expected): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        if ($state !== 'not_observed') {
            $observation = match ($state) {
                'complete' => Fixtures::observation([]),
                'disabled' => Fixtures::observation(accounts: [], read: TaskReviewReadStatus::Disabled),
                'invalid' => new TaskReviewObservation(TaskReviewReadStatus::InvalidTrust),
                default => new TaskReviewObservation(TaskReviewReadStatus::Unreadable),
            };
            $records->finish($group, $records->begin($group), $observation);
        }
        expect(Artisan::call('orbit:tasks:github-reviews', ['group-id' => $group->id, '--json' => true]))->toBe(0);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['scan']['read_status'])->toBe($expected)
            ->and($json['records'])->toBe([])->and($json['empty'])->toBeTrue();
        if ($state === 'not_observed') {
            expect($json['scan']['reason'])->toBe('not_observed')->and($json['scan']['age_seconds'])->toBeNull();
        }
        Http::assertNothingSent();
    })->with([
        ['complete', 'complete'], ['disabled', 'disabled'], ['unreadable', 'unreadable'],
        ['not_observed', 'unreadable'], ['invalid', 'unreadable'],
    ]);

    it('uses fractional scan age at the sixty-second freshness boundary', function (int $milliseconds, string $freshness, string $status): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $this->travel($milliseconds)->milliseconds();
        Artisan::call('orbit:tasks:github-reviews', ['group-id' => $group->id, '--json' => true]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['scan']['freshness'])->toBe($freshness)
            ->and($json['records'][0]['reported_status'])->toBe($status)
            ->and($json['records'][0]['confirmed_status'])->toBe('current');
        Http::assertNothingSent();
    })->with([
        'just below the boundary' => [59999, 'verified', 'current'],
        'exact boundary' => [60000, 'verified', 'current'],
        'one millisecond past the boundary' => [60001, 'unverified', 'unverified'],
        'half a second past the boundary' => [60500, 'unverified', 'unverified'],
    ]);

    it('shows unverified freshness without discarding confirmed evidence', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $this->travel(61)->seconds();
        Artisan::call('orbit:tasks:github-reviews', ['group-id' => $group->id, '--json' => true]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['scan']['freshness'])->toBe('unverified')
            ->and($json['records'][0]['reported_status'])->toBe('unverified')
            ->and($json['records'][0]['confirmed_status'])->toBe('current');
        Http::assertNothingSent();
    });

    it('fails for unknown IDs and subtasks', function (): void {
        expect(Artisan::call('orbit:tasks:github-reviews', ['group-id' => '999999', '--json' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('Unknown task group.');
        $group = Fixtures::group();
        $child = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Child', 'brief' => 'No approval authority.', 'status' => 'todo']);
        expect(Artisan::call('orbit:tasks:github-reviews', ['group-id' => $child->id, '--json' => true]))->toBe(1);
        Http::assertNothingSent();
    });
});
