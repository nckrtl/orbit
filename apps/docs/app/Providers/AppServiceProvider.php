<?php

declare(strict_types=1);

namespace App\Providers;

use App\Documentation\BlockedPhrases;
use App\Documentation\DocumentationLintPolicy;
use App\Documentation\DocumentationRepository;
use App\Librarian\Rules\DecisionRecordLanguageRule;
use App\Librarian\Rules\DecisionRecordStructureRule;
use App\Librarian\Rules\DocumentationNarrativeRule;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(DocumentationLintPolicy::class, fn (): DocumentationLintPolicy => new DocumentationLintPolicy(
            $this->stringList('orbit-docs.ignored_librarian_rules'),
            $this->stringList('orbit-docs.legacy_decision_rules'),
            $this->decisionRecordInteger('from_number'),
        ));

        $this->app->singleton(DocumentationNarrativeRule::class, fn (): DocumentationNarrativeRule => new DocumentationNarrativeRule(
            $this->app->make(MarkdownSnapshot::class),
            new BlockedPhrases($this->stringList('orbit-docs.narrative_phrases')),
        ));

        $this->app->singleton(DecisionRecordStructureRule::class, fn (): DecisionRecordStructureRule => new DecisionRecordStructureRule(
            $this->app->make(MarkdownSnapshot::class),
            $this->decisionRecordInteger('from_number'),
            $this->configuredComponents(),
        ));

        $this->app->singleton(BlockedPhrases::class, fn (): BlockedPhrases => new BlockedPhrases(
            $this->stringList('orbit-docs.decision_records.blocked_phrases'),
        ));

        $this->app->singleton(DecisionRecordLanguageRule::class, fn (): DecisionRecordLanguageRule => new DecisionRecordLanguageRule(
            $this->app->make(MarkdownSnapshot::class),
            $this->decisionRecordInteger('from_number'),
            $this->app->make(BlockedPhrases::class),
        ));

        $this->app->singleton(DocumentationRepository::class, function (): DocumentationRepository {
            $docsPath = config('librarian.path');
            $indexPath = config('orbit-docs.index_path');
            if (! is_string($docsPath) || ! is_string($indexPath)) {
                throw new LogicException('Orbit documentation paths must be configured.');
            }

            return new DocumentationRepository($docsPath, $indexPath, $this->configuredComponents());
        });
    }

    /** @return list<string> */
    private function configuredComponents(): array
    {
        return $this->stringList('orbit-docs.components');
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $values = config($key, []);
        if (! is_array($values)) {
            throw new LogicException("The {$key} value must be configured as an array.");
        }

        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new LogicException("The {$key} entries must be non-empty strings.");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private function decisionRecordInteger(string $key): int
    {
        $value = config("orbit-docs.decision_records.{$key}");
        if (! is_int($value) || $value < 0) {
            throw new LogicException("The orbit-docs.decision_records.{$key} value must be a non-negative integer.");
        }

        return $value;
    }
}
