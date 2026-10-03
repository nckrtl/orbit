<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;

/**
 * The review packet for one subtask (ADR 0169).
 *
 * The text is at most 16,000 characters. No part is exempt. A part under its cap leaves those
 * characters for the diff body, which also stops at 16,384 bytes. Retrieval commands are reserved
 * first and are never cut. A continued turn omits the group brief, the deliverables, and the
 * earlier approvals, and that spare goes to the diff body. Every cut note names
 * `.git/orbit/context.md`, which holds those parts uncut.
 */
final readonly class TaskReviewPacket
{
    public const int Limit = 16_000;

    public const int DiffBytes = 16_384;

    public const int BriefLimit = 2_000;

    public const int ResolutionLimit = 2_000;

    public const int DeliverablesLimit = 2_000;

    public const int DeliverableLineLimit = 240;

    public const int DescriptionLimit = 160;

    public const int ApprovalsLimit = 1_500;

    public const int ApprovalLineLimit = 200;

    public const int DiffStatLimit = 1_500;

    public const int HandoffLimit = 2_000;

    public const int CommandLimit = 160;

    public const int RetrievalLimit = 1_000;

    public const int ConsultsLimit = 2_000;

    public const int ConsultLineLimit = 400;

    /**
     * @param  list<TaskDeliverable>  $deliverables
     * @param  list<array{title: string, summary: string}>  $approvals  earlier approved subtasks, oldest first
     * @param  list<array{path: string, insertions: int, deletions: int}>  $diffFiles  tracked and untracked files, empty when the list was cut
     * @param  array{files: int, insertions: int, deletions: int}|null  $diffCounts  full counts when the path list was cut
     * @param  string|null  $taskCheck  the Project task check, or null when the Project has none
     * @param  list<array{question: string, answer: string}>  $consults  answered consults, oldest first
     */
    public function __construct(
        private string $groupBrief,
        private int $subtaskId,
        private string $subtaskTitle,
        private string $subtaskBrief,
        private array $deliverables,
        private array $approvals,
        private array $diffFiles,
        private string $diff,
        private ?string $taskCheck,
        private string $handoffStatus,
        private ?int $handoffExitCode,
        private ?TaskDeliverableEvidence $evidence,
        private string $startCommit,
        private bool $continued = false,
        private bool $opensPullRequest = false,
        private bool $diffFilesComplete = true,
        private bool $diffAvailable = true,
        private ?array $diffCounts = null,
        private string $resolution = '',
        private ?int $threadId = null,
        private string $groupStartCommit = '',
        private array $consults = [],
    ) {}

    public function render(): string
    {
        $before = array_values(array_filter([
            $this->preamble(),
            $this->resolution === '' ? '' : $this->section('Resolution', $this->cutEnd(
                $this->resolution,
                self::ResolutionLimit,
                'The end is cut. '.TaskReviewContext::Path.' holds the full resolution.',
            )),
            $this->continued ? '' : $this->section('Group brief', $this->cutEnd(
                $this->groupBrief,
                self::BriefLimit,
                'The end is cut. '.TaskReviewContext::Path.' holds the full brief.',
            )),
            $this->section('Subtask brief', $this->cutEnd(
                $this->subtaskBrief,
                self::BriefLimit,
                'The end is cut. '.TaskReviewContext::Path.' holds the full brief.',
            )),
            $this->continued ? '' : $this->section('Deliverables', $this->deliverablesText()),
            $this->continued ? '' : $this->section('Earlier approved subtasks', $this->approvalsText()),
            $this->continued || $this->consults === [] ? '' : $this->section('Consults', $this->consultsText()),
            $this->section('Diff stat', $this->diffStat()),
            $this->section('Handoff', $this->handoff()),
        ], static fn (string $part): bool => $part !== ''));
        $retrieval = $this->retrieval();
        $closing = TaskTurnInstructions::reviewer($this->opensPullRequest, $this->deliverables, $this->threadId);

        return $this->withinLimit(
            implode("\n\n", [...$before, $this->diffSection($before, [$retrieval, $closing]), $retrieval, $closing]),
            $retrieval,
            $closing,
        );
    }

    /**
     * Shrinks only the material before the retrieval commands and the closing instructions.
     * The diff body is already the remainder, so a long preamble cannot be recovered by cutting the diff.
     */
    private function withinLimit(string $text, string $retrieval, string $closing): string
    {
        if (mb_strlen($text) <= self::Limit) {
            return $text;
        }
        $suffix = "\n\n".$retrieval."\n\n".$closing;
        $room = self::Limit - mb_strlen($suffix);
        if ($room < 1 || ! str_ends_with($text, $suffix)) {
            return mb_substr($text, 0, self::Limit);
        }

        return mb_substr($text, 0, $room).$suffix;
    }

    private function preamble(): string
    {
        $rule = 'Do not re-run the Project task check or deliverable commands the handoff already passed.';
        if ($this->taskCheck !== null) {
            $shown = mb_substr($this->taskCheck, 0, self::CommandLimit);
            $rule .= ' The Project task check is `'.$shown.'`.';
            if (mb_strlen($this->taskCheck) > self::CommandLimit) {
                $rule .= ' $(git rev-parse --git-path orbit)/check.log holds the rest. '.TaskReviewContext::Path.' holds the full task context.';
            }
        }
        $rule .= ' Run another command only when you need evidence the handoff result does not give, and say why in the approved or changes_requested summary.';

        return implode("\n\n", [
            'Review subtask #'.$this->subtaskId.': '.$this->subtaskTitle,
            'The implementer works with a minimal toolset and has no web access. You do: use your web and documentation tools to confirm that framework and library usage matches current documentation for the versions this Project uses.',
            $rule,
        ]);
    }

    private function section(string $heading, string $body): string
    {
        return $heading."\n".$body;
    }

    /** Keeps the start of a brief. The note replaces the cut end and stays inside the cap. */
    private function cutEnd(string $text, int $limit, string $note): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $suffix = "\n".$note;
        $room = $limit - mb_strlen($suffix);

        return ($room > 0 ? mb_substr($text, 0, $room) : '').($room > 0 ? $suffix : mb_substr($note, 0, $limit));
    }

    private function deliverablesText(): string
    {
        if ($this->deliverables === []) {
            return 'None.';
        }
        $lines = [];
        $cut = false;
        foreach ($this->deliverables as $deliverable) {
            $line = $this->deliverableLine($deliverable);
            $lines[] = $line['text'];
            $cut = $cut || $line['cut'];
        }

        return $this->fitLines($lines, self::DeliverablesLimit, false, function (int $omitted) use ($cut): string {
            $dropped = match (true) {
                $omitted === 1 => '1 deliverable was omitted.',
                $omitted > 1 => $omitted.' deliverables were omitted.',
                default => '',
            };
            $show = $omitted > 0 || $cut ? TaskReviewContext::Path.' holds every field.' : '';

            return trim($dropped.' '.$show);
        });
    }

    /** @return array{text: string, cut: bool} */
    private function deliverableLine(TaskDeliverable $deliverable): array
    {
        $line = $deliverable->line();
        $prefix = mb_substr($line, 0, mb_strlen($line) - mb_strlen($deliverable->description));
        $room = self::DeliverableLineLimit - mb_strlen($prefix);
        if ($room <= 0) {
            return ['text' => mb_substr($prefix, 0, self::DeliverableLineLimit), 'cut' => true];
        }
        $descriptionRoom = min(self::DescriptionLimit, $room);
        $shown = mb_substr($deliverable->description, 0, $descriptionRoom);

        return ['text' => $prefix.$shown, 'cut' => mb_strlen($deliverable->description) > mb_strlen($shown)];
    }

    private function approvalsText(): string
    {
        if ($this->approvals === []) {
            return 'None.';
        }
        $lines = [];
        $cut = false;
        foreach ($this->approvals as $approval) {
            $line = '- '.$approval['title'].': '.$approval['summary'];
            $lines[] = mb_substr($line, 0, self::ApprovalLineLimit);
            $cut = $cut || mb_strlen($line) > self::ApprovalLineLimit;
        }

        return $this->fitLines($lines, self::ApprovalsLimit, true, function (int $omitted) use ($cut): string {
            $dropped = match (true) {
                $omitted === 1 => '1 earlier approval was omitted.',
                $omitted > 1 => $omitted.' earlier approvals were omitted.',
                default => '',
            };
            $show = $omitted > 0 || $cut ? TaskReviewContext::Path.' holds each approval body.' : '';

            return trim($dropped.' '.$show);
        });
    }

    private function consultsText(): string
    {
        $lines = [];
        $cut = false;
        foreach ($this->consults as $consult) {
            $line = $this->consultLine($consult['question'], $consult['answer']);
            $lines[] = $line['text'];
            $cut = $cut || $line['cut'];
        }

        return $this->fitLines($lines, self::ConsultsLimit, true, function (int $omitted) use ($cut): string {
            $dropped = match (true) {
                $omitted === 1 => '1 answered consult was omitted.',
                $omitted > 1 => $omitted.' answered consults were omitted.',
                default => '',
            };
            $show = $omitted > 0 || $cut ? TaskReviewContext::Path.' holds each question and answer.' : '';

            return trim($dropped.' '.$show);
        });
    }

    /**
     * Keeps a prefix of the question and a prefix of the answer. A long question cannot erase the answer.
     *
     * @return array{text: string, cut: bool}
     */
    private function consultLine(string $question, string $answer): array
    {
        $prefix = '- Question: ';
        $middle = ' Answer: ';
        $full = $prefix.$question.$middle.$answer;
        if (mb_strlen($full) <= self::ConsultLineLimit) {
            return ['text' => $full, 'cut' => false];
        }
        $budget = self::ConsultLineLimit - mb_strlen($prefix) - mb_strlen($middle);
        $questionRoom = min(mb_strlen($question), intdiv($budget, 2));
        $answerRoom = min(mb_strlen($answer), $budget - $questionRoom);
        $spare = $budget - $questionRoom - $answerRoom;
        if (mb_strlen($question) > $questionRoom) {
            $questionRoom += min($spare, mb_strlen($question) - $questionRoom);
            $spare = $budget - $questionRoom - $answerRoom;
        }
        if (mb_strlen($answer) > $answerRoom) {
            $answerRoom += min($spare, mb_strlen($answer) - $answerRoom);
        }

        return [
            'text' => $prefix.mb_substr($question, 0, $questionRoom).$middle.mb_substr($answer, 0, $answerRoom),
            'cut' => true,
        ];
    }

    private function diffStat(): string
    {
        $summary = $this->diffSummary();
        if (! $this->diffFilesComplete) {
            $text = $summary."\nThe path list was cut. The stat command prints the rest. ".TaskReviewContext::Path.' holds the full task context.';

            return mb_strlen($text) > self::DiffStatLimit ? mb_substr($text, 0, self::DiffStatLimit) : $text;
        }
        if (mb_strlen($summary) > self::DiffStatLimit) {
            return mb_substr($summary, 0, self::DiffStatLimit);
        }
        $paths = array_map(fn (array $file): string => $this->utf8($file['path']), $this->diffFiles);
        if ($paths === []) {
            return $summary;
        }
        $budget = self::DiffStatLimit - mb_strlen($summary) - 1;
        $fitted = $this->fitLines($paths, $budget, false, function (int $omitted): string {
            if ($omitted === 0) {
                return '';
            }
            $label = $omitted === 1 ? '1 path was omitted.' : $omitted.' paths were omitted.';

            return $label.' The stat command prints the rest. '.TaskReviewContext::Path.' holds the full task context.';
        });

        return $summary."\n".$fitted;
    }

    private function diffSummary(): string
    {
        if (! $this->diffFilesComplete && is_array($this->diffCounts)) {
            return $this->counted($this->diffCounts['files'], 'file changed', 'files changed').', '
                .$this->counted($this->diffCounts['insertions'], 'insertion(+)', 'insertions(+)').', '
                .$this->counted($this->diffCounts['deletions'], 'deletion(-)', 'deletions(-)');
        }
        $insertions = 0;
        $deletions = 0;
        foreach ($this->diffFiles as $file) {
            $insertions += $file['insertions'];
            $deletions += $file['deletions'];
        }

        return $this->counted(count($this->diffFiles), 'file changed', 'files changed').', '
            .$this->counted($insertions, 'insertion(+)', 'insertions(+)').', '
            .$this->counted($deletions, 'deletion(-)', 'deletions(-)');
    }

    private function counted(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function handoff(): string
    {
        $records = $this->commandRecords();
        $status = 'Status: '.$this->handoffStatus;
        $count = count($records);
        for ($kept = $count; $kept >= 0; $kept--) {
            $text = $this->composeHandoff($status, $records, $kept);
            if ($text !== null) {
                return $text;
            }
        }

        return mb_substr($status, 0, self::HandoffLimit);
    }

    /**
     * @param  list<array{line: string, tail: ?string, truncated: bool}>  $records
     */
    private function composeHandoff(string $status, array $records, int $kept): ?string
    {
        $slice = array_slice($records, 0, $kept);
        $omitted = count($records) - $kept;
        $truncated = false;
        $lines = [$status];
        $tails = [];
        foreach ($slice as $index => $record) {
            $lines[] = $record['line'];
            $truncated = $truncated || $record['truncated'];
            if (is_string($record['tail']) && $record['tail'] !== '') {
                $tails[$index + 1] = $record['tail'];
            }
        }
        $assemble = function (array $lines, int $includedTails) use ($omitted, $truncated, $tails): string {
            $note = $this->handoffNote($omitted, $truncated || $includedTails < count($tails));
            $text = implode("\n", $lines);

            return $note === '' ? $text : $text."\n".$note;
        };
        if (mb_strlen($assemble($lines, 0)) > self::HandoffLimit) {
            return null;
        }
        $included = 0;
        foreach ($tails as $lineIndex => $tail) {
            $trial = $lines;
            $trial[$lineIndex] .= ': '.$tail;
            if (mb_strlen($assemble($trial, $included + 1)) > self::HandoffLimit) {
                break;
            }
            $lines = $trial;
            $included++;
        }

        return $assemble($lines, $included);
    }

    private function handoffNote(int $omitted, bool $cut): string
    {
        if ($omitted === 0 && ! $cut) {
            return '';
        }
        $dropped = match (true) {
            $omitted === 1 => '1 command was omitted.',
            $omitted > 1 => $omitted.' commands were omitted.',
            default => '',
        };

        return trim($dropped.' $(git rev-parse --git-path orbit)/check.log holds the command text and any cut tail. $(git rev-parse --git-path orbit)/check.json stores the exit codes. '.TaskReviewContext::Path.' holds the full task context.');
    }

    /** The Project task check, then each deliverable command, including its base run when requested.
     * @return list<array{line: string, tail: ?string, truncated: bool}>
     */
    private function commandRecords(): array
    {
        $records = [];
        if (is_string($this->taskCheck) && $this->taskCheck !== '' && is_int($this->handoffExitCode)) {
            $records[] = $this->commandRecord($this->taskCheck, '.', $this->handoffExitCode, null);
        }
        foreach ($this->deliverables as $deliverable) {
            if ($deliverable->type !== TaskDeliverableType::Command) {
                continue;
            }
            $run = $this->evidence?->commands[$deliverable->id] ?? null;
            if (! is_array($run)) {
                continue;
            }
            if (isset($run['base_exit_code'])) {
                $records[] = $this->commandRecord($deliverable->command, $this->directory($deliverable->directory), $run['base_exit_code'], $run['base_output'] ?? null, ' on the start commit');
            }
            $records[] = $this->commandRecord($deliverable->command, $this->directory($deliverable->directory), $run['exit_code'], $run['output']);
        }

        return $records;
    }

    /**
     * @return array{line: string, tail: ?string, truncated: bool}
     */
    private function commandRecord(string $command, string $directory, int $exitCode, ?string $tail, string $suffix = ''): array
    {
        return [
            'line' => '`'.mb_substr($command, 0, self::CommandLimit).'` in '.$directory.' exited '.$exitCode.$suffix,
            'tail' => $tail,
            'truncated' => mb_strlen($command) > self::CommandLimit,
        ];
    }

    private function directory(string $directory): string
    {
        $relative = TaskDeliverable::relative($directory);

        return $relative === '' ? '.' : $relative;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function diffSection(array $before, array $after): string
    {
        $heading = "Diff\n";
        if (! $this->diffAvailable) {
            return $heading.'The diff could not be read. The diff command prints it.';
        }
        // git diff emits file bytes unchanged. Scrub before both the whole diff and the cut prefix.
        $diff = $this->utf8($this->diff);
        $note = "\nThe end of the diff is cut. The diff command prints the rest, including the content of untracked files. ".TaskReviewContext::Path.' holds the full task context.';
        $used = mb_strlen(implode("\n\n", [...$before, ...$after])) + mb_strlen("\n\n");
        $remaining = max(0, self::Limit - $used - mb_strlen($heading));
        if ($this->fits($diff, $remaining)) {
            return $heading.$diff;
        }
        if ($remaining >= mb_strlen($note)) {
            return $heading.$this->prefixWithin($diff, $remaining - mb_strlen($note), self::DiffBytes).$note;
        }

        return $heading.$this->prefixWithin($diff, $remaining, self::DiffBytes);
    }

    /** Replaces bytes that are not valid UTF-8 so the packet can be JSON-encoded. */
    private function utf8(string $text): string
    {
        return mb_scrub($text, 'UTF-8');
    }

    private function fits(string $text, int $characters): bool
    {
        return mb_strlen($text) <= $characters && strlen($text) <= self::DiffBytes;
    }

    /** The longest prefix that stays inside the character budget and the byte cap. */
    private function prefixWithin(string $text, int $characters, int $bytes): string
    {
        if ($characters <= 0 || $bytes <= 0 || $text === '') {
            return '';
        }
        $length = mb_strlen($text);
        $high = min($length, $characters);
        $low = 0;
        $best = '';
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $slice = $mid === $length ? $text : mb_substr($text, 0, $mid);
            if (strlen($slice) <= $bytes) {
                $best = $slice;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best;
    }

    private function retrieval(): string
    {
        $commands = $this->statCommand()."\n".$this->diffCommand();
        $group = TaskTurnInstructions::groupStart($this->groupStartCommit);

        return "Retrieval\n".$commands.($group === '' ? '' : "\n".$group);
    }

    private function statCommand(): string
    {
        return 'git diff --stat '.$this->commit().'; git ls-files --others --exclude-standard -z | while IFS= read -r -d \'\' path; do git diff --no-index --stat -- /dev/null "$path" || true; done';
    }

    private function diffCommand(): string
    {
        return 'git diff '.$this->commit().'; git ls-files --others --exclude-standard -z | while IFS= read -r -d \'\' path; do git diff --no-index -- /dev/null "$path" || true; done';
    }

    private function commit(): string
    {
        return $this->startCommit !== '' ? $this->startCommit : 'START';
    }

    /**
     * @param  list<string>  $lines
     * @param  callable(int): string  $noteFor  the omission line for a dropped count, or empty when nothing is dropped
     */
    private function fitLines(array $lines, int $limit, bool $dropOldest, callable $noteFor): string
    {
        $total = count($lines);
        for ($dropped = 0; $dropped <= $total; $dropped++) {
            $kept = $dropOldest ? array_slice($lines, $dropped) : array_slice($lines, 0, $total - $dropped);
            $note = $noteFor($dropped);
            if ($note !== '') {
                if ($dropOldest) {
                    array_unshift($kept, $note);
                } else {
                    $kept[] = $note;
                }
            }
            $text = implode("\n", $kept);
            if (mb_strlen($text) <= $limit) {
                return $text;
            }
        }

        return mb_substr($noteFor($total), 0, max(0, $limit));
    }
}
