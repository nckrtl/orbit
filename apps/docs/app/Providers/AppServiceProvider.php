<?php

declare(strict_types=1);

namespace App\Providers;

use App\Documentation\DocumentationLintPolicy;
use App\Documentation\DocumentationRepository;
use App\Librarian\Rules\DecisionRecordLanguageRule;
use App\Librarian\Rules\DecisionRecordStructureRule;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(DocumentationLintPolicy::class, function (): DocumentationLintPolicy {
            $ignoredRules = config('orbit-docs.ignored_librarian_rules', []);
            if (! is_array($ignoredRules)) {
                throw new LogicException('Ignored Librarian rules must be configured as an array.');
            }

            $rules = [];
            foreach ($ignoredRules as $rule) {
                if (! is_string($rule)) {
                    throw new LogicException('Ignored Librarian rule names must be strings.');
                }

                $rules[] = $rule;
            }

            return new DocumentationLintPolicy($rules);
        });

        $this->app->singleton(DecisionRecordStructureRule::class, fn (): DecisionRecordStructureRule => new DecisionRecordStructureRule(
            $this->app->make(MarkdownSnapshot::class),
            $this->decisionRecordInteger('from_number'),
            $this->configuredComponents(),
        ));

        $this->app->singleton(DecisionRecordLanguageRule::class, function (): DecisionRecordLanguageRule {
            $phrases = config('orbit-docs.decision_records.blocked_phrases', []);
            if (! is_array($phrases)) {
                throw new LogicException('ADR blocked phrases must be configured as an array.');
            }

            $blocked = [];
            foreach ($phrases as $phrase) {
                if (! is_string($phrase)) {
                    throw new LogicException('ADR blocked phrases must be strings.');
                }

                $blocked[] = $phrase;
            }

            return new DecisionRecordLanguageRule(
                $this->app->make(MarkdownSnapshot::class),
                $this->decisionRecordInteger('from_number'),
                $blocked,
            );
        });

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
        $configuredComponents = config('orbit-docs.components', []);
        if (! is_array($configuredComponents)) {
            throw new LogicException('Orbit documentation components must be configured as an array.');
        }

        $components = [];
        foreach ($configuredComponents as $component) {
            if (! is_string($component) || $component === '') {
                throw new LogicException('Orbit documentation components must be non-empty strings.');
            }

            $components[] = $component;
        }

        return $components;
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
