<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewDiffException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/**
 * Reads a subtask diff without updating the index. The body is capped. The summary is printed last
 * so a tail-truncated capture still has the full counts, and a failed read is not an empty diff.
 */
final readonly class RemoteTaskReviewDiff implements TaskReviewDiff
{
    private const string DiffMarker = '---ORBIT-REVIEW-DIFF---';

    private const string SummaryMarker = '---ORBIT-REVIEW-SUMMARY---';

    public function __construct(private TaskWorkspaceExecutor $ssh) {}

    public function read(Instance $instance, string $startCommit): array
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '' || preg_match('/^[0-9a-f]{7,64}$/i', $startCommit) !== 1) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }

        try {
            $result = $this->ssh->execute($instance, new RemoteCommand(
                arguments: TaskWorkerUser::arguments(['bash', '-seu', '--', $instance->checkout_path, $startCommit], $instance),
                input: WorkspaceGit::bashPreamble(TaskWorkerUser::name($instance) === null ? null : $instance->checkout_path).<<<'BASH'
                    set -o pipefail
                    cd -- "$1"
                    start=$2
                    if ! git rev-parse --verify --quiet "$start^{commit}" >/dev/null; then
                        exit 2
                    fi
                    work=$(mktemp -d)
                    trap 'rm -rf "$work"' EXIT
                    index=$(git rev-parse --git-path index)
                    if [ -f "$index" ]; then
                        cp --preserve=timestamps -- "$index" "$work/index"
                    else
                        GIT_INDEX_FILE="$work/index" git read-tree --empty
                    fi
                    export GIT_INDEX_FILE="$work/index"
                    git ls-files --others --exclude-standard -z > "$work/untracked"
                    if [ -s "$work/untracked" ]; then
                        git --literal-pathspecs add --intent-to-add --pathspec-from-file="$work/untracked" --pathspec-file-nul
                    fi
                    numstat="$work/numstat"
                    git diff --numstat "$start" > "$numstat"
                    files=0
                    insertions=0
                    deletions=0
                    while IFS= read -r line || [ -n "$line" ]; do
                        [ -z "$line" ] && continue
                        ins=${line%%$'\t'*}
                        rest=${line#*$'\t'}
                        del=${rest%%$'\t'*}
                        files=$((files + 1))
                        if [ "$ins" != "-" ]; then insertions=$((insertions + ins)); fi
                        if [ "$del" != "-" ]; then deletions=$((deletions + del)); fi
                    done < "$numstat"
                    cat "$numstat"
                    printf '%s\n' '---ORBIT-REVIEW-DIFF---'
                    set +e
                    {
                        git diff "$start"
                        diff_status=$?
                        if [ "$diff_status" -ne 0 ]; then
                            exit "$diff_status"
                        fi
                    } | head -c 20000
                    body=${PIPESTATUS[0]}
                    set -e
                    if [ "$body" -ne 0 ] && [ "$body" -ne 141 ]; then
                        exit "$body"
                    fi
                    printf '\n%s\n%s\t%s\t%s\n' '---ORBIT-REVIEW-SUMMARY---' "$files" "$insertions" "$deletions"
                    BASH,
                maxOutputBytes: 65_536,
            ), 'task-review-diff', 'tasks.diff_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskReviewDiffException('The review diff could not be read.', previous: $exception);
        }

        return $this->parse($result);
    }

    /**
     * @return array{
     *     files: list<array{path: string, insertions: int, deletions: int}>,
     *     diff: string,
     *     files_complete: bool,
     *     diff_available: bool,
     *     summary: array{files: int, insertions: int, deletions: int}
     * }
     */
    private function parse(CommandResult $result): array
    {
        $stdout = $result->stdout;
        $summaryAt = strrpos($stdout, self::SummaryMarker."\n");
        if ($summaryAt === false) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }
        $summary = $this->summary(substr($stdout, $summaryAt + strlen(self::SummaryMarker) + 1));
        if ($result->truncated) {
            return [
                'files' => [],
                'diff' => '',
                'files_complete' => false,
                'diff_available' => false,
                'summary' => $summary,
            ];
        }
        $diffAt = strpos($stdout, self::DiffMarker."\n");
        if ($diffAt === false || $summaryAt < $diffAt) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }
        $files = [];
        foreach (preg_split("/\r\n|\n|\r/", substr($stdout, 0, $diffAt)) ?: [] as $line) {
            $file = $this->file($line);
            if ($file !== null) {
                $files[] = $file;
            }
        }
        $insertions = 0;
        $deletions = 0;
        foreach ($files as $file) {
            $insertions += $file['insertions'];
            $deletions += $file['deletions'];
        }
        if (count($files) !== $summary['files'] || $insertions !== $summary['insertions'] || $deletions !== $summary['deletions']) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }

        return [
            'files' => $files,
            'diff' => substr($stdout, $diffAt + strlen(self::DiffMarker) + 1, $summaryAt - ($diffAt + strlen(self::DiffMarker) + 1)),
            'files_complete' => true,
            'diff_available' => true,
            'summary' => $summary,
        ];
    }

    /** @return array{files: int, insertions: int, deletions: int} */
    private function summary(string $tail): array
    {
        $line = trim($tail);
        if (preg_match('/\A(\d+)\t(\d+)\t(\d+)\z/', $line, $counts) !== 1) {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }

        return ['files' => (int) $counts[1], 'insertions' => (int) $counts[2], 'deletions' => (int) $counts[3]];
    }

    /** @return array{path: string, insertions: int, deletions: int}|null */
    private function file(string $line): ?array
    {
        if ($line === '') {
            return null;
        }
        $parts = explode("\t", $line, 3);
        if (count($parts) !== 3 || $parts[2] === '') {
            return null;
        }
        $path = $parts[2];
        if (preg_match('/ => (.+)$/', $path, $renamed) === 1) {
            $path = trim($renamed[1], '"');
        }

        return [
            'path' => trim($path, '"'),
            'insertions' => $parts[0] === '-' ? 0 : max(0, (int) $parts[0]),
            'deletions' => $parts[1] === '-' ? 0 : max(0, (int) $parts[1]),
        ];
    }
}
