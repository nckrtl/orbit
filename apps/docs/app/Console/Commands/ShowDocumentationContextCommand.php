<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documentation\DocumentationContextEntry;
use App\Documentation\DocumentationContextIndexBuilder;
use Illuminate\Console\Command;

final class ShowDocumentationContextCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:docs-context
        {--component=* : Repository component such as apps/gateway}
        {--concept=* : Canonical product concept such as Cluster}
        {--format=agent : Output format: agent or json}';

    #[\Override]
    protected $description = 'Return ordered documentation paths for implementation context.';

    public function handle(DocumentationContextIndexBuilder $builder): int
    {
        $components = $this->stringListOption('component');
        $concepts = $this->stringListOption('concept');
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['agent', 'json'], true)) {
            $this->error('The documentation context format must be `agent` or `json`.');

            return self::FAILURE;
        }

        $index = $builder->committed()->filtered($components, $concepts);
        if ($index->documents === []) {
            $this->error('No documentation matched the requested components or concepts.');

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->output->write($index->toJson());

            return self::SUCCESS;
        }

        foreach ($index->documents as $document) {
            $this->line($this->agentLine($document));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function stringListOption(string $name): array
    {
        $values = $this->option($name);
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

    private function agentLine(DocumentationContextEntry $document): string
    {
        $adrs = $document->governingAdrs === [] ? 'none' : implode(',', $document->governingAdrs);

        return "{$document->path} | {$document->kind} | {$document->title} | ADRs: {$adrs}";
    }
}
