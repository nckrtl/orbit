<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\FeaturePlan;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

final class LintFeaturePlanCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:plan-lint
        {action : check (read only), record (write receipt), or verify (require receipt)}
        {issue : Uppercase issue identifier}
        {--worktree= : Issue worktree; defaults to the current directory}
        {--artifact= : For verify, require the exact saved artifact SHA to match local inputs}';

    #[\Override]
    protected $description = 'Lint a plan and verify its structural handoff receipt.';

    public function handle(FeaturePlan $validator): int
    {
        try {
            $action = (string) $this->argument('action');
            $issue = (string) $this->argument('issue');
            $artifact = $this->option('artifact');
            if (! in_array($action, ['check', 'record', 'verify'], true)
                || preg_match('/^[A-Z]+-[0-9]+$/', $issue) !== 1
                || ($artifact !== null && ($action !== 'verify' || preg_match('/^[0-9a-f]{40}$/', (string) $artifact) !== 1))) {
                throw new RuntimeException('Use check|record|verify ISSUE [--worktree=PATH]; only verify accepts --artifact=FULL_SHA.');
            }
            $root = trim($this->processOutput(['git', '-C', (string) ($this->option('worktree') ?? getcwd()), 'rev-parse', '--show-toplevel']));
            $source = dirname(base_path(), 2);
            $directory = $root.'/.loop';
            if (is_link($directory) || ! is_dir($directory)) {
                throw new RuntimeException('A regular .loop directory is required.');
            }
            $receiptPath = $directory.'/plan-lint.json';
            if (is_link($receiptPath) || (file_exists($receiptPath) && ! is_file($receiptPath))) {
                throw new RuntimeException('The receipt must be a regular file.');
            }
            if ($action === 'record' && is_file($receiptPath) && ! unlink($receiptPath)) {
                throw new RuntimeException('Cannot remove the old receipt.');
            }
            $plan = $this->read($directory.'/plan.md');
            $flow = trim($this->processOutput([$source.'/bin/loop-flow', 'status', '--worktree='.$root]));
            $template = $this->read($source.'/.agents/skills/planning-features/template.md');
            $errors = $validator->findings($plan, $template, $issue, $flow);
            if ($errors !== []) {
                throw new RuntimeException(implode("\n", $errors));
            }
            $receipt = [
                'schema' => 1,
                'issue' => $issue,
                'flow' => $flow,
                'plan_sha256' => hash('sha256', $plan),
                'validator_sha256' => hash('sha256', $template.$this->read(__FILE__)
                    .$this->read($source.'/apps/docs/app/Documentation/FeaturePlan.php')
                    .$this->read($source.'/apps/docs/app/Documentation/MarkdownProse.php')
                    .$this->read($source.'/bin/loop-flow')),
            ];
            if ($action === 'record') {
                $this->record($directory, $receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            } elseif ($action === 'verify') {
                $saved = json_decode($this->read($receiptPath), true, flags: JSON_THROW_ON_ERROR);
                if ($saved !== $receipt) {
                    throw new RuntimeException('Stale or invalid receipt; run plan-lint record after correcting the plan.');
                }
                if (is_string($artifact)) {
                    foreach (['plan.md', 'plan-lint.json', 'flow.json'] as $file) {
                        $path = '.loop/'.$file;
                        $entry = trim($this->processOutput(['git', '-C', $root, 'ls-tree', $artifact, '--', $path]));
                        if ($file === 'flow.json' && $entry === '' && ! file_exists($directory.'/'.$file)) {
                            continue;
                        }
                        if (! str_starts_with($entry, '100644 blob ')
                            || $this->processOutput(['git', '-C', $root, 'show', $artifact.':'.$path]) !== $this->read($directory.'/'.$file)) {
                            throw new RuntimeException("Saved artifact differs from local {$path}; record and save the current plan before handoff.");
                        }
                    }
                }
            }
            $this->line(json_encode(['result' => 'passed', 'action' => $action, ...$receipt, 'artifact' => $artifact], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RuntimeException|\JsonException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function read(string $path): string
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Missing or unsafe file: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $contents;
    }

    /** @param list<string> $command */
    private function processOutput(array $command): string
    {
        $process = new Process($command);
        $process->mustRun();

        return $process->getOutput();
    }

    private function record(string $directory, string $path, string $contents): void
    {
        $temporary = tempnam($directory, '.plan-lint-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create the receipt.');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents) || ! rename($temporary, $path)) {
                throw new RuntimeException('Cannot write the receipt.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
