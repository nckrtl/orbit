<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\DocumentationLintPolicy;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\Linter;
use HardImpact\Librarian\Linting\LintResult;
use Illuminate\Console\Command;
use JsonException;

final class LintDocumentationCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:docs-lint
        {--format=agent : Output format: agent, json, or text}
        {--strict : Treat warnings as failures}';

    #[\Override]
    protected $description = 'Lint Orbit documentation with its reader-focused policy.';

    /** @throws JsonException */
    public function handle(Linter $linter, DocumentationLintPolicy $policy): int
    {
        $format = $this->option('format');
        if (! is_string($format) || ! in_array($format, ['agent', 'json', 'text'], true)) {
            $this->error('The documentation lint format must be `agent`, `json`, or `text`.');

            return self::FAILURE;
        }

        $strict = (bool) $this->option('strict');
        $result = $policy->apply($linter->lint());

        if ($format === 'text') {
            $this->renderText($result);
        } else {
            $this->line($this->encode($result, $strict));
        }

        return $result->passed($strict) ? self::SUCCESS : self::FAILURE;
    }

    private function renderText(LintResult $result): void
    {
        if ($result->findings === []) {
            $this->info('Documentation lint passed.');

            return;
        }

        foreach ($result->findings as $finding) {
            $location = $finding->line === null ? $finding->path : "{$finding->path}:{$finding->line}";
            $this->line("{$location} [{$finding->severity->value}] {$finding->rule}: {$finding->message}");
        }
    }

    /** @throws JsonException */
    private function encode(LintResult $result, bool $strict): string
    {
        $payload = [
            'tool' => 'librarian',
            'result' => $result->passed($strict) ? 'passed' : 'failed',
            'issues' => count($result->findings),
            'errors' => count($result->errors()),
            'warnings' => count($result->warnings()),
        ];

        if ($result->findings !== []) {
            $payload['findings'] = array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $result->findings,
            );
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
