<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Install\GuidelineAssist;
use Laravel\Boost\Install\GuidelineComposer;
use RuntimeException;

final class LaravelZeroGuidelineComposer extends GuidelineComposer
{
    public function compose(): string
    {
        $guidelines = parent::compose();
        $optionalLocation = 'in `.ai/rules` when that directory exists';
        $permissiveFallback = 'If `.ai/rules` does not exist, continue without it.';
        $boostSectionPattern = '/=== boost rules ===\R\R.*?(?=\R\R=== [^\r\n]+ rules ===|\z)/s';

        if (
            substr_count($guidelines, $optionalLocation) !== 1
            || substr_count($guidelines, $permissiveFallback) !== 1
            || preg_match_all($boostSectionPattern, $guidelines) !== 1
        ) {
            throw new RuntimeException(
                'Boost project-rule guidance changed. Update the Orbit CLI hard-stop transformation before regenerating.',
            );
        }

        $safeBoostGuidance = <<<'MARKDOWN'
            === boost rules ===

            # Laravel Boost

            ## Project Rules

            - `.ai/rules/index.md` and every indexed rule file are required repository state. Open the index, read every rule whose globs match the files in scope, and search `.ai/rules` for relevant terms before planning or editing.
            - If `.ai/rules` does not exist, the checkout or Boost bootstrap is incomplete. Restore or regenerate the guidance before planning or editing any file. Do not silently continue without the project rule set.
            - Store durable project decisions in a concise repository-owned rule file and keep its frontmatter globs in the index. Run `composer guidance:check` after each rule change.

            ## Boost Commands

            - Run `composer guidance:generate` to refresh Boost-managed guidance, skills, and scoped rules without interactive package discovery.
            - Run `composer guidance:check` to verify the committed guidance and installed skill output.
            - The Boost MCP server is configured in `.codex/config.toml`. Use a Boost MCP tool only when the active client exposes that tool. Use repository commands and files when it does not.
            MARKDOWN;
        $composed = preg_replace($boostSectionPattern, $safeBoostGuidance, $guidelines);

        if (! is_string($composed)) {
            throw new RuntimeException('Unable to compose the Orbit CLI Boost guidance.');
        }

        return $composed;
    }

    protected function getGuidelineAssist(): GuidelineAssist
    {
        return new LaravelZeroGuidelineAssist($this->project, $this->config);
    }
}
