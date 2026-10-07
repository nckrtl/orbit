<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Actions\Doctor\RunDoctorAction;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Logs\LogRedactor;
use App\Domain\Shared\StoredInteger;
use App\Models\Activity;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records recurring Doctor, Activity, log, and assistance signals, and the release alerts that release commands push.
 * Each source commits its fingerprints and its cursor together.
 */
final readonly class ProblemCollector
{
    public function __construct(
        private RunDoctorAction $doctor,
        private GatewayLogLocator $logs,
        private GatewayLogReader $reader,
        private ProblemLogParser $parser,
        private ProblemEvidence $evidence,
        private ProblemSuppression $suppression,
        private LogRedactor $redactor,
    ) {}

    /** @return list<Throwable> */
    public function collect(): array
    {
        if (ProblemCollectorState::query()->orderBy('id')->first() === null) {
            ProblemCollectorState::query()->create([]);
        }

        $failures = [];

        try {
            $this->collectDoctor();
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        try {
            $this->collectActivity();
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        try {
            $this->collectLog();
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        try {
            $this->collectAssistance();
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        return $failures;
    }

    /**
     * Records one pushed release alert under `release|{key}`. Stored is false when the fingerprint is suppressed.
     *
     * @param  array<string, mixed>  $observation
     * @return array{fingerprint: string, stored: bool}
     */
    public function recordRelease(string $key, array $observation): array
    {
        $fingerprint = $this->fingerprint(ProblemSource::Release, $key);

        if ($this->suppression->suppressesSignal($fingerprint, $observation)) {
            return ['fingerprint' => $fingerprint, 'stored' => false];
        }

        DB::transaction(fn () => $this->record($fingerprint, ProblemSource::Release, $observation));

        return ['fingerprint' => $fingerprint, 'stored' => true];
    }

    private function collectDoctor(): void
    {
        $issues = $this->doctorIssues($this->doctor->executeForFleet());

        DB::transaction(function () use ($issues): void {
            $state = $this->lockedState();
            $resume = $state->doctor_resume_key;
            $pending = [];

            foreach ($issues as $issue) {
                if (is_string($resume) && $resume !== '' && strcmp($issue['fingerprint'], $resume) <= 0) {
                    continue;
                }

                $pending[] = $issue;
            }

            $batch = [];

            foreach ($pending as $issue) {
                $last = $batch === [] ? null : array_last($batch)['fingerprint'];

                if (count($batch) >= 200 && $issue['fingerprint'] !== $last) {
                    break;
                }

                $batch[] = $issue;
            }

            foreach ($batch as $issue) {
                $this->record($issue['fingerprint'], ProblemSource::Doctor, [
                    'expected' => $issue['expected'],
                    'observed' => $issue['observed'],
                    'summary' => $issue['summary'],
                ]);
            }

            $lastFingerprint = $batch === [] ? null : array_last($batch)['fingerprint'];
            $state->doctor_resume_key = count($batch) < count($pending) ? $lastFingerprint : null;
            $state->save();
        });
    }

    private function collectActivity(): void
    {
        DB::transaction(function (): void {
            $state = $this->lockedState();

            // The first run remembers the latest row and does not count history.
            if ($state->activity_cursor === null) {
                $state->activity_cursor = StoredInteger::fromOrZero(Activity::query()->max('id'));
                $state->save();

                return;
            }

            $rows = Activity::query()
                ->where('id', '>', $state->activity_cursor)
                ->orderBy('id')
                ->limit(500)
                ->get();

            foreach ($rows as $row) {
                if (! $this->isServerClass($row)) {
                    continue;
                }

                $observation = [
                    'request_ids' => $row->request_id !== '' ? [$row->request_id] : [],
                    'activity_ids' => [$row->id],
                    'paths' => $this->activityPaths($row),
                ];
                $message = $this->activityMessage($row);

                if ($message !== null) {
                    $observation['error_message'] = $message;
                }

                $createdAt = $row->getAttribute('created_at');
                $this->record(
                    $this->fingerprint(ProblemSource::Activity, $row->command.'|'.$this->activityCode($row)),
                    ProblemSource::Activity,
                    $observation,
                    $createdAt instanceof CarbonInterface ? $createdAt : null,
                );
            }

            $last = $rows->last();

            if ($last instanceof Activity) {
                $state->activity_cursor = $last->id;
                $state->save();
            }
        });
    }

    private function collectLog(): void
    {
        DB::transaction(function (): void {
            $state = $this->lockedState();
            $current = $this->logs->current();

            // The first run that sees a file starts at the end and does not count history.
            if ($state->log_path === null || $state->log_inode === null || $state->log_offset === null) {
                if ($current === null) {
                    return;
                }

                $state->log_path = $current->path;
                $state->log_inode = $current->inode;
                $state->log_offset = $current->size;
                $state->save();

                return;
            }

            $reading = $this->logs->matching($state->log_path, $state->log_inode)
                ?? $this->logs->findInode($state->log_inode);
            $offset = $state->log_offset;

            if (! $reading instanceof GatewayLogFile) {
                if ($current === null) {
                    return;
                }

                $reading = $current;
                $offset = 0;
            } elseif ($offset > $reading->size) {
                $offset = $reading->size;
            }

            $chunk = $this->reader->read($reading->path, $offset);

            foreach ($chunk->records as $record) {
                $signal = $this->parser->signal($record);

                if (! $signal instanceof ProblemLogSignal) {
                    continue;
                }

                $observation = [
                    'request_ids' => $signal->requestId === null ? [] : [$signal->requestId],
                    'log_excerpt' => $signal->excerpt,
                ];
                $sourcePath = $this->suppression->logFramePath($signal->frame);

                if ($sourcePath !== null) {
                    $observation['source_path'] = $sourcePath;
                }

                $this->record(
                    $this->fingerprint(ProblemSource::Log, $signal->exceptionClass.'|'.$signal->frame),
                    ProblemSource::Log,
                    $observation,
                    $this->logRecordedAt($signal->recordedAt),
                );
            }

            $state->log_path = $reading->path;
            $state->log_inode = $reading->inode;
            $state->log_offset = $chunk->offset;
            $fresh = $this->logs->describe($reading->path);
            $finished = $chunk->offset >= $fresh->size;

            if ($finished && $current instanceof GatewayLogFile && ($current->path !== $reading->path || $current->inode !== $reading->inode)) {
                $state->log_path = $current->path;
                $state->log_inode = $current->inode;
                $state->log_offset = 0;
            }

            $state->save();
        });
    }

    private function collectAssistance(): void
    {
        DB::transaction(function (): void {
            $this->lockedState();
            $stored = $this->storedAssistanceIds();
            $stillOpen = $stored === []
                ? []
                : array_values($this->tasks()
                    ->whereIn('id', $stored)
                    ->where('assistance_requested', true)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => StoredInteger::from($id))
                    ->all());
            $this->pruneAssistance($stillOpen);

            $fresh = $this->tasks()
                ->where('assistance_requested', true)
                ->whereNotNull('assistance_reason')
                ->when($stillOpen !== [], fn ($query) => $query->whereNotIn('id', $stillOpen))
                ->orderBy('id')
                ->limit(200)
                ->get(['id', 'assistance_reason']);

            foreach ($fresh as $task) {
                $reason = $task->assistance_reason;

                if (! is_string($reason)) {
                    continue;
                }

                $normalized = $this->normalizeReason($reason);

                if ($normalized === '') {
                    continue;
                }

                $fingerprint = $this->fingerprint(ProblemSource::Assist, $normalized);

                if ($this->assistanceIsFull($fingerprint)) {
                    continue;
                }

                $this->record($fingerprint, ProblemSource::Assist, [
                    'assistance_task_ids' => [$task->id],
                    'assistance_reason' => $reason,
                ]);
            }
        });
    }

    /** @return list<array{fingerprint: string, expected: bool|string|null, observed: bool|string|null, summary: string}> */
    private function doctorIssues(DoctorReportData $report): array
    {
        $issues = [];

        foreach ($report->nodes as $node) {
            foreach ($node->families as $family) {
                foreach ($family->issues as $issue) {
                    // Doctor health ignores informational findings, so they are not problems either.
                    if ($issue->kind === DoctorIssueKind::Informational) {
                        continue;
                    }

                    $resource = $issue->resourceId;
                    $segment = $resource === null || $resource === '' ? 'none' : (string) $resource;
                    $issues[] = [
                        'fingerprint' => $this->fingerprint(
                            ProblemSource::Doctor,
                            $issue->code.'|'.$issue->resourceType.'|'.$segment,
                        ),
                        'expected' => $issue->expected,
                        'observed' => $issue->observed,
                        'summary' => $issue->summary,
                    ];
                }
            }
        }

        usort($issues, static fn (array $left, array $right): int => strcmp($left['fingerprint'], $right['fingerprint']));

        return $issues;
    }

    private function isServerClass(Activity $activity): bool
    {
        $code = $activity->error_code;

        if ($code === 'gateway.unhandled' || $code === 'activity.interrupted') {
            return true;
        }

        if (is_string($code) && (str_ends_with($code, '_failed') || str_ends_with($code, '.unavailable'))) {
            return true;
        }

        if (is_string($code) && preg_match('/\Ahttp\.(\d{3})\z/', $code, $match) === 1 && (int) $match[1] >= 500) {
            return true;
        }

        return $activity->exit_code !== null && $activity->exit_code !== 0;
    }

    private function activityCode(Activity $activity): string
    {
        if (is_string($activity->error_code) && $activity->error_code !== '') {
            return $activity->error_code;
        }

        return 'exit';
    }

    /** @return list<string> */
    private function activityPaths(Activity $activity): array
    {
        $path = $activity->properties?->get('path');

        return is_string($path) && $path !== '' ? [$path] : [];
    }

    private function activityMessage(Activity $activity): ?string
    {
        $message = $activity->properties?->get('error_message');

        if (! is_string($message) || $message === '') {
            return null;
        }

        return $this->redactor->redact($message, []);
    }

    private function normalizeReason(string $reason): string
    {
        $normalized = mb_strtolower(trim($reason));
        $normalized = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '#',
            $normalized,
        ) ?? $normalized;
        $normalized = preg_replace('/\d+/', '#', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /** @return Builder<Task> */
    private function tasks(): Builder
    {
        return Task::query()->withoutGlobalScope('subtask');
    }

    /** @return list<int> */
    private function storedAssistanceIds(): array
    {
        $ids = [];
        $rows = ProblemFingerprint::query()->where('source', ProblemSource::Assist)->get(['evidence']);

        foreach ($rows as $row) {
            foreach ($this->evidence->ints($row->evidence['assistance_task_ids'] ?? null) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @param list<int> $stillOpen */
    private function pruneAssistance(array $stillOpen): void
    {
        $open = array_fill_keys($stillOpen, true);
        $rows = ProblemFingerprint::query()->where('source', ProblemSource::Assist)->lockForUpdate()->get();

        foreach ($rows as $row) {
            $evidence = $row->evidence;
            $ids = $this->evidence->ints($evidence['assistance_task_ids'] ?? null);
            $kept = array_values(array_filter($ids, static fn (int $id): bool => isset($open[$id])));

            if ($kept === $ids) {
                continue;
            }

            $evidence['assistance_task_ids'] = $kept;
            $row->evidence = $evidence;
            $row->save();
        }
    }

    private function assistanceIsFull(string $fingerprint): bool
    {
        $row = ProblemFingerprint::query()->where('fingerprint', $fingerprint)->first();

        if (! $row instanceof ProblemFingerprint) {
            return false;
        }

        return count($this->evidence->ints($row->evidence['assistance_task_ids'] ?? null)) >= 200;
    }

    private function fingerprint(ProblemSource $source, string $rest): string
    {
        $key = $source->value.'|'.$rest;

        if (mb_strlen($key) <= 255) {
            return $key;
        }

        return $source->value.'#'.substr(hash('sha256', $key), 0, 12);
    }

    /**
     * The bracketed log header is written in the Gateway application timezone.
     */
    private function logRecordedAt(string $recordedAt): Carbon
    {
        $timezone = config('app.timezone');

        return Carbon::parse($recordedAt, is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
    }

    /** @param array<string, mixed> $observation */
    private function record(string $fingerprint, ProblemSource $source, array $observation, ?CarbonInterface $seenAt = null): void
    {
        if ($this->suppression->suppressesSignal($fingerprint, $observation)) {
            return;
        }

        $seenAt = Carbon::parse($seenAt ?? now())->utc();
        $row = ProblemFingerprint::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if (! $row instanceof ProblemFingerprint) {
            $row = new ProblemFingerprint([
                'fingerprint' => $fingerprint,
                'source' => $source,
                'occurrences' => 0,
                'evidence' => [],
            ]);
        }

        $evidence = $row->evidence;
        $occurrences = $row->occurrences;
        $settled = $this->evidence->legacyEpisode($evidence, $source);

        if ($settled !== null) {
            $evidence = $settled['evidence'];
            $occurrences = $settled['occurrences'];

            if ($settled['reset_seen']) {
                $row->first_seen = null;
                $row->last_seen = null;
            }
        }

        $times = $this->observationTimes($evidence);
        $counts = $this->observationCounts($evidence, count($times));
        $blocks = $this->countedBlocks($evidence, $times);
        $block = intdiv($seenAt->getTimestamp(), 300);

        if (! in_array($block, $blocks, true)) {
            $blocks[] = $block;
            $occurrences++;
            $times[] = $seenAt->format('Y-m-d\TH:i:s.u\Z');
            $counts[] = 1;
            [$times, $counts] = $this->trimSample($times, $counts);
        } else {
            $index = $this->sampleIndex($times, $block);

            if ($index !== null) {
                $counts[$index]++;
            }
        }

        $row->source = $source;
        $row->occurrences = $occurrences;
        $row->first_seen ??= $seenAt;
        $row->last_seen = $seenAt;
        $stored = $this->evidence->apply($evidence, $observation);
        $stored['observation_times'] = $times;
        $stored['observation_counts'] = $counts;
        $stored['counted_blocks'] = $blocks;
        $row->evidence = $stored;
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private function observationTimes(array $evidence): array
    {
        $times = $evidence['observation_times'] ?? null;

        if (! is_array($times)) {
            return [];
        }

        $parsed = [];

        foreach ($times as $time) {
            if (is_string($time) && $time !== '') {
                $parsed[] = $time;
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<int>
     */
    private function observationCounts(array $evidence, int $windows): array
    {
        $counts = array_slice($this->evidence->ints($evidence['observation_counts'] ?? null), 0, $windows);

        while (count($counts) < $windows) {
            $counts[] = 1;
        }

        return $counts;
    }

    /**
     * The display sample keeps the newest 20 windows. counted_blocks keeps every window in the episode,
     * including one the sample has dropped, so a late signal in that block is not counted again.
     *
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $times
     * @return list<int>
     */
    private function countedBlocks(array $evidence, array $times): array
    {
        if (array_key_exists('counted_blocks', $evidence)) {
            return $this->evidence->ints($evidence['counted_blocks']);
        }

        $blocks = [];

        foreach ($times as $time) {
            $stamp = $this->unixTime($time);

            if ($stamp === null) {
                continue;
            }

            $block = intdiv($stamp, 300);

            if (! in_array($block, $blocks, true)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** @param list<string> $times */
    private function sampleIndex(array $times, int $block): ?int
    {
        foreach ($times as $index => $time) {
            $stamp = $this->unixTime($time);

            if ($stamp !== null && intdiv($stamp, 300) === $block) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $times
     * @param  list<int>  $counts
     * @return array{0: list<string>, 1: list<int>}
     */
    private function trimSample(array $times, array $counts): array
    {
        $order = array_keys($times);
        usort($order, fn (int $left, int $right): int => ($this->unixTime($times[$left]) ?? 0) <=> ($this->unixTime($times[$right]) ?? 0));
        $trimmedTimes = [];
        $trimmedCounts = [];

        foreach (array_slice($order, -20) as $index) {
            $trimmedTimes[] = $times[$index];
            $trimmedCounts[] = $counts[$index] ?? 1;
        }

        return [$trimmedTimes, $trimmedCounts];
    }

    private function unixTime(string $time): ?int
    {
        try {
            return Carbon::parse($time)->utc()->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function lockedState(): ProblemCollectorState
    {
        $state = ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->first();

        if ($state instanceof ProblemCollectorState) {
            return $state;
        }

        return ProblemCollectorState::query()->create([]);
    }
}
