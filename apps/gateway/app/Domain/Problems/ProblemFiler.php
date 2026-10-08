<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Requests\Tasks\CreateTaskGroupRequest;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Files at most three Backlog groups a day for fingerprints that keep returning, and for release alerts.
 * The operator replaces the review placeholder with a scoped repro before Todo.
 */
final readonly class ProblemFiler
{
    private const int DailyCap = 3;

    private const int ReadyLimit = 50;

    private const int BriefLimit = 8000;

    public function __construct(
        private CreateTaskGroupAction $groups,
        private ProblemEvidence $evidence,
        private ProblemSuppression $suppression,
    ) {}

    /** @return list<Throwable> */
    public function file(): array
    {
        $failures = $this->suppressEnded();

        foreach ($this->settleLegacyEpisodes() as $exception) {
            $failures[] = $exception;
        }

        $project = Project::query()->where('slug', 'orbit')->first();

        if (! $project instanceof Project) {
            return $failures;
        }

        foreach ($this->readyRows() as $row) {
            if ($this->filedToday() >= self::DailyCap) {
                break;
            }

            try {
                $this->fileOne($row, $project);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        return $failures;
    }

    /** @return list<Throwable> */
    private function suppressEnded(): array
    {
        $failures = [];
        $rows = ProblemFingerprint::query()
            ->whereNotNull('task_group_id')
            ->whereNull('muted_until')
            ->orderBy('id')
            ->get(['id']);

        foreach ($rows as $row) {
            try {
                $this->suppressOne($row->id);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        return $failures;
    }

    /** @return list<Throwable> */
    private function settleLegacyEpisodes(): array
    {
        $failures = [];

        ProblemFingerprint::query()
            ->where(function (Builder $query): void {
                $query->where('occurrences', '>', 0)
                    ->orWhereNotNull('evidence->observation_times');
            })
            ->whereNull('evidence->observation_counts')
            ->whereNull('evidence->counted_blocks')
            ->chunkById(100, function ($rows) use (&$failures): void {
                foreach ($rows as $row) {
                    try {
                        $this->settleOne($row->id);
                    } catch (Throwable $exception) {
                        $failures[] = $exception;
                    }
                }
            });

        return $failures;
    }

    /**
     * Rewrites a pre-window episode in place. The linked task and the mute stay as they are.
     */
    private function settleOne(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = ProblemFingerprint::query()->lockForUpdate()->find($id);

            if (! $row instanceof ProblemFingerprint) {
                return;
            }

            $this->rewriteLegacy($row);
        });
    }

    private function rewriteLegacy(ProblemFingerprint $row): void
    {
        $settled = $this->evidence->legacyEpisode($row->evidence, $row->source);

        if ($settled === null) {
            return;
        }

        $row->occurrences = $settled['occurrences'];
        $row->evidence = $settled['evidence'];

        if ($settled['reset_seen']) {
            $row->first_seen = null;
            $row->last_seen = null;
        }

        $row->save();
    }

    /**
     * The deadline and the episode clear are one write. A crash stores neither.
     */
    private function suppressOne(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = ProblemFingerprint::query()->lockForUpdate()->find($id);

            if (! $row instanceof ProblemFingerprint || $row->muted_until !== null || $row->task_group_id === null) {
                return;
            }

            if ($this->suppression->isUnrecoverable($row)) {
                return;
            }

            $task = Task::query()->withoutGlobalScope('subtask')->find($row->task_group_id);

            if ($task instanceof Task && $this->isOpen($task)) {
                return;
            }

            $cancelled = $task instanceof Task && $task->groupStatus() === TaskGroupStatus::Cancelled;
            $row->muted_until = $this->muteFrom($task)->addDays($cancelled ? 14 : 7);
            $this->clearEpisode($row);
            $row->save();
        });
    }

    /** @return list<ProblemFingerprint> */
    private function readyRows(): array
    {
        $ready = [];
        $open = $this->openStatuses();

        $candidates = ProblemFingerprint::query()
            ->where(function (Builder $query): void {
                $query->whereNull('muted_until')->orWhere('muted_until', '<=', now());
            })
            ->where(function (Builder $query) use ($open): void {
                $query->whereNull('task_group_id')
                    ->orWhereNotExists(function ($subquery) use ($open): void {
                        $subquery->selectRaw('1')
                            ->from('tasks')
                            ->whereColumn('tasks.id', 'problem_fingerprints.task_group_id')
                            ->whereIn('tasks.status', $open);
                    });
            })
            ->where(function (Builder $query): void {
                $query->whereIn('source', [ProblemSource::Doctor, ProblemSource::Release])
                    ->orWhere('occurrences', '>=', 3);
            })
            ->orderByRaw('case when source = ? then 0 else 1 end', [ProblemSource::Release->value])
            ->orderByDesc('occurrences')
            ->orderByRaw('case when first_seen is null then 1 else 0 end')
            ->orderBy('first_seen')
            ->orderBy('fingerprint');

        foreach ($candidates->lazy(100) as $row) {
            if (! $this->isReady($row) || $this->suppression->blocksFiling($row)) {
                continue;
            }

            $ready[] = $row;

            if (count($ready) >= self::ReadyLimit) {
                break;
            }
        }

        return $ready;
    }

    private function fileOne(ProblemFingerprint $row, Project $project): void
    {
        try {
            DB::transaction(function () use ($row, $project): void {
                $locked = ProblemFingerprint::query()->lockForUpdate()->find($row->id);

                if (! $locked instanceof ProblemFingerprint) {
                    return;
                }

                $this->rewriteLegacy($locked);

                if (! $this->canFile($locked)) {
                    return;
                }

                if ($this->filedToday() >= self::DailyCap) {
                    return;
                }

                $data = $this->groupData($locked, $project);
                $this->validate($data);
                $group = $this->groups->execute($data);
                $this->clearEpisode($locked);
                $locked->task_group_id = $group->id;
                $locked->filed_at = now();
                $locked->muted_until = null;
                $locked->save();
            });
        } catch (ValidationException|ResourceOperationException) {
            return;
        }
    }

    private function canFile(ProblemFingerprint $row): bool
    {
        if ($this->suppression->blocksFiling($row) || ! $this->isReady($row)) {
            return false;
        }

        if ($row->muted_until instanceof CarbonInterface && $row->muted_until->isFuture()) {
            return false;
        }

        if ($row->task_group_id === null) {
            return true;
        }

        $task = Task::query()->withoutGlobalScope('subtask')->find($row->task_group_id);

        return $task instanceof Task && ! $this->isOpen($task);
    }

    private function isReady(ProblemFingerprint $row): bool
    {
        $occurrences = $row->occurrences;
        $evidence = $row->evidence;
        $settled = $this->evidence->legacyEpisode($evidence, $row->source);

        if ($settled !== null) {
            $occurrences = $settled['occurrences'];
            $evidence = $settled['evidence'];
        }

        $stamps = $this->stamps($this->strings($evidence['observation_times'] ?? null));

        if ($row->source === ProblemSource::Doctor) {
            return $this->tenMinutesApart($stamps);
        }

        if ($row->source === ProblemSource::Release) {
            return $occurrences >= 1;
        }

        if ($occurrences >= 10) {
            return true;
        }

        if ($occurrences < 3) {
            return false;
        }

        return $this->twoQuarterHours($stamps) || $this->twoDates($stamps);
    }

    private function isOpen(Task $task): bool
    {
        return in_array($task->groupStatus(), [
            TaskGroupStatus::Backlog,
            TaskGroupStatus::Todo,
            TaskGroupStatus::Reserved,
            TaskGroupStatus::Running,
            TaskGroupStatus::Reviewing,
            TaskGroupStatus::Settling,
            TaskGroupStatus::WaitingForReview,
        ], true);
    }

    /** @return list<string> */
    private function openStatuses(): array
    {
        return [
            TaskGroupStatus::Backlog->value,
            TaskGroupStatus::Todo->value,
            TaskGroupStatus::Reserved->value,
            TaskGroupStatus::Running->value,
            TaskGroupStatus::Reviewing->value,
            TaskGroupStatus::Settling->value,
            TaskGroupStatus::WaitingForReview->value,
        ];
    }

    private function filedToday(): int
    {
        $start = now()->startOfDay();

        return ProblemFingerprint::query()
            ->where('filed_at', '>=', $start)
            ->where('filed_at', '<', $start->copy()->addDay())
            ->count();
    }

    private function groupData(ProblemFingerprint $row, Project $project): CreateTaskGroupData
    {
        $symptom = $this->symptom($row);

        return new CreateTaskGroupData(
            projectId: $project->id,
            title: $this->title($row),
            brief: $this->brief($row, $symptom),
            status: TaskGroupStatus::Backlog,
            notifyCoder: false,
            tasks: [
                new TaskInputData(
                    title: 'Document the owning page',
                    brief: $symptom,
                    deliverables: [[
                        'id' => 'docs',
                        'type' => 'review',
                        'description' => 'The owning page matches the fix, or no page changes.',
                    ]],
                ),
                new TaskInputData(
                    title: 'Reproduce the failure and fix it',
                    brief: $symptom,
                    deliverables: [[
                        'id' => 'test',
                        'type' => 'review',
                        'description' => 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.',
                    ]],
                ),
            ],
        );
    }

    private function validate(CreateTaskGroupData $data): void
    {
        $payload = [
            'project_id' => $data->projectId,
            'title' => $data->title,
            'brief' => $data->brief,
            'status' => $data->status->value,
            'tasks' => array_map(static fn (TaskInputData $task): array => [
                'title' => $task->title,
                'brief' => $task->brief,
                'deliverables' => $task->deliverables,
            ], $data->tasks),
        ];
        $validator = Validator::make($payload, (new CreateTaskGroupRequest)->rules());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function brief(ProblemFingerprint $row, string $symptom): string
    {
        $sections = [
            "Symptom\n{$symptom}",
            "Fingerprint\n".$this->cut($row->fingerprint, 255, false),
            "First seen\n".$this->timeLine($row->first_seen),
            "Last seen\n".$this->timeLine($row->last_seen),
            'Count'."\n".$row->occurrences,
            "Occurrences\n".$this->occurrenceHistory($row),
        ];
        $lines = $this->evidenceLines($row);
        $entry = "Suspected entry point\n".$this->entryPoint($row);

        while (true) {
            $body = $lines === [] ? 'none' : implode("\n", $lines);
            $brief = implode("\n\n", [
                'Filed by the outer loop.',
                ...$sections,
                "Evidence\n{$body}",
                $entry,
            ]);

            if (mb_strlen($brief) <= self::BriefLimit || $lines === []) {
                return $brief;
            }

            array_pop($lines);
        }
    }

    private function occurrenceHistory(ProblemFingerprint $row): string
    {
        $times = $row->evidence['observation_times'] ?? null;
        $counts = $row->evidence['observation_counts'] ?? null;

        if (! is_array($times) || ! is_array($counts)) {
            return 'none';
        }

        $occurrences = [];

        foreach ($times as $index => $time) {
            $count = $counts[$index] ?? null;

            if (! is_string($time) || $time === '' || ! is_int($count) || $count < 1) {
                continue;
            }

            try {
                $at = Carbon::parse($time)->utc();
            } catch (Throwable) {
                continue;
            }

            $occurrences[] = ['at' => $at, 'count' => $count];
        }

        usort($occurrences, static fn (array $left, array $right): int => $left['at']->getTimestamp() <=> $right['at']->getTimestamp());
        $lines = [];

        foreach (array_slice($occurrences, -20) as $occurrence) {
            $lines[] = $this->timeLine($occurrence['at']).' '.$occurrence['count'];
        }

        return $lines === [] ? 'none' : implode("\n", $lines);
    }

    private function symptom(ProblemFingerprint $row): string
    {
        $text = match ($row->source) {
            ProblemSource::Doctor => $this->text($row, 'summary') ?? $this->doctorSymptom($row),
            ProblemSource::Activity => $this->text($row, 'error_message') ?? $this->activitySymptom($row),
            ProblemSource::Log => $this->text($row, 'log_excerpt') ?? $this->logSymptom($row),
            ProblemSource::Assist => $this->text($row, 'assistance_reason') ?? $this->reason($row),
            ProblemSource::Release => $this->text($row, 'summary') ?? $this->releaseTitle($row),
        };
        $text = trim($text);

        if ($text === '') {
            $text = 'No symptom was recorded.';
        }

        return $this->cut($text, 1000, true);
    }

    private function title(ProblemFingerprint $row): string
    {
        $title = match ($row->source) {
            ProblemSource::Doctor => $this->doctorTitle($row),
            ProblemSource::Activity => $this->activityTitle($row),
            ProblemSource::Log => $this->logTitle($row),
            ProblemSource::Assist => $this->reason($row),
            ProblemSource::Release => $this->releaseTitle($row),
        };

        if ($title === '') {
            $title = $row->fingerprint;
        }

        if (mb_strlen($title) <= 160) {
            return $title;
        }

        return mb_substr($title, 0, 157).'...';
    }

    private function entryPoint(ProblemFingerprint $row): string
    {
        $entry = match ($row->source) {
            ProblemSource::Doctor => $this->doctorEntry($row),
            ProblemSource::Activity => $this->activityParts($row)['command'] ?? $row->fingerprint,
            ProblemSource::Log => $this->logParts($row)['frame'] ?? $this->text($row, 'source_path') ?? $row->fingerprint,
            ProblemSource::Assist => $this->assistanceEntry($row),
            ProblemSource::Release => $this->releaseEntry($row),
        };

        return $this->cut($entry, 500, true);
    }

    /** @return list<string> */
    private function evidenceLines(ProblemFingerprint $row): array
    {
        $evidence = $row->evidence;
        $lines = [];
        $requestIds = $this->strings($evidence['request_ids'] ?? null);

        if ($requestIds !== []) {
            $lines[] = 'Request ids: '.implode(', ', $requestIds);
        }

        $activityIds = $this->evidence->ints($evidence['activity_ids'] ?? null);

        if ($activityIds !== []) {
            $lines[] = 'Activity ids: '.implode(', ', array_map(static fn (int $id): string => (string) $id, $activityIds));
        }

        $paths = $this->strings($evidence['paths'] ?? null);

        if ($paths !== []) {
            $lines[] = 'Paths: '.implode(', ', array_map($this->oneLine(...), $paths));
        }

        $links = $this->strings($evidence['evidence_urls'] ?? null);

        if ($links !== []) {
            $lines[] = 'Evidence links: '.implode(', ', array_map($this->oneLine(...), $links));
        }

        $excerpt = $this->text($row, 'log_excerpt');

        if ($excerpt !== null) {
            $lines[] = 'Log excerpt: '.$this->oneLine($excerpt);
        }

        if (array_key_exists('expected', $evidence)) {
            $line = $this->valueLine('Expected', $evidence['expected']);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if (array_key_exists('observed', $evidence)) {
            $line = $this->valueLine('Observed', $evidence['observed']);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $assistanceIds = $this->evidence->ints($evidence['assistance_task_ids'] ?? null);

        if ($assistanceIds !== []) {
            $lines[] = 'Assistance task ids: '.implode(', ', array_map(static fn (int $id): string => (string) $id, $assistanceIds));
        }

        return $lines;
    }

    private function valueLine(string $label, mixed $value): ?string
    {
        if (is_bool($value)) {
            return $label.': '.($value ? 'true' : 'false');
        }

        if ($value === null) {
            return $label.': none';
        }

        if (! is_string($value)) {
            return null;
        }

        return $label.': '.$this->oneLine($this->cut($value, 200, false));
    }

    private function doctorTitle(ProblemFingerprint $row): string
    {
        $parts = $this->doctorParts($row);

        if ($parts === null) {
            return $row->fingerprint;
        }

        return "Doctor {$parts['code']} on {$parts['type']} {$parts['id']}";
    }

    private function doctorSymptom(ProblemFingerprint $row): string
    {
        return $this->doctorTitle($row);
    }

    private function doctorEntry(ProblemFingerprint $row): string
    {
        $parts = $this->doctorParts($row);

        if ($parts === null) {
            return $row->fingerprint;
        }

        return "{$parts['type']} {$parts['id']} {$parts['code']}";
    }

    private function activityTitle(ProblemFingerprint $row): string
    {
        $parts = $this->activityParts($row);

        if ($parts === null) {
            return $row->fingerprint;
        }

        return "{$parts['command']} failed with {$parts['error_code']}";
    }

    private function activitySymptom(ProblemFingerprint $row): string
    {
        return $this->activityTitle($row);
    }

    private function logTitle(ProblemFingerprint $row): string
    {
        $parts = $this->logParts($row);

        if ($parts === null) {
            return $row->fingerprint;
        }

        return "{$parts['exception']} at {$parts['frame']}";
    }

    private function logSymptom(ProblemFingerprint $row): string
    {
        return $this->logTitle($row);
    }

    private function reason(ProblemFingerprint $row): string
    {
        $prefix = ProblemSource::Assist->value.'|';

        if (! str_starts_with($row->fingerprint, $prefix)) {
            return '';
        }

        return substr($row->fingerprint, strlen($prefix));
    }

    private function assistanceEntry(ProblemFingerprint $row): string
    {
        $ids = $this->evidence->ints($row->evidence['assistance_task_ids'] ?? null);

        if ($ids === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (int $id): string => (string) $id, $ids));
    }

    private function releaseTitle(ProblemFingerprint $row): string
    {
        $parts = $this->releaseParts($row);

        if ($parts === null) {
            return $row->fingerprint;
        }

        $kind = ReleaseAlertKind::tryFrom($parts['kind']);
        $label = $kind instanceof ReleaseAlertKind ? $kind->label() : $parts['kind'];

        return "{$label} for {$parts['target']} at ".substr($parts['sha'], 0, 12);
    }

    private function releaseEntry(ProblemFingerprint $row): string
    {
        $parts = $this->releaseParts($row);

        if ($parts === null) {
            return $row->fingerprint;
        }

        $repository = $this->text($row, 'release_repository');
        $entry = $repository === null ? $parts['sha'] : "{$repository}@{$parts['sha']}";
        $releaseId = $this->text($row, 'release_id');

        return $releaseId === null ? $entry : "{$entry}, release {$releaseId}";
    }

    /** @return array{kind: string, target: string, sha: string}|null */
    private function releaseParts(ProblemFingerprint $row): ?array
    {
        $pieces = $this->pieces($row, ProblemSource::Release, 3);

        if ($pieces === null) {
            return null;
        }

        return ['kind' => $pieces[0], 'target' => $pieces[1], 'sha' => $pieces[2]];
    }

    /** @return array{code: string, type: string, id: string}|null */
    private function doctorParts(ProblemFingerprint $row): ?array
    {
        $pieces = $this->pieces($row, ProblemSource::Doctor, 3);

        if ($pieces === null) {
            return null;
        }

        return ['code' => $pieces[0], 'type' => $pieces[1], 'id' => $pieces[2]];
    }

    /** @return array{command: string, error_code: string}|null */
    private function activityParts(ProblemFingerprint $row): ?array
    {
        $pieces = $this->pieces($row, ProblemSource::Activity, 2);

        if ($pieces === null) {
            return null;
        }

        return ['command' => $pieces[0], 'error_code' => $pieces[1]];
    }

    /** @return array{exception: string, frame: string}|null */
    private function logParts(ProblemFingerprint $row): ?array
    {
        $pieces = $this->pieces($row, ProblemSource::Log, 2);

        if ($pieces === null) {
            return null;
        }

        return ['exception' => $pieces[0], 'frame' => $pieces[1]];
    }

    /** @return list<string>|null */
    private function pieces(ProblemFingerprint $row, ProblemSource $source, int $count): ?array
    {
        $prefix = $source->value.'|';

        if (! str_starts_with($row->fingerprint, $prefix)) {
            return null;
        }

        $pieces = explode('|', substr($row->fingerprint, strlen($prefix)), $count);

        if (count($pieces) !== $count || in_array('', $pieces, true)) {
            return null;
        }

        return $pieces;
    }

    private function clearEpisode(ProblemFingerprint $row): void
    {
        $evidence = $row->evidence;
        unset(
            $evidence['observation_times'],
            $evidence['observation_counts'],
            $evidence['counted_blocks'],
            $evidence['request_ids'],
            $evidence['activity_ids'],
            $evidence['paths'],
            $evidence['evidence_urls'],
            $evidence['log_excerpt'],
            $evidence['source_path'],
        );
        $row->occurrences = 0;
        $row->first_seen = null;
        $row->last_seen = null;
        $row->evidence = $evidence;
    }

    private function text(ProblemFingerprint $row, string $key): ?string
    {
        $value = $row->evidence[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timeLine(?Carbon $time): string
    {
        if ($time === null) {
            return 'unknown';
        }

        return $time->copy()->utc()->format('Y-m-d H:i:s').' UTC';
    }

    private function muteFrom(?Task $task): Carbon
    {
        $stored = $task?->getAttributes()['updated_at'] ?? null;

        if ($stored instanceof CarbonInterface || (is_string($stored) && $stored !== '')) {
            return Carbon::parse($stored)->copy();
        }

        return now();
    }

    private function cut(string $value, int $limit, bool $ellipsis): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        if (! $ellipsis) {
            return mb_substr($value, 0, $limit);
        }

        return mb_substr($value, 0, $limit).'...';
    }

    private function oneLine(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($collapsed) ? $collapsed : trim($value);
    }

    /**
     * @param  list<string>  $times
     * @return list<int>
     */
    private function stamps(array $times): array
    {
        $stamps = [];

        foreach ($times as $time) {
            try {
                $stamps[] = Carbon::parse($time)->utc()->getTimestamp();
            } catch (Throwable) {
                continue;
            }
        }

        return $stamps;
    }

    /** @param list<int> $stamps */
    private function tenMinutesApart(array $stamps): bool
    {
        $count = count($stamps);

        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if (abs($stamps[$left] - $stamps[$right]) >= 600) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<int> $stamps */
    private function twoQuarterHours(array $stamps): bool
    {
        $blocks = [];

        foreach ($stamps as $stamp) {
            $blocks[intdiv($stamp, 900)] = true;
        }

        return count($blocks) >= 2;
    }

    /** @param list<int> $stamps */
    private function twoDates(array $stamps): bool
    {
        $dates = [];

        foreach ($stamps as $stamp) {
            $dates[Carbon::createFromTimestampUTC($stamp)->toDateString()] = true;
        }

        return count($dates) >= 2;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
