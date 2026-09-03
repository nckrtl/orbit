<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\IssueDraft;
use Illuminate\Console\Command;

final class LintIssueDraftCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:issue-lint
        {file : Markdown file holding the drafted issue description}
        {--parent : The draft is a parent issue without an Acceptance section}';

    #[\Override]
    protected $description = 'Check a drafted Linear issue description against the creating-issues template.';

    public function handle(IssueDraft $draft): int
    {
        $file = $this->argument('file');

        if (! is_string($file) || ! is_file($file)) {
            $this->error('The issue draft file must exist.');

            return self::FAILURE;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            $this->error("Unable to read [{$file}].");

            return self::FAILURE;
        }

        $findings = $draft->findings($file, $contents, (bool) $this->option('parent'));

        if ($findings === []) {
            $this->info('Issue draft passed.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->line(
                "{$finding->path}:{$finding->line} [{$finding->severity->value}] {$finding->rule}: {$finding->message}",
            );
        }

        return self::FAILURE;
    }
}
